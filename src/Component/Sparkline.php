<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Text;

/**
 * A tiny line chart in one row: each value a block from `▁` to `█`, the newest at the right.
 *
 *     new Sparkline($cpuHistory)                      // scaled to the largest value
 *     new Sparkline($latencies, max: 100)             // a fixed scale
 *
 * `$values` (numbers; null leaves a gap) and `$max` are props. Only the last as many values as
 * the width allows are shown; with fewer, the chart is aligned to the right. Takes the width it
 * is given (or the number of values, in `auto` sizing); one row.
 *
 */
class Sparkline extends Component
{
    public const BARS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param list<int|float|null> $values
     * @param float|null           $max    the value of a full block; null: the largest value shown
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $values = [],
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
        return [min($cols, max(1, \count($this->values))), 1];
    }

    /** The chart for the last `$width` values: a string of blocks, spaces where the values are missing. */
    public static function bars(array $values, int $width, ?float $max = null): string
    {
        $shown = \array_slice(array_values($values), -$width);
        $top = $max ?? max(array_map(static fn($v) => $v ?? 0, $shown) ?: [0]);
        $out = '';
        foreach ($shown as $v) {
            if ($v === null) {
                $out .= ' ';
            } elseif ($top <= 0 || $v <= 0) {
                $out .= self::BARS[0];
            } else {
                $out .= self::BARS[(int) min(7, max(0, round($v / $top * 7)))];
            }
        }
        return $out;
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $line = self::bars($this->values, $cols, $this->max);
        $pad = str_repeat(' ', max(0, $cols - Text::width($line)));
        $this->surface->write("\e[?25l\e[2J\e[H" . Ansi::style($this->style()->text) . $pad . $line);
        return null;
    }
}
