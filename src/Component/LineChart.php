<?php

namespace phasync\Tui\Component;

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Markup;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * A chart of one or more series on axes, drawn on a `Canvas`: a load graph, a temperature over
 * time, a comparison of runs.
 *
 *     new LineChart(['cpu' => [12, 30, 22, 80, 45], 'mem' => [40, 41, 43, 50, 52]], yMin: 0, yMax: 100)
 *     new LineChart(['run 1' => [[0, 1.5], [2, 3], [5, 2.5]]], points: true)      // [x, y] pairs
 *
 * Each series is a list of y values (x is the position, 0, 1, 2, ...) or a list of `[x, y]`
 * pairs. The axes fit the data, or `xMin`/`xMax`/`yMin`/`yMax` fix them; the y axis has labels at
 * the bottom, the top and (when high enough) the middle, the x axis its two ends. `lines` joins
 * the points of a series, `points` marks each one; a `legend` in the top right names the series
 * in their colours (`colors`, a list of CSS colours used in turn). Axes and labels are
 * characters, the data is the canvas's dots (`marker:`, as for `Canvas`; needs `ext-gd`). It
 * takes the room it is given. A series with `null` values leaves gaps. All arguments are props.
 *
 * **Style**: `LineChart` (its colour is the text colour of the legend names), parts
 * `LineChart::axis` (the axes and their labels).
 */
class LineChart extends Component
{
    public const COLORS = ['#38bdf8', '#34d399', '#fbbf24', '#f87171', '#c084fc', '#f472b6'];

    /**
     * @param array<string, list<float|int|null|array{float|int, float|int|null}>> $series name => y values or [x, y] pairs
     * @param list<string> $colors CSS colours, one per series in turn
     * @param string $marker  as `Canvas`: `auto`, `braille`, `quarter`, `half`, `block`, `pixels`
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $series = [],
        public bool $lines = true,
        public bool $points = false,
        public bool $legend = true,
        public ?float $xMin = null,
        public ?float $xMax = null,
        public ?float $yMin = null,
        public ?float $yMax = null,
        public array $colors = self::COLORS,
        public string $marker = 'auto',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        if ($colors === []) {
            throw new \InvalidArgumentException('A chart needs at least one colour');
        }
        $this->with($key, $id, $class, $style);
    }

    /** @return array<string, list<array{float, float}|null>> each series as points, null for a gap */
    private function points(): array
    {
        $out = [];
        foreach ($this->series as $name => $values) {
            $out[(string) $name] = [];
            foreach (array_values($values) as $i => $v) {
                $out[(string) $name][] = match (true) {
                    \is_array($v) => $v[1] === null ? null : [(float) $v[0], (float) $v[1]],
                    $v === null => null,
                    default => [(float) $i, (float) $v],
                };
            }
        }
        return $out;
    }

    private static function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function hex(Style $style): string
    {
        [$r, $g, $b] = $style->fg === Color::DEFAULT ? [136, 136, 136] : Color::toRgb($style->fg);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    protected function render(): Element
    {
        return new Canvas(draw: $this->draw(...), marker: $this->marker);
    }

    private function draw(CanvasContext $c): void
    {
        $cols = $c->width();
        $rows = $c->height();
        $series = $this->points();
        $all = array_merge(...array_values(array_map(static fn(array $s) => array_filter($s), $series)) ?: [[]]);
        $xs = array_column($all, 0);
        $ys = array_column($all, 1);
        $xMin = $this->xMin ?? ($xs === [] ? 0.0 : min($xs));
        $xMax = $this->xMax ?? ($xs === [] ? 1.0 : max($xs));
        $yMin = $this->yMin ?? ($ys === [] ? 0.0 : min($ys));
        $yMax = $this->yMax ?? ($ys === [] ? 1.0 : max($ys));
        if ($xMax <= $xMin) {
            $xMax = $xMin + 1;
        }
        if ($yMax <= $yMin) {
            [$yMin, $yMax] = [$yMin - 1, $yMax + 1];
        }
        $plotRows = $rows - 2;                         // the x axis and its labels are the last two rows
        $yTicks = [[$yMax, 0], [$yMin, $plotRows - 1]];
        if ($plotRows >= 9) {
            $yTicks[] = [($yMin + $yMax) / 2, intdiv($plotRows - 1, 2)];
        }
        $labelW = max(array_map(fn(array $t) => \strlen(self::number($t[0])), $yTicks));
        $left = $labelW + 1;                           // the y axis column; the plot starts after it
        $plotCols = $cols - $left - 1;
        if ($plotRows < 2 || $plotCols < 2) {
            return;
        }
        $c->textBaseline = 'top';
        $c->fillStyle = $this->hex($this->style('axis')->text);
        $c->textAlign = 'right';
        foreach ($yTicks as [$v, $row]) {
            $c->fillText(self::number($v), $labelW, $row);
        }
        $c->textAlign = 'left';
        $tick = array_column($yTicks, 1);
        for ($r = 0; $r < $plotRows; $r++) {
            $c->fillText(\in_array($r, $tick, true) ? '┤' : '│', $left, $r);
        }
        $c->fillText('└' . str_repeat('─', $plotCols), $left, $plotRows);
        $c->fillText(self::number($xMin), $left + 1, $plotRows + 1);
        $c->textAlign = 'right';
        $c->fillText(self::number($xMax), $cols, $plotRows + 1);

        // data: x across the plot columns, y over the rows of the tick labels (a quarter cell in from the edges)
        $px = fn(float $x) => $left + 1.25 + ($x - $xMin) / ($xMax - $xMin) * ($plotCols - 0.5);
        $py = fn(float $y) => 0.5 + (1 - ($y - $yMin) / ($yMax - $yMin)) * ($plotRows - 1);
        $i = 0;
        foreach ($series as $name => $points) {
            $color = $this->colors[$i++ % \count($this->colors)];
            $c->strokeStyle = $c->fillStyle = $color;
            $c->lineWidth = 0.25;
            if ($this->lines) {
                $c->beginPath();
                $pen = false;
                foreach ($points as $p) {
                    if ($p === null) {
                        $pen = false;
                        continue;
                    }
                    $pen ? $c->lineTo($px($p[0]), $py($p[1])) : $c->moveTo($px($p[0]), $py($p[1]));
                    $pen = true;
                }
                $c->stroke();
            }
            if ($this->points || !$this->lines) {
                foreach ($points as $p) {
                    if ($p !== null) {
                        $c->fillRect($px($p[0]) - 0.25, $py($p[1]) - 0.25, 0.5, 0.5);
                    }
                }
            }
        }
        if ($this->legend && $plotRows >= \count($series)) {
            $c->textAlign = 'right';
            $row = 0;
            foreach (array_keys($series) as $i => $name) {
                $c->fillStyle = $this->hex($this->style()->text);
                $c->fillText($name, $cols, $row);
                $c->fillStyle = $this->colors[$i % \count($this->colors)];
                $c->fillText($this->lines ? '━' : '●', $cols - Text::width($name) - 1, $row);
                $row++;
            }
        }
    }
}
