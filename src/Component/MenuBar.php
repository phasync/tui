<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Overlay;
use phasync\Tui\Rect;
use phasync\Tui\Text;

use function fiber\await;

/**
 * A row of menu titles that open dropdown `Menu`s: ` File  Edit  Help `.
 *
 *     new MenuBar([
 *         'File' => ['new' => ['label' => 'New', 'shortcut' => 'Ctrl+N'], '-', 'quit' => 'Quit'],
 *         'Help' => ['about' => 'About'],
 *     ], onSelect: fn(string|int $key, MenuBar $bar) => ...)
 *
 * Each title maps to the items of its menu (see `Menu` for their format). On the bar Left/Right
 * (wrapping) change the title, Enter, Space or Down open its menu; in an open menu Left/Right
 * pass to the neighbouring menu when they have nothing else to do (a submenu to open or close),
 * Escape closes it. A click on a title opens its menu (on the open one: closes it, on another
 * while one is open: switches). Picking an item calls its `onSelect`, then `onSelect($key,
 * $bar)` and emits `select`; `openTitle()` is the title whose menu is open. The bar fills the width
 * it is given, one row. Item keys should be unique across the menus: they tell what was picked.
 *
 * **Style**: `MenuBar` (the bar), the part `MenuBar::title` (states `current`, `open`,
 * `focused`); the menus are `Menu`s.
 */
class MenuBar extends Component
{
    private int $current = 0;
    private ?Overlay $popup = null;
    /** @var list<array{string, int, int}> title, from column, to column (exclusive) */
    private array $hits = [];

    /**
     * @param array<string, array<int|string, string|array<string, mixed>>> $menus title => items
     * @param (\Closure(int|string $key, MenuBar $bar): void)|null $onSelect
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $menus = [],
        private readonly ?\Closure $onSelect = null,
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
        return ['left' => 'previous', 'right' => 'next', 'enter' => 'open', 'space' => 'open', 'down' => 'open'];
    }

    /** The title whose menu is open, null when none is. */
    public function openTitle(): ?string
    {
        return $this->popup?->isOpen() ? array_keys($this->menus)[$this->current] ?? null : null;
    }

    private function select(int $to): bool
    {
        $n = \count($this->menus);
        if ($this->attribute('disabled') || $n === 0) {
            return false;
        }
        $this->current = (($to % $n) + $n) % $n;
        $this->requestRender();
        return true;
    }

    public function previous(): bool { return $this->select($this->current - 1); }
    public function next(): bool { return $this->select($this->current + 1); }

    /** Open the menu of `$title` or of the current title (Enter); false when one is open already or disabled. */
    public function open(?string $title = null): bool
    {
        $titles = array_keys($this->menus);
        if ($title !== null) {
            $at = array_search($title, array_map('strval', $titles), true);
            if ($at === false) {
                return false;
            }
            $this->current = $at;
        }
        $title = (string) ($titles[$this->current] ?? '');
        if ($this->attribute('disabled') || !isset($this->menus[$title]) || !$this->isMounted()) {
            return false;
        }
        $this->closeMenu();
        $x = 0;
        foreach ($this->hits as [$t, $from, $to]) {
            if ($t === $title) {
                $x = $from;
            }
        }
        $menu = new Menu(
            $this->menus[$title],
            fn(int|string $key) => $this->picked($key),
            fn(int $direction) => $this->select($this->current + $direction) && $this->open(),
        );
        $popup = $this->popup = $this->overlay($menu, new Rect($x, 0, Text::width($title) + 2, 1), 'below');
        $this->go(function () use ($popup): void {
            await($popup->closed());        // (the bar renders again when it has ended)
        });
        $this->requestRender();
        return true;
    }

    private function closeMenu(): void
    {
        if ($this->popup?->isOpen()) {
            $this->popup->close();
        }
        $this->popup = null;
    }

    private function picked(int|string $key): void
    {
        $this->call($this->onSelect, $key, $this);
        $this->emit('select', $this);
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            foreach ($this->hits as $i => [$title, $from, $to]) {
                if ($mouse->x >= $from && $mouse->x < $to) {
                    $wasOpen = $this->openTitle() === $title;
                    $this->closeMenu();
                    $this->focus();
                    $this->current = $i;
                    if (!$wasOpen) {
                        $this->open();
                    }
                    $this->requestRender();
                    break;
                }
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $focused = $this->hasFocus();
        $open = $this->openTitle();
        $cols = $this->surface->cols();
        $line = '';
        $this->hits = [];
        $x = 0;
        $i = 0;
        foreach (array_keys($this->menus) as $title) {
            $title = (string) $title;
            $states = [...($i === $this->current ? ['current'] : []), ...($open === $title ? ['open'] : []), ...($focused && $i === $this->current ? ['focused'] : [])];
            $width = Text::width(Markup::plain($title)) + 2;
            $line .= Ansi::style($this->style('title', ...$states)->text) . ' ' . $this->markup($title, 'title', ...$states) . Ansi::style($this->style('title', ...$states)->text) . ' ';
            $this->hits[] = [$title, $x, $x + $width];
            $x += $width;
            $i++;
        }
        $this->surface->write(Ansi::style($this->style()->text) . "\e[?25l\e[2J\e[H" . Text::truncate($line, $cols));
        return null;
    }
}
