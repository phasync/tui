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
 * Digits, the letters A-Z (lower case is shown as upper case) and ` : . , - + ! ? / % _` are
 * drawn from a 3x5 font in `█`, a column apart: five rows high. With `compact: true` two font
 * rows share a text row (`▀ ▄ █`), so it is three rows high. A character the font does not have
 * is shown as `?`. One line; wider than the room, it is cut. `$text` and `$compact` are props.
 * Digits for a clock or counter are `new BigText((string) $n)`. Not interactive.
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
    ];

    /**
     * @param string $text what to show (one line)
     * @param bool $compact half blocks: three rows high instead of five
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $text = '',
        public bool $compact = false,
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
     * @return list<string>
     */
    public static function rows(string $text, bool $compact = false): array
    {
        $rows = ['', '', '', '', ''];
        $chars = mb_str_split(mb_strtoupper(str_replace(["\r", "\n"], ' ', $text)));
        foreach ($chars as $i => $ch) {
            $glyph = self::FONT[$ch] ?? self::FONT['?'];
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
        $glyphs = self::rows($this->text, $this->compact);
        return [min($cols, Text::width($glyphs[0])), min($rows, \count($glyphs))];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $rows = array_map(static fn(string $r) => Text::truncate(rtrim($r), $cols, ''), self::rows($this->text, $this->compact));
        $this->surface->write("\e[?25l" . Ansi::style($this->style()->text) . "\e[2J\e[H" . implode("\r\n", $rows));
        return null;
    }
}
