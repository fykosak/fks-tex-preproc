<?php

namespace Fykosak\FKSTexPreprocess;

/**
 * Macro -- control sequence in text neglecting arguments
 * Variant -- control sequence with particular no. of arguments
 * @author Michal Koutný <michal@fykos.cz>
 */
class TexPreprocess {

    public const SAFETY_LIMIT = 10000;

    private static array $macros = [
// equations
        '\eq m' => "\n\\[\\begin{align*}\n    \\1\n\\end {align*}\\]\n",// NOTE: space as it breaks Dokuwiki parser
        '\eq s' => "\n\\[\\begin{equation*}\n    \\1\n\\end {equation*}\\]\n",
        '\eq' => "\n\\[\\begin{equation*}\n    \\1\n\\end {equation*}\\]\n",
        '\eqref:1' => '\eqref{\1}',
// lists
        '\begin compactenum ' => 'f:startOList',
        '\begin compactenum' => 'f:startOList',
        '\end compactenum' => 'f:endOList',
        '\begin compacitem ' => 'f:startUList',
        '\begin compactitem' => 'f:startUList',
        '\end compactitem' => 'f:endUList',
        '\item' => 'f:listItem',
// text style & typography
        '\emph' => '//\1//',
        '\footnote' => '((\1))',
        '\par' => 'f:paragraph',
        '\textit' => '//\1//',
        '\url:1' => '[[\1]]',
        '\uv:1' => '„\1“',
        '\,' => ' ',// Unicode
        '\\' => '\\\\',
// figures
        '\illfigi:5 i' => '',
        '\illfigi:5 o' => '',
        '\illfigi:5' => '',
        '\illfig:4' => '',
        '\fullfig:3' => '',
// /dev/null
        '\hfill' => '',
        '\mbox:1' => '\1',
        '\noindent' => '',
        '\quad' => ' ',
        '\ref:1' => '',// TODO figures?
        '\smallskip' => '',
        '\vspace:1' => '',
        '\vspace*:1' => '',
        '\taskhint:2' => '**\1:** \2',
    ];
    private array $variantArity = [];
    private array $maxMaskArity = [];
    private array $macroMasks = [];
    private array $macroVariants;

    public function __construct() {
        foreach (self::$macros as $pattern => $replacement) {
            $variant = $pattern;
            $parts = explode(' ', $pattern);
            $macro = $parts[0];
            $macroParts = explode(':', $macro);
// replacement arity
            if (count($macroParts) > 1) {
                $this->variantArity[$variant] = $macroParts[1];
                $macro = $macroParts[0];
            } elseif (substr($replacement, 0, 2) == 'f:') {
                $this->variantArity[$variant] = 0;
            } else {
                preg_match_all('/\\\([0-9])/', $replacement, $matches);
                $this->variantArity[$variant] = count($matches[1]) ? max($matches[1]) : 0;
            }

// mask arity
            $maskArity = count($parts) - 1;

            if (!isset($this->maxMaskArity[$macro])) {
                $this->maxMaskArity[$macro] = 0;
            }
            $this->maxMaskArity[$macro] = ($maskArity > $this->maxMaskArity[$macro]) ? $maskArity : $this->maxMaskArity[$macro];

// macro masks
            if (!isset($this->macroMasks[$macro])) {
                $this->macroMasks[$macro] = [];
            }
            $this->macroMasks[$macro][$variant] = array_slice($parts, 1);
        }

        $this->macroVariants = self::$macros;
    }

    public function preproc(string $text): string {
        $text = str_replace(['[m]', '[i]', '[o]'], ['{m}', '{i}', '{o}'], $text); // simple solution
// units macro
        $text = preg_replace_callback('#"(([+-]?[0-9\\\, ]+(\.[0-9\\\, ]+)?)(e([+-]?[0-9 ]+))?)((\s*)([^"]+))?"#', function ($matches) {
            $mantissa = $matches[2];
            $exp = $matches[5];
            $unit = $matches[8];
            $space = $matches[7];
            if ($exp) {
                $num = "$mantissa \cdot 10^{{$exp}}";
            } else {
                $num = $mantissa;
            }
            $num = str_replace(['.', ' '], ['{,}', '\;'], $num);
            if ($unit && $space != '') {
                $unit = '\,\mathrm{' . str_replace('.', '\cdot ', $unit) . '}';
            }
            return "$num$unit";
        }, $text);

        $ast = $this->parse($text);

        return $this->process($ast);
    }

    private function chooseVariant(string $sequence, array $toMatch): array {
        foreach ($this->macroMasks[$sequence] as $variant => $mask) { //assert: must be sorted in decreasing mask length
            $matching = true;
            $matchLength = 0;
            for ($i = 0; $i < count($mask); ++$i) {
//if (preg_match('/' . $mask[$i] . '/', $toMatch[$i])) {
                if ($mask[$i] == $toMatch[$i] || ($mask[$i] == '' && preg_match('/\s/', $toMatch[$i]))) { // empty mask string means whitespace
                    $matchLength = $i + 1;
                } else {
                    $matching = false;
                    break;
                }
            }
            if ($matching) {
                return [$variant, $matchLength];
            }
        }
        return [null, 0];
    }

    private function process(array $ast): string {
        $safetyCounter = 0;
        $result = '';
        reset($ast);
        while (($it = current($ast)) !== false) {
            if (++$safetyCounter > self::SAFETY_LIMIT) {
                throw new \Error('Infinite loop in parser.', -1);
            }

            if (is_array($it)) { // group
                $result .= '{' . $this->process($it) . '}';
            } else {
                $sequence = strtolower(trim($it));
                if (isset($this->maxMaskArity[$sequence])) {
                    $toMatch = [];
                    for ($i = 0; $i < $this->maxMaskArity[$sequence]; ++$i) {
                        $toMatch[] = $this->nodeToText(next($ast));
                    }
                    [$variant, $matchLength] = $this->chooseVariant($sequence, $toMatch);

                    $rest = $this->maxMaskArity[$sequence] - $matchLength;
                    for ($i = 0; $i < $rest; ++$i) {
                        prev($ast);
                    }

                    $arguments = [];
                    for ($i = 0; $i < $this->variantArity[$variant]; ++$i) {
                        $arguments[] = $this->process(next($ast));
                    }

                    if (substr($this->macroVariants[$variant], 0, 2) == 'f:') {
                        $result .= call_user_func([$this, substr($this->macroVariants[$variant], 2)]);
                    } else {
                        $result .= preg_replace_callback('/\\\([0-9])/', function ($match) use ($arguments) {
                            return $arguments[$match[1] - 1];
                        }, $this->macroVariants[$variant]);
                    }
                } else {
                    $result .= $it;
                }
            }
            next($ast);
        }

        return $result;
    }

    private function nodeToText($node): string {
        if (is_array($node)) {
            $result = '';
            foreach ($node as $it) {
                $result .= $this->nodeToText($it);
            }
            return $result;
        } else {
            return (string)$node;
        }
    }

    private function processText(string $text): string {
        return $text;
    }

    private function parse(string $text): array {
        $stack = [[]];
        $current = &$stack[0];
        $lexer = new TexLexer($text);

        foreach ($lexer as $token) {
            switch ($token['type']) {
                case TexLexer::TOKEN_LBRACE:
                    array_push($stack, []);
                    $current = &$stack[count($stack) - 1];
                    break;
                case TexLexer::TOKEN_RBRACE:
                    $content = array_pop($stack);
                    $current = &$stack[count($stack) - 1];
                    $current[] = $content;
                    break;
                case TexLexer::TOKEN_SEQ:
                    $sequence = preg_replace('/\s+\*/', '*', $token['text']);
                    $current[] = $sequence;
                    break;
                default:
                    $current[] = $token['text'];
                    break;
            }
        }
        return $current;
    }

    /*     * **************
    * Replacement callbacks
    */

    private array $listStack = [];

    private function startOList(): string {
        array_push($this->listStack, 'O');
        return "\n";
    }

    private function endOList(): string {
        array_pop($this->listStack);
        return "\n";
    }

    private function startUList(): string {
        array_push($this->listStack, 'U');
        return "\n";
    }

    private function endUList(): string {
        array_pop($this->listStack);
        return "\n";
    }

    private function listItem(): string {
        $char = end($this->listStack) == 'U' ? '*' : '-';
        $level = count($this->listStack);
        return "\n" . str_repeat('  ', $level) . $char . ' ';
    }

    private function paragraph(): string {
        if (count($this->listStack)) {
            return '\\\\ ';
        } else {
            return "\n\n";
        }
    }
}
