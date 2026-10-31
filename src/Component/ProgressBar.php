<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\ComputedStyle;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * How far something has come: a bar filled to `$value` (0 to 1) with a label and the percentage
 * after it, or, with the value null, an indeterminate bar (a block moving to and fro, animated by
 * itself) with its label.
 *
 *     new ProgressBar($done / $total, label: "$done/$total files")    // ████░░░░ 3/10 files 30%
 *     new ProgressBar($v, label: 'upload {percent} of 2 GB')          // ████░░░░ upload 30% of 2 GB
 *     new ProgressBar(null, label: 'connecting')                      // indeterminate
 *
 * The label may hold `{percent}` (the value as a percentage, `30%`) and `{value}` (the value
 * with two decimals, `0.30`); a label with one of them is shown as it is, any other label is
 * followed by the percentage, and without a label the percentage is shown alone. So a runner
 * that shows a name and how far it is needs no `Text` around the bar. The label takes at most
 * half the width (cut with "…"), and nothing is drawn past the bar's own width.
 *
 * `$value` and `$label` are props: place a `new ProgressBar(...)` in the tree on every render,
 * or set them with `setValue()`. The value may be a closure (`fn() => $state->done / 100`):
 * the bar reads it when it renders, and renders again by itself when a `State` the closure read
 * changes, without the component around it rendering (so a closure reading a value that
 * changes every frame is called every frame). The label takes markup (see `Markup`). A new
 * value that draws the same bar (a change smaller than a cell, the same percentage) costs a
 * comparison: the bar compares the cells it would fill and the label it would show with what
 * it drew before drawing anything, so neither `setValue()` from a fast coroutine nor a closure
 * needs rounding of its own. One row; it takes the width it is given.
 *
 * **Style**: `ProgressBar` (the label and the empty part), the part `ProgressBar::bar` (the
 * filled part; the default theme: the accent colour).
 */
class ProgressBar extends Component
{
    private int $tick = 0;
    private bool $animating = false;
    /** What it drew last: its look in short (drawn()), so a value that draws the same costs no drawing. */
    private string $drawn = '';
    /** @var array{int, int, int, string, string, string, string}|null what was drawn last: cols, bar width, filled from, filled cells, label, styles (see drawing()) */
    private ?array $cells = null;

    /**
     * @param float|(\Closure(): ?float)|null $value from 0 to 1 (clamped), null for indeterminate, or a closure giving it
     * @param string     $label after the bar, followed by the percentage unless it holds `{percent}` or `{value}`
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public float|\Closure|null $value = 0.0,
        public string $label = '',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
    }

    /**
     * Set the value (0 to 1, null for indeterminate, or a closure) and the label (null keeps
     * it). It renders only when the bar then looks different.
     */
    public function setValue(float|\Closure|null $value, ?string $label = null): void
    {
        $this->value = $value;
        $this->label = $label ?? $this->label;
        if ($value instanceof \Closure || !$this->isMounted() || $this->look($value) !== $this->drawn) {
            $this->requestRender();
        }
    }

    /**
     * An indeterminate bar moves by itself, ten steps a second, for as long as it is
     * indeterminate; a determinate one has no coroutine (it renders when its value changes).
     */
    private function animate(?float $value): void
    {
        if ($value !== null || $this->animating) {
            return;
        }
        $this->animating = true;
        $this->go(function (): void {
            try {
                while ($this->value() === null && $this->sleep(0.1)) {
                    $this->tick++;
                }
            } finally {
                $this->animating = false;
            }
        });
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $value = $this->value();
        $this->animate($value);       // (every value it gets, by props or setValue(), comes with a render)
        $styles = [$this->style(), $this->style('bar')];
        $look = $this->look($value, $styles);
        if ($look !== $this->drawn) {
            $this->drawn = $look;
            $this->surface->write($this->drawing($value, $styles));
        }
        return null;
    }

    /** The value now: the prop, or what its closure gives. */
    private function value(): ?float
    {
        $value = $this->value instanceof \Closure ? ($this->value)() : $this->value;
        return $value === null ? null : (float) $value;
    }

    /**
     * What the bar shows for $value (in $styles, see drawing()), in short: its width, the label (with the percentage), the
     * cells filled and the styles. Equal for two values that draw the same.
     */
    private function look(float|\Closure|null $value, ?array $styles = null): string
    {
        $styles ??= [$this->style(), $this->style('bar')];
        if ($value instanceof \Closure) {
            $value = $this->value();
        }
        $cols = $this->surface->cols();
        $label = $this->labelText($value);
        $room = intdiv($cols, 2) - 1;
        $shown = $room < 1 || $label === '' ? 0 : min($room, Text::width(Markup::plain($label)));
        $width = max(0, $cols - ($shown === 0 ? 0 : $shown + 1));
        $cells = $value === null ? 't' . $this->tick : (int) round(max(0, min(1, $value)) * $width);
        return $cols . "\0" . $cells . "\0" . spl_object_id($styles[0]) . "\0" . spl_object_id($styles[1]) . "\0" . $label;
    }

    /**
     * What the bar writes for $value at its size now, in $styles (its own and its `bar` part's):
     * everything, or only the cells and the label that changed since the last drawing.
     *
     * @param array{ComputedStyle, ComputedStyle} $styles
     */
    private function drawing(float|\Closure|null $value, array $styles): string
    {
        if ($value instanceof \Closure) {
            $value = $this->value();
        }
        $cols = $this->surface->cols();
        $label = $this->labelText($value);
        $room = intdiv($cols, 2) - 1;           // (and the space before it)
        $label = $room < 1 ? '' : Text::truncate($this->markup($label), $room);
        $width = max(0, $cols - ($label === '' ? 0 : Text::width($label) + 1));
        if ($value === null) {
            $block = max(1, intdiv($width, 5));
            $span = max(1, $width - $block);
            $pos = $this->tick % (2 * $span);
            $from = $pos < $span ? $pos : 2 * $span - $pos;
            $cells = [$from, $block];
        } else {
            $cells = [0, (int) round(max(0, min(1, $value)) * $width)];
        }
        [$from, $n] = $cells;
        $n = min($n, $width - $from);
        $base = Ansi::style($styles[0]->text);
        $bar = Ansi::style($styles[1]->text);
        $was = $this->cells;
        $this->cells = [$cols, $width, $from, $n, $label, $base, $bar];
        if ($was !== null && [$was[0], $was[1], $was[5], $was[6]] === [$cols, $width, $base, $bar]) {
            // the same bar at the same size: only the cells whose fill changed, and the label
            // (a bar that moves by a cell writes a cell, not the whole row)
            $out = '';
            [, , $oldFrom, $oldN, $oldLabel] = $was;
            for ($x = 0; $x < $width; $x++) {
                $filled = $x >= $from && $x < $from + $n;
                if ($filled === ($x >= $oldFrom && $x < $oldFrom + $oldN)) {
                    continue;
                }
                $run = 1;
                while ($x + $run < $width && ($x + $run >= $from && $x + $run < $from + $n) === $filled
                    && ($x + $run >= $oldFrom && $x + $run < $oldFrom + $oldN) !== $filled) {
                    $run++;
                }
                $out .= "\e[1;" . ($x + 1) . 'H' . ($filled ? $bar . str_repeat('█', $run) : $base . str_repeat('░', $run));
                $x += $run - 1;
            }
            if ($label !== $oldLabel) {
                $out .= "\e[1;" . ($width + 1) . 'H' . $base . "\e[K" . ($label === '' ? '' : ' ' . $label);
            }
            return "\e[?7;25l" . $out;
        }
        // (no autowrap: nothing goes past the last column, whatever the label holds)
        return "\e[?7;25l" . $base . "\e[2J\e[H" . str_repeat('░', $from) . $bar . str_repeat('█', $n) . $base . str_repeat('░', max(0, $width - $from - $n))
            . ($label === '' ? '' : ' ' . $label);
    }

    /** The label with its placeholders filled in, or followed by the percentage (see the class description). */
    private function labelText(?float $value): string
    {
        $label = str_replace(["\r", "\n", "\t", "\e"], ' ', $this->label);
        if ($value === null) {
            return str_replace(['{percent}', '{value}'], '', $label);
        }
        $value = max(0.0, min(1.0, $value));
        $percent = (int) round($value * 100) . '%';
        if (str_contains($label, '{percent}') || str_contains($label, '{value}')) {
            return str_replace(['{percent}', '{value}'], [$percent, number_format($value, 2)], $label);
        }
        return $label === '' ? $percent : "$label $percent";
    }
}
