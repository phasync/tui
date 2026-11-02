<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text as TextElement;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A header row that shows or hides its content: `▾ Details` over the content, `▸ Details` alone.
 *
 *     new Collapsible('Advanced', new Column([new Checkbox('Verbose'), new Editor(maxLines: 1)]),
 *         collapsed: true, onToggle: fn(bool $collapsed) => ...)
 *     new Collapsible('Notes', 'Plain text content, wrapped to the width')
 *
 * Enter or Space on the header, or a click on it, toggles it; Right opens and Left closes it
 * (Left/Right are left alone otherwise). `$content` is an element tree, or a string shown as
 * text. `$title` takes markup. While collapsed the content is not laid out and not drawn (its
 * components are stopped); expanding it makes them anew. `collapsed` is the first instance's
 * only (like `Checkbox`'s `checked:`): a render that builds the widget again does not undo
 * the user's choice, use `setCollapsed()` for that. `onToggle($collapsed, $collapsible)` is
 * called after every change and the event `toggle` emitted. A disabled one cannot be toggled.
 * The header is one row; the whole is as high as the header and the content.
 *
 * **Style**: `Collapsible`, parts `Collapsible::header` (states `collapsed`, `focused`),
 * `Collapsible::mark` (the arrow, on the header); states `:focused`, `:disabled`.
 */
class Collapsible extends Component
{
    /**
     * @param (\Closure(bool $collapsed, Collapsible $collapsible): void)|null $onToggle
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $title = '',
        public Element|string|null $content = null,
        private bool $collapsed = true,
        private readonly ?\Closure $onToggle = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->setStyleState('collapsed', $collapsed);
    }

    protected function bindings(): array
    {
        return ['enter' => 'toggle', 'space' => 'toggle', 'right' => 'expand', 'left' => 'collapse'];
    }

    /** True while the content is hidden. */
    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }

    /** Show or hide the content (calls `$onToggle` when that changed it). */
    public function setCollapsed(bool $collapsed): void
    {
        if ($collapsed === $this->collapsed) {
            return;
        }
        $this->collapsed = $collapsed;
        $this->setStyleState('collapsed', $collapsed);
        $this->call($this->onToggle, $collapsed, $this);
        $this->emit('toggle', $this);
        $this->requestRender();
    }

    /** Flip it, as Enter does; false when disabled. */
    public function toggle(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->setCollapsed(!$this->collapsed);
        return true;
    }

    /** Show the content (Right); false when it is shown already or disabled, so the key goes on. */
    public function expand(): bool
    {
        return $this->collapsed && $this->toggle();
    }

    /** Hide the content (Left); false when it is hidden already or disabled. */
    public function collapse(): bool
    {
        return !$this->collapsed && $this->toggle();
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->y !== 0 || $mouse->action !== Mouse::PRESS || $mouse->button !== Mouse::LEFT) {
            return false;
        }
        if ($this->attribute('disabled')) {
            return true;
        }
        $this->focus();
        $this->toggle();
        return true;
    }

    protected function render(): Element
    {
        $states = [...($this->collapsed ? ['collapsed'] : []), ...($this->hasFocus() && $this->surface->focused() === null ? ['focused'] : [])];
        $header = new TextElement([
            new Span($this->collapsed ? '▸ ' : '▾ ', style: $this->style(['header', 'mark'], ...$states)->properties()),
            new Span($this->title),
        ], wrap: false, height: 1, style: $this->style('header', ...$states)->properties());
        $content = $this->content;
        if (\is_string($content)) {
            $content = new TextElement($content);
        }
        return new Column($this->collapsed || $content === null ? [$header] : [$header, $content]);
    }
}
