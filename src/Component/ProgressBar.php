<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Text;

/**
 * How far something has come: a bar filled to `$value` (0 to 1) with a label after it, or, with
 * the value null, an indeterminate bar (a block moving to and fro, animated by itself).
 *
 *     new ProgressBar($done / $total, label: "$done/$total files")
 *     new ProgressBar(null, label: 'connecting')       // indeterminate
 *
 * `$value` and `$label` are props: place a `new ProgressBar(...)` in the tree on every render,
 * or set them with `setValue()`. Without a label the percentage is shown. One row; it takes the
 * width it is given.
 *
 * **Style**: `ProgressBar` (the label and the empty part), the part `ProgressBar::bar` (the
 * filled part; the default theme: the accent colour).
 */
class ProgressBar extends Component
{
    private int $tick = 0;

    /**
     * @param float|null $value from 0 to 1 (clamped), null for indeterminate
     * @param string     $label after the bar; '' shows the percentage
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

    protected function run(): void
    {
        // an indeterminate bar moves by itself (a determinate one renders only when its value changes)
        while ($this->isMounted()) {
            if ($this->value === null) {
                $this->tick++;
                $this->requestRender();
            }
            \fiber\sleep(0.1);
        }
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $label = $this->label !== '' ? $this->label : ($this->value === null ? '' : (int) round(max(0, min(1, $this->value)) * 100) . '%');
        $label = Text::truncate($label, max(0, intdiv($cols, 2)));
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
        $out = "\e[?25l\e[2J\e[H" . $base . str_repeat('░', $from) . $bar . str_repeat('█', $n) . $base . str_repeat('░', max(0, $width - $from - $n));
        $this->surface->write($out . ($label === '' ? '' : ' ' . $label));
        return null;
    }
}
