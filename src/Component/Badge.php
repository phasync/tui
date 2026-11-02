<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A small coloured label, to mark a status or a category: ` new `, ` beta `, ` php × `.
 *
 *     new Badge('stable', 'success')
 *     new Badge('php', closable: true, onClose: fn(Badge $b) => $this->removeTag('php'))
 *
 * `$variant` is `'neutral'` (the default), `'info'`, `'success'`, `'warning'` or `'error'`: the
 * colours of the stylesheet. `$label` takes markup. With `closable: true` (a removable tag, as in
 * a tag list) it shows `×` after the label and takes the focus: a click on the `×`, or Delete or
 * Backspace on the focused tag, calls `onClose($badge)` and emits `close`; it does not remove
 * itself, the owner stops rendering it. Without `closable` it is plain display and takes no
 * focus. One row, as wide as its label plus a space each side. All of `$label`, `$variant` and
 * `$closable` are props.
 *
 * **Style**: `Badge`, with the state of its variant (`Badge:success`), `:focused` and `:disabled`;
 * the part `Badge::close` for the `×`.
 */
class Badge extends Component
{
    public const VARIANTS = ['neutral', 'info', 'success', 'warning', 'error'];

    /**
     * @param 'neutral'|'info'|'success'|'warning'|'error' $variant
     * @param (\Closure(Badge $badge): void)|null $onClose
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $label = '',
        public string $variant = 'neutral',
        public bool $closable = false,
        private readonly ?\Closure $onClose = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException("Unknown Badge variant '$variant': use neutral, info, success, warning or error");
        }
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->propsChanged();
    }

    protected function propsChanged(): void
    {
        foreach (self::VARIANTS as $v) {
            $this->setStyleState($v, $v === $this->variant);
        }
    }

    protected function bindings(): array
    {
        return $this->closable ? ['delete' => 'close', 'backspace' => 'close'] : [];
    }

    /** Ask the owner to remove the tag (Delete, Backspace): calls `onClose`; false when not closable or disabled. */
    public function close(): bool
    {
        if (!$this->closable || $this->attribute('disabled')) {
            return false;
        }
        $this->call($this->onClose, $this);
        $this->emit('close', $this);
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if (!$this->closable || $this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            if ($mouse->x >= $this->surface->cols() - 3) {
                $this->close();
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, Text::width(Markup::plain($this->label)) + 2 + ($this->closable ? 2 : 0)), 1];
    }

    protected function render(): null
    {
        $style = Ansi::style($this->style()->text);
        $close = $this->closable ? Ansi::style($this->style('close')->text) . ' ×' : '';
        $this->surface->write("\e[?25l" . $style . "\e[2J\e[H" . Text::truncate($style . ' ' . $this->markup($this->label) . $close . $style . ' ', $this->surface->cols()));
        return null;
    }
}
