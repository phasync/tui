<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * One choice out of a few, all visible: `(•) Small`, `( ) Large`.
 *
 *     new RadioGroup(['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'], value: 'm',
 *         onChange: fn($key) => ...)
 *
 * Up/Down (or k/j) move the choice and pick it; Space picks the highlighted one; a click picks
 * the one clicked. With `horizontal: true` the options are in a row and Left/Right move.
 * `onChange($key, $group)` is called after every change and the event `change` emitted. In a
 * `Form`, its value is `value()` (the key). Labels take markup. One row per option.
 *
 * **Style**: `RadioGroup`, the part `RadioGroup::option` (states `chosen`, `current`), the part
 * `RadioGroup::mark`; states `:focused`, `:disabled`.
 */
class RadioGroup extends Component implements Field
{
    private int|string|null $value;
    private int $current = 0;

    /**
     * @param array<int|string, string> $options key => label
     * @param (\Closure(int|string $key, RadioGroup $group): void)|null $onChange
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $options = [],
        int|string|null $value = null,
        public bool $horizontal = false,
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $keys = array_keys($options);
        $this->value = $value !== null && \in_array($value, $keys, true) ? $value : null;
        $at = $this->value === null ? false : array_search($this->value, $keys, true);
        $this->current = $at === false ? 0 : $at;
    }

    protected function bindings(): array
    {
        return $this->horizontal
            ? ['left' => 'previous', 'right' => 'next', 'space' => 'pick']
            : ['up' => 'previous', 'k' => 'previous', 'down' => 'next', 'j' => 'next', 'space' => 'pick'];
    }

    /** The key of the chosen option, null when none. */
    public function value(): int|string|null
    {
        return $this->value;
    }

    public function setValue(int|string|null $key): void
    {
        if ($key !== null && !\array_key_exists($key, $this->options)) {
            return;
        }
        $this->current = $key === null ? $this->current : (int) array_search($key, array_keys($this->options), true);
        if ($key !== $this->value) {
            $this->value = $key;
            if ($key !== null) {
                $this->call($this->onChange, $key, $this);
            }
            $this->emit('change', $this);
        }
        $this->requestRender();
    }

    private function step(int $by): bool
    {
        $n = \count($this->options);
        if ($this->attribute('disabled') || $n === 0) {
            return false;
        }
        $to = $this->current + $by;
        if ($to < 0 || $to >= $n) {
            return false;       // the key goes on: focus moves to the next field
        }
        $this->setValue(array_keys($this->options)[$to]);
        return true;
    }

    public function previous(): bool { return $this->step(-1); }
    public function next(): bool { return $this->step(1); }

    /** Pick the highlighted option (Space). */
    public function pick(): bool
    {
        if ($this->attribute('disabled') || $this->options === []) {
            return false;
        }
        $this->setValue(array_keys($this->options)[$this->current]);
        return true;
    }

    /** @return list<string> the plain text of each option, as drawn */
    private function cells(): array
    {
        $out = [];
        foreach (array_keys($this->options) as $i => $k) {
            $out[] = ($k === $this->value ? '(•) ' : '( ) ') . Markup::plain($this->options[$k]);
        }
        return $out;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $keys = array_keys($this->options);
            if ($this->horizontal) {
                $x = 0;
                foreach ($this->cells() as $i => $cell) {
                    $x += Text::width($cell) + 2;
                    if ($mouse->x < $x) {
                        $this->current = $i;
                        return $this->pick() || true;
                    }
                }
            } elseif (isset($keys[$mouse->y])) {
                $this->current = $mouse->y;
                $this->pick();
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        $cells = $this->cells();
        if ($this->horizontal) {
            return [min($cols, array_sum(array_map(Text::width(...), $cells)) + 2 * max(0, \count($cells) - 1)), 1];
        }
        return [min($cols, max([0, ...array_map(Text::width(...), $cells)])), \count($cells)];
    }

    protected function render(): null
    {
        $focused = $this->hasFocus();
        $parts = [];
        foreach (array_keys($this->options) as $i => $k) {
            $states = [...($k === $this->value ? ['chosen'] : []), ...($focused && $i === $this->current ? ['current'] : [])];
            $mark = Ansi::style($this->style('mark', ...$states)->text) . ($k === $this->value ? '(•)' : '( )');
            $parts[] = $mark . Ansi::style($this->style('option', ...$states)->text) . ' ' . $this->markup($this->options[$k], 'option', ...$states);
        }
        $cols = $this->surface->cols();
        $reset = Ansi::style($this->style()->text);
        if ($this->horizontal) {
            $this->surface->write("\e[?25l\e[2J\e[H" . $reset . Text::truncate(implode($reset . '  ', $parts), $cols));
        } else {
            $this->surface->write("\e[?25l\e[2J\e[H" . $reset . implode("\r\n", array_map(static fn($p) => Text::truncate($p, $cols), $parts)));
        }
        return null;
    }
}
