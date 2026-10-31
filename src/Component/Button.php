<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * A button: a label that does something when pressed with Enter, Space or a click.
 *
 *     new Row([
 *         new Button('Save', onPress: fn() => $this->save()),
 *         new Button('Cancel', onPress: fn() => $this->close()),
 *     ], gap: 2)
 *
 * Drawn as `[ label ]`, one row, as wide as that (its `measure()`). The label takes markup
 * (`'<u>S</u>ave'`, see `Markup`). Pressing it calls
 * `$onPress($button)` and emits the event `press`. In a `Form`, a button without `$onPress`
 * submits the form. A disabled button (`disabled: true`) takes no input.
 *
 * **Style**: `Button`, with the states `:focused`, `:pressed` (while the mouse button is held
 * on it) and `:disabled`; the default theme shows the focused button inverted.
 */
class Button extends Component
{
    /**
     * @param string $label the text on it (a prop)
     * @param (\Closure(Button): void)|null $onPress what pressing it does
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $label,
        private readonly ?\Closure $onPress = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
    }

    protected function bindings(): array
    {
        return ['enter' => 'press', 'space' => 'press'];
    }

    /** Press it: `$onPress`, the `press` event; in a `Form` without `$onPress`, submit it. False when disabled. */
    public function press(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($this->onPress !== null) {
            $this->call($this->onPress, $this);
        } else {
            for ($c = $this->parent(); $c !== null; $c = $c->parent()) {
                if ($c instanceof Form) {
                    $c->submit();
                    break;
                }
            }
        }
        $this->emit('press', $this);
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled') || $mouse->button !== Mouse::LEFT && $mouse->action !== Mouse::RELEASE) {
            return false;
        }
        $inside = $mouse->x >= 0 && $mouse->y >= 0 && $mouse->x < $this->surface->cols() && $mouse->y < $this->surface->rows();
        if ($mouse->action === Mouse::PRESS) {
            $this->focus();
            $this->setStyleState('pressed');
        } elseif ($mouse->action === Mouse::DRAG) {
            $this->setStyleState('pressed', $inside);
        } elseif ($mouse->action === Mouse::RELEASE) {
            $was = $this->hasStyleState('pressed');
            $this->setStyleState('pressed', false);
            if ($was && $inside) {
                $this->press();
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, Text::width(Markup::plain($this->label)) + 4), 1];
    }

    protected function render(): null
    {
        $text = Text::truncate('[ ' . $this->markup($this->label) . ' ]', $this->surface->cols());
        $this->surface->write("\e[?25l\e[2J\e[H" . Ansi::style($this->style()->text) . $text);
        return null;
    }
}
