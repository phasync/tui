<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * Pick a number in a range with the keyboard or the mouse: `volume ━━━━●─────── 40`.
 *
 *     new Slider(0, 100, value: 40, step: 5, label: 'volume', onChange: fn(float $v) => ...)
 *
 * Left/Right (or h/l) move by `$step`, Page Up/Down by ten steps, Home/End to the ends; a click
 * or drag on the track sets the value there. `onChange($value, $slider)` is called after every
 * change and the event `change` emitted. In a `Form`, its value is `value()`. One row; it takes
 * the width it is given. `$label` and `$min`/`$max`/`$step` are props.
 *
 * **Style**: `Slider` (the track), parts `Slider::filled` (the part before the knob),
 * `Slider::knob`; states `:focused`, `:disabled`.
 */
class Slider extends Component implements Field
{
    private float $value;
    private int $trackX = 0;
    private int $trackWidth = 0;

    /**
     * @param (\Closure(float $value, Slider $slider): void)|null $onChange
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public float $min = 0,
        public float $max = 100,
        float $value = 0,
        public float $step = 1,
        public string $label = '',
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if ($max <= $min || $step <= 0) {
            throw new \InvalidArgumentException('A Slider needs min < max and a positive step');
        }
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->value = $this->snap($value);
    }

    protected function propsChanged(): void
    {
        $this->value = $this->snap($this->value);     // (min, max or step may have moved under it)
    }

    protected function bindings(): array
    {
        return [
            'left' => 'less', 'h' => 'less', 'right' => 'more', 'l' => 'more',
            'pageup' => 'muchMore', 'pagedown' => 'muchLess', 'home' => 'toMin', 'end' => 'toMax',
        ];
    }

    private function snap(float $v): float
    {
        $v = $this->min + round(($v - $this->min) / $this->step) * $this->step;
        return round(min($this->max, max($this->min, $v)), 10);
    }

    public function value(): float
    {
        return $this->value;
    }

    public function setValue(float $value): void
    {
        $value = $this->snap($value);
        if ($value === $this->value) {
            return;
        }
        $this->value = $value;
        $this->call($this->onChange, $value, $this);
        $this->emit('change', $this);
        $this->requestRender();
    }

    private function move(float $by): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->setValue($this->value + $by);
        return true;
    }

    public function less(): bool { return $this->move(-$this->step); }
    public function more(): bool { return $this->move($this->step); }
    public function muchLess(): bool { return $this->move(-10 * $this->step); }
    public function muchMore(): bool { return $this->move(10 * $this->step); }
    public function toMin(): bool { return $this->move($this->min - $this->value); }
    public function toMax(): bool { return $this->move($this->max - $this->value); }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if (($mouse->action === Mouse::PRESS || $mouse->action === Mouse::DRAG) && $mouse->button === Mouse::LEFT) {
            $onTrack = $mouse->x >= $this->trackX && $mouse->x < $this->trackX + $this->trackWidth;
            if ($mouse->action === Mouse::PRESS) {
                $this->focus();
            }
            if ($onTrack && $this->trackWidth > 1) {
                $at = min($this->trackWidth - 1, max(0, $mouse->x - $this->trackX));
                $this->setValue($this->min + $at / ($this->trackWidth - 1) * ($this->max - $this->min));
            }
        }
        return true;
    }

    private function number(): string
    {
        return (string) ($this->value == (int) $this->value ? (int) $this->value : $this->value);
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $prefix = $this->label === '' ? '' : $this->markup($this->label) . ' ';
        $suffix = ' ' . $this->number();
        $this->trackX = Text::width(\phasync\Tui\Markup::plain($this->label)) + ($this->label === '' ? 0 : 1);
        $this->trackWidth = max(0, $cols - $this->trackX - Text::width($suffix));
        $track = '';
        if ($this->trackWidth > 0) {
            $at = $this->trackWidth === 1 ? 0 : (int) round(($this->value - $this->min) / ($this->max - $this->min) * ($this->trackWidth - 1));
            $track = Ansi::style($this->style('filled')->text) . str_repeat('━', $at)
                . Ansi::style($this->style('knob')->text) . '●'
                . Ansi::style($this->style()->text) . str_repeat('─', max(0, $this->trackWidth - $at - 1));
        }
        $line = Ansi::style($this->style()->text) . $prefix . $track . Ansi::style($this->style()->text) . $suffix;
        $this->surface->write("\e[?25l\e[2J\e[H" . Text::truncate($line, $cols));
        return null;
    }
}
