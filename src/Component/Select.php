<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Overlay;
use phasync\Tui\Text;

/**
 * Pick one item or several from a list or a table, filtering as you type: a file picker, a
 * command palette, a member list.
 *
 *     $pick = $this->child('fruit', fn() => new Select(
 *         items: ['a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry'],
 *         onActivate: fn($key) => $this->open($key),
 *     ));
 *
 *     new Select(                                // a table with a header, several picked
 *         items: [1201 => ['1201', 'php', '3.2'], 88 => ['88', 'sshd', '0.0']],
 *         columns: ['PID' => ['width' => 6, 'align' => 'right'], 'Command' => '1fr', 'CPU' => 5],
 *         multiple: true,
 *         onChange: fn(array $keys) => $this->state->picked = $keys,
 *     )
 *
 * **Items** by key (your ids): a label each (one column, no header), or a list of cells with
 * `columns` as for `Table` (header, widths, alignment, sorting by a click on a title). The
 * selection is kept by key: through `setItems()` (or new `items` from a render), sorting and
 * filtering; when the current item is gone, the one at its place becomes current.
 *
 * **Keys**: those of `Table` (Up/Down, Page Up/Down, Home/End, Left/Right for wide tables),
 * Enter activates the current item (as does a click on it). With `multiple`, Space marks or
 * unmarks the current item and Alt-A marks all shown.
 *
 * **Filtering** is a mode: typing a character (or `/`) starts it, and while it is on, what is
 * typed goes into the filter, Space included; only the items whose text contains it (ignoring
 * case) are shown, under them the filter line. Up/Down still move. Backspace takes a character
 * back, Escape clears the filter and ends the mode (an Escape with no filter goes on to the
 * parent). Enter ends the mode with `multiple`, keeping the filter, so Space marks again (and
 * typing goes on with the filter); without `multiple` Enter activates the current item.
 *
 * **Dropdown** (`dropdown: true`): a one-line field showing the current item; Enter, Space,
 * Down or a click opens the list under it (above it near the bottom of the screen; shrunk and
 * scrolling when neither side has room), an overlay (`Component::overlay()`). Enter or a click
 * there picks an item and closes it, Escape or a click outside closes it unchanged. Typing on
 * the closed field opens it, filtering.
 *
 * **Callbacks**: `onSelect($key, $select)` when the current item changed, `onActivate($key,
 * $select)` on Enter or a click on the current item, `onChange($keys, $select)` when the
 * marked items changed (with `multiple`).
 *
 * **Style** parts: those of `Table`, with the row state `selected` for a marked item (and the
 * current one without `multiple`), and `Select::filter` for the filter line.
 */
final class Select extends Table implements Field
{
    /** @var array<int|string, true> the marked items */
    private array $marked = [];
    private string $filter = '';
    private bool $filtering = false;
    private ?Overlay $list = null;
    /** The list of a dropdown: one click picks. */
    private bool $pickOnClick = false;

    /**
     * @param array<int|string, string|list<string>> $items a label or the cells of each item, by key
     * @param array<string, int|string|array{width?: int|string, align?: string, sortable?: bool}> $columns
     *        column titles and sizes for items with cells (see `Table`); none: no header
     * @param bool $multiple    several items can be marked (Space)
     * @param bool $filterable  typing filters the items
     * @param bool $dropdown    a one-line field that opens the list in an overlay
     * @param (\Closure(int|string $key, Select $select): void)|null $onSelect   the current item changed
     * @param (\Closure(int|string $key, Select $select): void)|null $onActivate Enter or a click on the current item
     * @param (\Closure(list<int|string> $keys, Select $select): void)|null $onChange the marked items changed
     */
    public function __construct(
        array $items = [],
        array $columns = [],
        public bool $multiple = false,
        public bool $filterable = true,
        private readonly ?\Closure $onSelect = null,
        private readonly ?\Closure $onActivate = null,
        private readonly ?\Closure $onChange = null,
        public bool $dropdown = false,
    ) {
        parent::__construct($columns, self::rows($items), $columns !== []);
    }

    /** @return array<int|string, list<string>> */
    private static function rows(array $items): array
    {
        return array_map(fn($item) => \is_array($item) ? array_values(array_map('strval', $item)) : [str_replace(["\r", "\n"], ' ', (string) $item)], $items);
    }

    /**
     * Replace the items (labels or cells by key). The current and the marked items stay
     * (by key) where they still are.
     *
     * @param array<int|string, string|list<string>> $items
     */
    public function setItems(array $items): void
    {
        $this->rows = self::rows($items);
        $this->propsChanged();
        $this->requestRender();
    }

    protected function propsChanged(): void
    {
        $before = $this->marked;
        $this->marked = array_intersect_key($this->marked, $this->rows);
        if ($this->marked !== $before) {
            $this->call($this->onChange, $this->selection(), $this);
        }
        if ($this->currentKey !== null && !isset($this->rows[$this->currentKey])) {
            // the current item is gone: the one at its place
            $this->moveTo($this->currentAt);
        }
    }

    /** The key of the current item, or null when no item is shown. */
    public function selected(): int|string|null
    {
        return $this->current();
    }

    /**
     * The marked items' keys in the order of the items (with `multiple`); without `multiple`
     * the current item's, or none.
     *
     * @return list<int|string>
     */
    public function selection(): array
    {
        if (!$this->multiple) {
            $current = $this->current();
            return $current === null ? [] : [$current];
        }
        return array_values(array_filter(array_keys($this->rows), fn($k) => isset($this->marked[$k])));
    }

    /**
     * Mark exactly these items (with `multiple`; without it the first becomes current).
     *
     * @param list<int|string> $keys
     */
    public function setSelection(array $keys): void
    {
        if (!$this->multiple) {
            $keys === [] || $this->setCurrent($keys[0]);
            return;
        }
        $this->marked = array_fill_keys(array_values(array_filter($keys, fn($k) => isset($this->rows[$k]))), true);
        $this->call($this->onChange, $this->selection(), $this);
        $this->requestRender();
    }

    /** The value in a `Form`: the current item's key, or with `multiple` the marked items' keys. */
    public function value(): mixed
    {
        return $this->multiple ? $this->selection() : $this->selected();
    }

    /** True while typed text goes into the filter (see the class description). */
    public function isFiltering(): bool
    {
        return $this->filtering;
    }

    /** What was typed to filter the items ('' for none). */
    public function filter(): string
    {
        return $this->filter;
    }

    /** Filter the items by $text (as typing it does); '' shows all. */
    public function setFilter(string $text): void
    {
        $current = $this->current();
        $this->filter = $text;
        $shown = $this->shown();
        if ($shown !== [] && !\in_array($current, $shown, true)) {
            $this->moveTo(0);
        }
        $this->requestRender();
    }

    protected function bindings(): array
    {
        if ($this->dropdown) {
            return ['enter' => 'open', 'space' => 'open', 'down' => 'open', 'alt+down' => 'open'];
        }
        return [
            ...parent::bindings(),
            'enter' => 'activate',
            'space' => 'toggle',
            'alt+a' => 'markAll',
            'backspace' => 'filterBack',
            'escape' => 'clearFilter',
        ];
    }

    /**
     * Enter: activate the current item (`onActivate`). While filtering with `multiple`, end
     * the filtering instead (the filter stays). False when no item is shown.
     */
    public function activate(): bool
    {
        if ($this->filtering && $this->multiple) {
            $this->filtering = false;
            $this->requestRender();
            return true;
        }
        $current = $this->current();
        if ($current === null) {
            return false;
        }
        $this->filtering = false;
        $this->call($this->onActivate, $current, $this);
        return true;
    }

    /** Space: while filtering, a space in the filter; else mark or unmark the current item (false without `multiple`). */
    public function toggle(): bool
    {
        if ($this->filtering) {
            $this->setFilter($this->filter . ' ');
            return true;
        }
        $current = $this->current();
        if (!$this->multiple || $current === null) {
            return false;
        }
        if (isset($this->marked[$current])) {
            unset($this->marked[$current]);
        } else {
            $this->marked[$current] = true;
        }
        $this->call($this->onChange, $this->selection(), $this);
        return true;
    }

    /** Mark every item shown (with `multiple`). */
    public function markAll(): bool
    {
        if (!$this->multiple) {
            return false;
        }
        $this->setSelection([...$this->selection(), ...$this->shown()]);
        return true;
    }

    /** Take the last character off the filter; false when there is no filter. */
    public function filterBack(): bool
    {
        if ($this->filter === '') {
            return false;
        }
        $this->setFilter(mb_substr($this->filter, 0, -1));
        return true;
    }

    /** Escape: clear the filter and end the filtering; false when there is neither. */
    public function clearFilter(): bool
    {
        if ($this->filter === '' && !$this->filtering) {
            return false;
        }
        $this->filtering = false;
        $this->setFilter('');
        return true;
    }

    protected function onKey(Key $key): bool
    {
        if (!$this->filterable || !$key->isText()) {
            return false;
        }
        if ($this->dropdown) {
            $this->open();
            $this->list()?->onKey($key);
            return true;
        }
        if (!$this->filtering) {
            $this->filtering = true;
            if ($key->text === '/') {
                $this->requestRender();
                return true;
            }
        }
        $this->setFilter($this->filter . $key->text);
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Dropdown

    /** Open the list of a dropdown (see the class description); false when it is not one or is open already. */
    public function open(): bool
    {
        if (!$this->dropdown || $this->list?->isOpen() || !$this->isMounted()) {
            return false;
        }
        $list = new Select(
            $this->rows,
            $this->columns,
            multiple: $this->multiple,
            filterable: $this->filterable,
            onActivate: function (int|string $key): void {
                $this->setCurrent($key);
                $this->list?->close($key);
                $this->call($this->onActivate, $key, $this);
            },
            onChange: fn(array $keys) => $this->setSelection($keys),
        );
        $list->pickOnClick = true;
        $list->setSelection($this->selection());
        [$natural] = $list->measure(\PHP_INT_MAX >> 8, 1);
        $list->with(width: max($this->surface->cols(), $natural));
        $this->list = $this->overlay($list, place: 'below');
        if ($this->current() !== null) {
            $list->setCurrent($this->current());
        }
        return true;
    }

    /** True while the list of a dropdown is open. */
    public function isOpen(): bool
    {
        return $this->list?->isOpen() ?? false;
    }

    /** The open list of a dropdown (a `Select`), or null. */
    public function list(): ?Select
    {
        $c = $this->list?->isOpen() ? $this->list->content : null;
        return $c instanceof Select ? $c : null;
    }

    /** Close the list of a dropdown, unchanged. */
    public function close(): void
    {
        $this->list?->close();
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if (!$this->dropdown) {
            return parent::onMouse($mouse);
        }
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $this->isOpen() ? $this->close() : $this->open();
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        if (!$this->dropdown) {
            return parent::measure($cols, $rows);
        }
        [$w] = parent::measure($cols, $rows);
        return [min($cols, $w + 2), 1];
    }

    protected function render(): null
    {
        if (!$this->dropdown) {
            return parent::render();
        }
        $cols = $this->surface->cols();
        $current = $this->current();
        $label = $current === null ? '' : implode(' ', $this->rows[$current]);
        $style = Ansi::style($this->style()->text);
        $this->surface->write("\e[?25l\e[2J\e[H" . $style . Text::truncate($label, max(0, $cols - 2)) . Ansi::moveTo(max(0, $cols - 1), 0) . $style . ($this->isOpen() ? '▴' : '▾'));
        return null;
    }

    protected function shown(): array
    {
        $keys = parent::shown();
        if ($this->filter === '') {
            return $keys;
        }
        $needle = mb_strtolower($this->filter);
        return array_values(array_filter($keys, fn($k) => str_contains(mb_strtolower(self::plain(implode(' ', $this->rows[$k]))), $needle)));
    }

    protected function currentChanged(int|string $key): void
    {
        $this->call($this->onSelect, $key, $this);
    }

    protected function clicked(int|string $key): void
    {
        if ($this->pickOnClick && !$this->multiple) {
            $this->setCurrent($key);
            $this->activate();
            return;
        }
        $key === $this->current() ? $this->activate() : $this->setCurrent($key);
    }

    protected function rowStates(int|string $key, bool $current): array
    {
        $states = parent::rowStates($key, $current);
        if ($this->multiple ? isset($this->marked[$key]) : $current) {
            $states[] = 'selected';
        }
        return $states;
    }

    protected function footerRows(): int
    {
        return $this->filter === '' && !$this->filtering ? 0 : 1;
    }

    protected function footer(int $y): string
    {
        if ($this->footerRows() === 0) {
            return '';
        }
        $line = Text::truncate('/' . $this->filter, $this->surface->cols());
        return Ansi::moveTo(0, min($y, $this->surface->rows() - 1)) . Ansi::style($this->style('filter')->text) . $line . "\e[0m";
    }
}
