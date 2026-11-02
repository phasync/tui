<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * Values as bars, with labels: horizontal (one row per bar, the default) or vertical columns.
 *
 *     new BarChart(['mon' => 3, 'tue' => 7, 'wed' => 5])                  // mon ███░░░ 3
 *     new BarChart($sales, vertical: true, max: 100)
 *
 * `$bars` maps label => value (markup in labels); `$max` is the value of a full bar (default:
 * the largest). Horizontal: labels aligned in a column, the bar fills the room left of the
 * value; with fewer rows than bars the first bars are shown. Vertical: each column is as wide
 * as its label (at least 3, one space between), values above the bars when there is room, and
 * eighth-block precision; columns that do not fit are cut.
 *
 * **Style**: `BarChart` (labels and values), the part `BarChart::bar` (the default theme: the
 * accent colour).
 */
class BarChart extends Component
{
    private const EIGHTHS = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param array<string, int|float> $bars
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $bars = [],
        public bool $vertical = false,
        public ?float $max = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->vertical ? [$cols, $rows] : [$cols, min($rows, max(1, \count($this->bars)))];
    }

    private static function num(int|float $v): string
    {
        return (string) (round($v) == $v ? (int) $v : round($v, 2));
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        $top = $this->max ?? max([0, ...array_values($this->bars)]);
        $base = Ansi::style($this->style()->text);
        $bar = Ansi::style($this->style('bar')->text);
        $lines = [];
        if ($this->bars === []) {
            $this->surface->write("\e[?25l\e[2J\e[H");
            return null;
        }
        if (!$this->vertical) {
            $labelW = max(array_map(static fn($l) => Text::width(Markup::plain((string) $l)), array_keys($this->bars)));
            $valueW = max(array_map(static fn($v) => \strlen(self::num($v)), $this->bars));
            $room = max(0, $cols - $labelW - $valueW - 2);
            foreach (\array_slice($this->bars, 0, $rows, true) as $label => $value) {
                $eighths = $top > 0 ? (int) round(min(1, max(0, $value / $top)) * $room * 8) : 0;
                $full = intdiv($eighths, 8);
                $part = $eighths % 8;
                $fill = str_repeat('█', $full) . ($part ? self::EIGHTHS[$part] : '');
                $lines[] = $base . Text::pad($this->markup((string) $label), $labelW) . ' ' . $bar . $fill
                    . $base . str_repeat(' ', $room - Text::width($fill)) . ' ' . self::num($value);
            }
        } else {
            $height = max(1, $rows - 2);               // bar rows; the label row and the value row
            $grid = [];
            $used = 0;
            $columns = [];
            foreach ($this->bars as $label => $value) {
                $w = max(3, Text::width(Markup::plain((string) $label)));
                if ($used + $w > $cols) {
                    break;
                }
                $columns[] = [(string) $label, $value, $w];
                $used += $w + 1;
            }
            for ($r = $height - 1; $r >= 0; $r--) {
                $line = $base;
                foreach ($columns as [$label, $value, $w]) {
                    $eighths = $top > 0 ? (int) round(min(1, max(0, $value / $top)) * $height * 8) : 0;
                    $cell = min(8, max(0, $eighths - $r * 8));
                    $line .= $bar . str_repeat(self::EIGHTHS[$cell === 0 ? 0 : $cell] === ' ' ? ' ' : self::EIGHTHS[$cell], $w) . $base . ' ';
                }
                $lines[] = $line;
            }
            $values = $base;
            $labels = $base;
            foreach ($columns as [$label, $value, $w]) {
                $values .= Text::pad(self::num($value), $w, 'center') . ' ';
                $labels .= Text::pad($this->markup($label), $w, 'center') . ' ';
            }
            array_unshift($lines, $values);
            $lines[] = $labels;
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . implode("\r\n", $lines));
        return null;
    }
}
