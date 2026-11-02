<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Overlay;
use phasync\Tui\Rect;
use phasync\Tui\Text;

/**
 * A list of commands with shortcuts shown, separators and submenus: a context menu, or the
 * dropdown of a `MenuBar`.
 *
 *     $menu = Menu::popup($this, [
 *         'open'   => ['label' => 'Open', 'shortcut' => 'Ctrl+O', 'onSelect' => fn() => $this->open()],
 *         'recent' => ['label' => 'Recent', 'items' => ['a' => 'one.txt', 'b' => 'two.txt']],
 *         '-',
 *         'quit'   => ['label' => 'Quit', 'shortcut' => 'Ctrl+Q'],
 *     ], new Rect($mouse->x, $mouse->y, 1, 1), onSelect: fn($key) => ...);
 *
 * **Items**, by key: a label, `'-'` (a separator), or `['label' => ..., 'shortcut' => 'Ctrl+O'
 * (shown right-aligned; a hint, it binds nothing), 'disabled' => true, 'onSelect' => Closure,
 * 'items' => [...] (a submenu)]`. Labels take markup. `Menu::popup($from, $items, $anchor)` opens
 * it as an overlay (`Component::overlay()`) of `$from`, below `$anchor` (a rectangle in
 * `$from`'s coordinates; default `$from`'s whole area) and returns the `Overlay`, which closes
 * with the key picked (`fiber\await($overlay->closed())`), or null. A `Menu` can also be placed
 * in a layout like any component.
 *
 * **Keys**: Up/Down (wrapping) skip separators and disabled items, Home/End, Enter or Space
 * pick the current item or open its submenu; Right opens a submenu, Left closes one; Escape
 * closes the menu (one level). **Mouse**: moving over an item makes it current, a click picks
 * it, a click outside closes the menu. Picking an item calls its `onSelect($key, $menu)`, then
 * the menu's `onSelect($key, $menu)` (the top menu's, for an item of a submenu), emits `select`,
 * and closes the whole menu, submenus included. Right or Left in a top-level menu that cannot
 * use them is passed to `$onSideways(-1|1)`; when that returns true the key is taken (a
 * `MenuBar` switches to the next menu).
 *
 * **Style**: `Menu` (the default theme gives it a border), parts `Menu::item` (states `current`,
 * `disabled`), `Menu::shortcut`, `Menu::separator`.
 */
class Menu extends Component
{
    private int $current = 0;
    private int $top = 0;
    private ?Overlay $sub = null;
    private ?Menu $parentMenu = null;

    /**
     * @param array<int|string, string|array{label?: string, shortcut?: string, disabled?: bool, onSelect?: \Closure, items?: array}> $items
     * @param (\Closure(int|string $key, Menu $menu): void)|null $onSelect
     * @param (\Closure(int $direction): bool)|null $onSideways
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $items = [],
        private readonly ?\Closure $onSelect = null,
        private readonly ?\Closure $onSideways = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->current = $this->selectable(0, 1) ?? 0;
    }

    /**
     * Open a menu as an overlay of $from, below $anchor (in $from's coordinates).
     *
     * @param array<int|string, string|array<string, mixed>> $items
     * @param (\Closure(int|string $key, Menu $menu): void)|null $onSelect
     * @param 'below'|'above' $place
     */
    public static function popup(Component $from, array $items, ?Rect $anchor = null, ?\Closure $onSelect = null, string $place = 'below'): Overlay
    {
        return $from->overlay(new self($items, $onSelect), $anchor, $place, modal: true);       // (modal: a click outside closes it, see overlayMouse())
    }

    protected function bindings(): array
    {
        return [
            'up' => 'previous', 'down' => 'next', 'home' => 'first', 'end' => 'last',
            'enter' => 'activate', 'space' => 'activate', 'right' => 'forward', 'left' => 'back',
        ];
    }

    /** @return list<array{key: int|string, label: string, shortcut: string, disabled: bool, separator: bool, items: ?array, onSelect: ?\Closure}> */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->items as $key => $item) {
            $spec = \is_array($item) ? $item : ['label' => $item];
            $rows[] = [
                'key' => $key,
                'label' => (string) ($spec['label'] ?? $key),
                'shortcut' => (string) ($spec['shortcut'] ?? ''),
                'disabled' => (bool) ($spec['disabled'] ?? false),
                'separator' => $item === '-',
                'items' => $spec['items'] ?? null,
                'onSelect' => $spec['onSelect'] ?? null,
            ];
        }
        return $rows;
    }

    private function selectable(int $from, int $by): ?int
    {
        $rows = $this->rows();
        $n = \count($rows);
        for ($i = 0, $at = $from; $i < $n; $i++, $at += $by) {
            $at = (($at % $n) + $n) % $n;
            if (!$rows[$at]['separator'] && !$rows[$at]['disabled']) {
                return $at;
            }
        }
        return null;
    }

    /** The key of the current item, null when there is none. */
    public function current(): int|string|null
    {
        return $this->rows()[$this->current]['key'] ?? null;
    }

    private function move(?int $to): bool
    {
        if ($this->attribute('disabled') || $to === null) {
            return false;
        }
        $this->closeSub();
        $this->current = $to;
        $this->requestRender();
        return true;
    }

    public function previous(): bool { return $this->move($this->selectable($this->current - 1, -1)); }
    public function next(): bool { return $this->move($this->selectable($this->current + 1, 1)); }
    public function first(): bool { return $this->move($this->selectable(0, 1)); }
    public function last(): bool { return $this->move($this->selectable(-1, -1)); }

    private function closeSub(): void
    {
        if ($this->sub?->isOpen()) {
            $this->sub->close();
        }
        $this->sub = null;
    }

    /** Pick the current item, or open its submenu (Enter, Space). */
    public function activate(): bool
    {
        $row = $this->rows()[$this->current] ?? null;
        if ($this->attribute('disabled') || $row === null || $row['separator'] || $row['disabled']) {
            return false;
        }
        if ($row['items'] !== null) {
            return $this->openSub($row['items']);
        }
        $this->finish($row['key'], $row['onSelect']);
        return true;
    }

    /** Right: open a submenu, else offer the key sideways. */
    public function forward(): bool
    {
        $row = $this->rows()[$this->current] ?? null;
        if ($row !== null && $row['items'] !== null && !$row['disabled'] && !$this->attribute('disabled')) {
            return $this->openSub($row['items']);
        }
        return $this->parentMenu === null && $this->onSideways !== null && ($this->onSideways)(1);
    }

    /** Left: close this submenu, else offer the key sideways. */
    public function back(): bool
    {
        if ($this->parentMenu !== null) {
            $this->inOverlay()?->close();
            return true;
        }
        return $this->onSideways !== null && ($this->onSideways)(-1);
    }

    /** @param array<int|string, mixed> $items */
    private function openSub(array $items): bool
    {
        if ($this->sub?->isOpen()) {
            $this->sub->content->focus();
            return true;
        }
        $sub = new self($items);
        $sub->parentMenu = $this;
        // (its window starts right of this one, its first row level with the current item)
        $this->sub = $this->overlay($sub, new Rect($this->surface->cols(), $this->current - $this->top - 2, 1, 1), 'below');
        return true;
    }

    private function finish(int|string $key, ?\Closure $item): void
    {
        $top = $this;
        while ($top->parentMenu !== null) {
            $top = $top->parentMenu;
        }
        $chain = [];
        for ($m = $this; $m !== null; $m = $m->parentMenu) {
            $chain[] = $m;
        }
        $this->call($item, $key, $this);
        $this->call($top->onSelect, $key, $top);
        $this->emit('select', $this);
        foreach ($chain as $m) {
            $m->inOverlay()?->close($key);
        }
    }

    protected function wantsPointer(): bool
    {
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $at = $mouse->y + $this->top;
        $row = $this->rows()[$at] ?? null;
        $usable = $row !== null && !$row['separator'] && !$row['disabled'];
        if ($mouse->action === Mouse::MOVE && $usable && $at !== $this->current) {
            $this->move($at);
        } elseif ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT && $usable) {
            $this->current = $at;
            $this->focus();
            $this->activate();
        } elseif ($mouse->action === Mouse::WHEEL_UP || $mouse->action === Mouse::WHEEL_DOWN) {
            $this->move($this->selectable($this->current + ($mouse->action === Mouse::WHEEL_UP ? -1 : 1), $mouse->action === Mouse::WHEEL_UP ? -1 : 1));
        }
        return true;
    }

    /** @return array{int, int} the width of the label column and of the right column (shortcut or arrow) */
    private function columns(): array
    {
        $label = $right = 0;
        foreach ($this->rows() as $r) {
            if (!$r['separator']) {
                $label = max($label, Text::width(Markup::plain($r['label'])));
                $right = max($right, $r['items'] !== null ? 1 : Text::width($r['shortcut']));
            }
        }
        return [$label, $right];
    }

    public function measure(int $cols, int $rows): ?array
    {
        [$label, $right] = $this->columns();
        return [min($cols, $label + ($right > 0 ? 2 + $right : 0) + 2), min($rows, \count($this->items))];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $height = $this->surface->rows();
        $rows = $this->rows();
        $this->current = min($this->current, max(0, \count($rows) - 1));
        if ($this->current < $this->top) {
            $this->top = $this->current;
        } elseif ($this->current >= $this->top + $height) {
            $this->top = $this->current - $height + 1;
        }
        $out = [];
        for ($i = $this->top; $i < min(\count($rows), $this->top + $height); $i++) {
            $r = $rows[$i];
            if ($r['separator']) {
                $out[] = Ansi::style($this->style('separator')->text) . str_repeat('─', $cols);
                continue;
            }
            $states = [...($r['disabled'] ? ['disabled'] : []), ...($i === $this->current ? ['current'] : [])];
            $item = $this->style('item', ...$states);
            $right = $r['items'] !== null ? '▸' : $r['shortcut'];
            $label = $this->markup($r['label'], 'item', ...$states);
            $pad = max(0, $cols - 2 - Text::width(Markup::plain($r['label'])) - Text::width($right));
            $out[] = Ansi::style($item->text) . ' ' . $label . Ansi::style($item->text) . str_repeat(' ', $pad)
                . Ansi::style($this->style(['item', 'shortcut'], ...$states)->text) . $right . Ansi::style($item->text) . ' ';
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . implode("\r\n", $out));
        return null;
    }
}
