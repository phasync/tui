<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A grid of cells coloured by value: activity by weekday and hour, a correlation matrix, a
 * board of measurements.
 *
 *     new Heatmap([[1, 4, 9], [2, 6, 3]], rowLabels: ['mon', 'tue'], colLabels: ['a', 'b', 'c'],
 *         values: true, onSelect: fn(int $row, int $col, float $value) => ...)
 *
 * `$data` is a list of rows of numbers (null leaves a cell empty). The lowest value (`$min`, or the
 * smallest in the data) gets the first colour of `$colors`, the highest (`$max`) the last, and the
 * values between a blend: `$colors` are CSS hex colours (`#rrggbb`), the default runs from deep
 * blue through cyan and yellow to red. Each cell is `$cellWidth` columns wide and one row high;
 * with `$values` the number is written in it (rounded to fit, in black or white by the
 * background), and `$colLabels` are cut to the cell width. A click on a cell calls
 * `onSelect($row, $col, $value, $heatmap)` and emits `select`. Colours are quantised to 64
 * steps. Rows and columns that do not fit are left out. All arguments are props.
 *
 * **Style**: `Heatmap` (labels, empty cells), part `Heatmap::label`.
 */
class Heatmap extends Component
{
    public const COLORS = ['#1e3a8a', '#0ea5e9', '#fde047', '#ef4444'];

    /**
     * @param list<list<int|float|null>> $data
     * @param list<string> $rowLabels
     * @param list<string> $colLabels
     * @param list<string> $colors  CSS hex colours from the lowest value to the highest (two or more)
     * @param (\Closure(int $row, int $col, float $value, Heatmap $heatmap): void)|null $onSelect
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $data = [],
        public array $rowLabels = [],
        public array $colLabels = [],
        public ?float $min = null,
        public ?float $max = null,
        public array $colors = self::COLORS,
        public int $cellWidth = 2,
        public bool $values = false,
        private readonly ?\Closure $onSelect = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        if (\count($colors) < 2 || $cellWidth < 1) {
            throw new \InvalidArgumentException('A Heatmap needs two or more colours and a cell width of at least 1');
        }
        $this->with($key, $id, $class, $style);
    }

    /** @return array{float, float} */
    private function range(): array
    {
        $flat = array_filter(array_merge([], ...array_map('array_values', $this->data)), static fn($v) => $v !== null);
        $min = $this->min ?? ($flat === [] ? 0.0 : (float) min($flat));
        $max = $this->max ?? ($flat === [] ? 1.0 : (float) max($flat));
        return [$min, max($max, $min)];
    }

    /** The colour of $value as [r, g, b], blended along `$colors`. */
    private function colorOf(float $value, float $min, float $max): array
    {
        $t = $max > $min ? min(1.0, max(0.0, ($value - $min) / ($max - $min))) : 0.0;
        $t = round($t * 63) / 63;
        $stops = array_map(static fn(string $c) => sscanf(ltrim($c, '#'), '%2x%2x%2x'), $this->colors);
        $at = $t * (\count($stops) - 1);
        $i = min((int) floor($at), \count($stops) - 2);
        $f = $at - $i;
        return array_map(static fn(int $a, int $b) => (int) round($a + ($b - $a) * $f), $stops[$i], $stops[$i + 1]);
    }

    private function labelWidth(): int
    {
        return $this->rowLabels === [] ? 0 : max(array_map(static fn($l) => Text::width(Markup::plain((string) $l)), $this->rowLabels)) + 1;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->action !== Mouse::PRESS || $mouse->button !== Mouse::LEFT || $this->onSelect === null) {
            return false;
        }
        $row = $mouse->y - ($this->colLabels === [] ? 0 : 1);
        $col = intdiv($mouse->x - $this->labelWidth(), $this->cellWidth);
        $value = $mouse->x >= $this->labelWidth() ? ($this->data[$row][$col] ?? null) : null;
        if ($row < 0 || $value === null) {
            return false;
        }
        $this->call($this->onSelect, $row, $col, (float) $value, $this);
        $this->emit('select', $this);
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        $columns = max([0, ...array_map('count', $this->data)]);
        return [min($cols, $this->labelWidth() + $columns * $this->cellWidth), min($rows, \count($this->data) + ($this->colLabels === [] ? 0 : 1))];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $labelW = $this->labelWidth();
        $fitCols = max(0, intdiv($cols - $labelW, $this->cellWidth));
        [$min, $max] = $this->range();
        $base = Ansi::style($this->style()->text);
        $label = Ansi::style($this->style('label')->text);
        $lines = [];
        if ($this->colLabels !== []) {
            $line = $label . str_repeat(' ', $labelW);
            foreach (\array_slice($this->colLabels, 0, $fitCols) as $l) {
                $line .= Text::pad(Text::truncate(Markup::plain((string) $l), $this->cellWidth, ''), $this->cellWidth, 'center');
            }
            $lines[] = $line;
        }
        foreach ($this->data as $r => $row) {
            $line = $label . Text::pad(Markup::plain((string) ($this->rowLabels[$r] ?? '')), $labelW);
            foreach (\array_slice(array_values($row), 0, $fitCols) as $v) {
                if ($v === null) {
                    $line .= $base . str_repeat(' ', $this->cellWidth);
                    continue;
                }
                [$red, $green, $blue] = $this->colorOf((float) $v, $min, $max);
                $text = $this->values ? Text::pad(substr(self::number((float) $v, $this->cellWidth), 0, $this->cellWidth), $this->cellWidth, 'center') : str_repeat(' ', $this->cellWidth);
                $dark = 0.299 * $red + 0.587 * $green + 0.114 * $blue < 140;
                $line .= sprintf("\e[0;%s;48;2;%d;%d;%dm", $dark ? '97' : '30', $red, $green, $blue) . $text;
            }
            $lines[] = $line . $base;
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . implode("\r\n", \array_slice($lines, 0, $this->surface->rows())));
        return null;
    }

    private static function number(float $v, int $width): string
    {
        $s = (string) (round($v) == $v ? (int) $v : round($v, 1));
        return \strlen($s) > $width ? (string) round($v) : $s;
    }
}
