<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
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
 * or set them with `setValue()`. One row; it takes the width it is given.
 *
 * **Style**: `ProgressBar` (the label and the empty part), the part `ProgressBar::bar` (the
 * filled part; the default theme: the accent colour).
 */
class ProgressBar extends Component
{
    private int $tick = 0;
    private bool $animating = false;

    /**
     * @param float|null $value from 0 to 1 (clamped), null for indeterminate
     * @param string     $label after the bar, followed by the percentage unless it holds `{percent}` or `{value}`
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public ?float $value = 0.0,
        public string $label = '',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
    }

    /** Set the value (0 to 1, null for indeterminate) and the label (null keeps it). */
    public function setValue(?float $value, ?string $label = null): void
    {
        $this->value = $value;
        $this->label = $label ?? $this->label;
        $this->requestRender();
    }

    /**
     * An indeterminate bar moves by itself, ten steps a second, for as long as it is
     * indeterminate; a determinate one has no coroutine (it renders when its value changes).
     */
    private function animate(): void
    {
        if ($this->value !== null || $this->animating) {
            return;
        }
        $this->animating = true;
        $this->go(function (): void {
            try {
                while ($this->value === null && $this->sleep(0.1)) {
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
        $this->animate();       // (every value it gets, by props or setValue(), comes with a render)
        $cols = $this->surface->cols();
        $label = $this->labelText();
        $room = intdiv($cols, 2) - 1;           // (and the space before it)
        $label = $room < 1 ? '' : Text::truncate($label, $room);
        $width = max(0, $cols - ($label === '' ? 0 : Text::width($label) + 1));
        if ($this->value === null) {
            $block = max(1, intdiv($width, 5));
            $span = max(1, $width - $block);
            $pos = $this->tick % (2 * $span);
            $from = $pos < $span ? $pos : 2 * $span - $pos;
            $cells = [$from, $block];
        } else {
            $cells = [0, (int) round(max(0, min(1, $this->value)) * $width)];
        }
        [$from, $n] = $cells;
        $n = min($n, $width - $from);
        $base = Ansi::style($this->style()->text);
        $bar = Ansi::style($this->style('bar')->text);
        // (no autowrap: nothing goes past the last column, whatever the label holds)
        $out = "\e[?7;25l" . $base . "\e[2J\e[H" . str_repeat('░', $from) . $bar . str_repeat('█', $n) . $base . str_repeat('░', max(0, $width - $from - $n));
        $this->surface->write($out . ($label === '' ? '' : ' ' . $label));
        return null;
    }

    /** The label with its placeholders filled in, or followed by the percentage (see the class description). */
    private function labelText(): string
    {
        $label = str_replace(["\r", "\n", "\t", "\e"], ' ', $this->label);
        if ($this->value === null) {
            return str_replace(['{percent}', '{value}'], '', $label);
        }
        $value = max(0.0, min(1.0, $this->value));
        $percent = (int) round($value * 100) . '%';
        if (str_contains($label, '{percent}') || str_contains($label, '{value}')) {
            return str_replace(['{percent}', '{value}'], [$percent, number_format($value, 2)], $label);
        }
        return $label === '' ? $percent : "$label $percent";
    }
}
