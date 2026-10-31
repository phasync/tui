<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A yes/no choice with a label: a checkbox `[x] Remember me`, or with `toggle: true` a switch
 * `━● Dark mode`.
 *
 *     $remember = $this->child('remember', fn() => new Checkbox('Remember me', onChange: fn(bool $on) => ...));
 *     $remember->checked();
 *
 * Space, Enter or a click flips it; `$onChange($checked, $checkbox)` is called and the event
 * `change` emitted. One row, as wide as its box and label (`measure()`). In a `Form`, its
 * value is `checked()`. A disabled one takes no input.
 *
 * **Style**: `Checkbox`, states `:focused`, `:checked`, `:disabled`; the part `Checkbox::box`
 * (the box or the switch) with the state `checked`.
 */
class Checkbox extends Component implements Field
{
    /**
     * @param string $label  the text after the box (a prop)
     * @param bool   $checked its first value
     * @param bool   $toggle  drawn as a switch instead of a box (a prop)
     * @param (\Closure(bool $checked, Checkbox $checkbox): void)|null $onChange after every flip
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $label = '',
        private bool $checked = false,
        public bool $toggle = false,
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->setStyleState('checked', $checked);
    }

    protected function bindings(): array
    {
        return ['space' => 'flip', 'enter' => 'flip'];
    }

    /** True when it is checked (on). */
    public function checked(): bool
    {
        return $this->checked;
    }

    /** The value in a `Form`: `checked()`. */
    public function value(): bool
    {
        return $this->checked;
    }

    /** Check or uncheck it (calls `$onChange` when that changed it). */
    public function setChecked(bool $checked): void
    {
        if ($checked === $this->checked) {
            return;
        }
        $this->checked = $checked;
        $this->setStyleState('checked', $checked);
        $this->call($this->onChange, $checked, $this);
        $this->emit('change', $this);
    }

    /** Flip it, as Space does; false when disabled. */
    public function flip(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->setChecked(!$this->checked);
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $this->flip();
        }
        return true;
    }

    private function mark(): string
    {
        if ($this->toggle) {
            return $this->checked ? '━●' : '●━';
        }
        return $this->checked ? '[x]' : '[ ]';
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, Text::width($this->mark()) + ($this->label === '' ? 0 : 1 + Text::width($this->label))), 1];
    }

    protected function render(): null
    {
        $style = $this->style();
        $box = $this->style('box', ...($this->checked ? ['checked'] : []));
        $line = Ansi::style($box->text) . $this->mark() . Ansi::style($style->text) . ($this->label === '' ? '' : ' ' . $this->label);
        $this->surface->write("\e[?25l\e[2J\e[H" . Text::truncate($line, $this->surface->cols()));
        return null;
    }
}
