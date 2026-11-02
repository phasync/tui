<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Text;

/**
 * Large block-glyph text: a clock, a score, a counter, a banner.
 *
 *     new BigText('12:45')
 *     new BigText('Game over', compact: true)
 *
 * Digits, the capitals of the Latin, Greek and Cyrillic alphabets (lower case is shown as upper
 * case; a letter with a diacritic as its base letter: ü as U, Å as A, Ё as Е, when ext-intl is
 * there to decompose it), Æ Ø Œ Ð Þ ß Ł, and ` : . , - + ! ? / % _` are drawn from a 3x5 font in
 * `█`, a column apart: five rows high. With `compact: true` two font rows share a text row
 * (`▀ ▄ █`), so it is three rows high. A character the font does not have is shown as `?`; `glyphs:`
 * adds or replaces glyphs (`['☃' => '010111010111101']`: five rows of three cells, `1` filled).
 * One line; wider than the room, it is cut. `$text`, `$compact` and `$glyphs` are props. Digits
 * for a clock or counter are `new BigText((string) $n)`. Not interactive.
 *
 * **Style**: `BigText` (its colour is the colour of the glyphs; the default theme: the accent).
 */
class BigText extends Component
{
    /** @var array<string, string> the glyphs: 5 rows of 3 cells, row by row, `1` is filled */
    private const FONT = [
        '0' => '111101101101111', '1' => '010110010010111', '2' => '111001111100111', '3' => '111001111001111',
        '4' => '101101111001001', '5' => '111100111001111', '6' => '111100111101111', '7' => '111001001001001',
        '8' => '111101111101111', '9' => '111101111001111',
        'A' => '010101111101101', 'B' => '110101110101110', 'C' => '011100100100011', 'D' => '110101101101110',
        'E' => '111100110100111', 'F' => '111100110100100', 'G' => '011100101101011', 'H' => '101101111101101',
        'I' => '111010010010111', 'J' => '001001001101010', 'K' => '101101110101101', 'L' => '100100100100111',
        'M' => '101111111101101', 'N' => '110101101101101', 'O' => '010101101101010', 'P' => '110101110100100',
        'Q' => '010101101110011', 'R' => '110101110101101', 'S' => '011100010001110', 'T' => '111010010010010',
        'U' => '101101101101111', 'V' => '101101101101010', 'W' => '101101111111101', 'X' => '101101010101101',
        'Y' => '101101010010010', 'Z' => '111001010100111',
        ' ' => '000000000000000', ':' => '000010000010000', '.' => '000000000000010', ',' => '000000000010100',
        '-' => '000000111000000', '+' => '000010111010000', '!' => '010010010000010', '?' => '110001010000010',
        '/' => '001001010100100', '%' => '101001010100101', '_' => '000000000000111',
        // Latin letters that do not decompose to A-Z
        'Æ' => '011110111110111', 'Ø' => '001111101111100', 'Œ' => '011110111110011', 'Ð' => '110101111101110',
        'Þ' => '100110101110100', 'ß' => '010101110101110', 'Ł' => '100100110100111',
        // Greek: the capitals that are not the shape of a Latin one (Α Β Ε Ζ Η Ι Κ Μ Ν Ο Ρ Τ Υ Χ are)
        'Γ' => '111100100100100', 'Δ' => '010010101101111', 'Θ' => '111101111101111', 'Λ' => '010010101101101',
        'Ξ' => '111000111000111', 'Π' => '111101101101101', 'Σ' => '111100010100111', 'Φ' => '010111101111010',
        'Ψ' => '101101111010010', 'Ω' => '010101101010111',
        // Cyrillic: the capitals that are not the shape of a Latin one (А В Е К М Н О Р С Т Х are)
        'Б' => '111100110101110', 'Г' => '111100100100100', 'Д' => '011101101111101', 'Ж' => '101111010111101',
        'З' => '111001011001111', 'И' => '101101011111101', 'Л' => '011101101101101', 'П' => '111101101101101',
        'У' => '101101010010100', 'Ф' => '010111101111010', 'Ц' => '101101101111001', 'Ч' => '101101111001001',
        'Ш' => '101101101101111', 'Щ' => '101101101111001', 'Ъ' => '110010011011011', 'Ы' => '101101111111101',
        'Ь' => '100100110101110', 'Э' => '110001011001110', 'Ю' => '101111101111101', 'Я' => '011101011101101',
    ];
    /** Greek and Cyrillic capitals drawn with the glyph of the Latin letter of the same shape. */
    private const LOOKALIKES = [
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N',
        'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X',
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C',
        'Т' => 'T', 'Х' => 'X',
    ];

    /**
     * @param string $text what to show (one line)
     * @param bool $compact half blocks: three rows high instead of five
     * @param array<string, string> $glyphs glyphs added to the font (or replacing its): character => five rows of three cells, `1` filled
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $text = '',
        public bool $compact = false,
        public array $glyphs = [],
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
    }

    /**
     * The glyph rows of $text: one string per row, `█` and spaces, a blank column between glyphs.
     *
     * @param array<string, string> $glyphs added to the font (see `glyphs:`)
     * @return list<string>
     */
    public static function rows(string $text, bool $compact = false, array $glyphs = []): array
    {
        $rows = ['', '', '', '', ''];
        $text = mb_strtoupper(str_replace(["\r", "\n"], ' ', $text));
        if (class_exists(\Normalizer::class)) {
            // a letter with a diacritic is drawn as its base letter (the mark decomposed away)
            $text = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }
        foreach (mb_str_split($text) as $i => $ch) {
            $glyph = $glyphs[$ch] ?? self::FONT[$ch] ?? self::FONT[self::LOOKALIKES[$ch] ?? '?'];
            for ($r = 0; $r < 5; $r++) {
                $rows[$r] .= ($i ? ' ' : '') . strtr(substr($glyph, $r * 3, 3), ['1' => '█', '0' => ' ']);
            }
        }
        if (!$compact) {
            return $rows;
        }
        $out = [];
        foreach ([[0, 1], [2, 3], [4, null]] as [$top, $bottom]) {
            $line = '';
            for ($x = 0, $n = mb_strlen($rows[0]); $x < $n; $x++) {
                $t = mb_substr($rows[$top], $x, 1) !== ' ';
                $b = $bottom !== null && mb_substr($rows[$bottom], $x, 1) !== ' ';
                $line .= $t ? ($b ? '█' : '▀') : ($b ? '▄' : ' ');
            }
            $out[] = $line;
        }
        return $out;
    }

    public function measure(int $cols, int $rows): ?array
    {
        $glyphs = self::rows($this->text, $this->compact, $this->glyphs);
        return [min($cols, Text::width($glyphs[0])), min($rows, \count($glyphs))];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $rows = array_map(static fn(string $r) => Text::truncate(rtrim($r), $cols, ''), self::rows($this->text, $this->compact, $this->glyphs));
        $this->surface->write("\e[?25l" . Ansi::style($this->style()->text) . "\e[2J\e[H" . implode("\r\n", $rows));
        return null;
    }
}
