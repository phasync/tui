<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Key;
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
 * Enter activates the current item (as does a click on it). Typing filters: only the items
 * whose text contains what was typed (ignoring case) are shown, under them the filter line;
 * Backspace takes a character back, Escape clears it (an Escape with no filter goes on to the
 * parent). With `multiple`, Space marks or unmarks the current item and Alt-A marks all shown.
 *
 * **Callbacks**: `onSelect($key, $select)` when the current item changed, `onActivate($key,
 * $select)` on Enter or a click on the current item, `onChange($keys, $select)` when the
 * marked items changed (with `multiple`).
 *
 * **Style** parts: those of `Table`, with the row state `selected` for a marked item (and the
 * current one without `multiple`), and `Select::filter` for the filter line.
 */
final class Select extends Table
{
    /** @var array<int|string, true> the marked items */
    private array $marked = [];
    private string $filter = '';

    /**
     * @param array<int|string, string|list<string>> $items a label or the cells of each item, by key
     * @param array<string, int|string|array{width?: int|string, align?: string, sortable?: bool}> $columns
     *        column titles and sizes for items with cells (see `Table`); none: no header
     * @param bool $multiple    several items can be marked (Space)
     * @param bool $filterable  typing filters the items
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
        return [
            ...parent::bindings(),
            'enter' => 'activate',
            'space' => 'toggle',
            'alt+a' => 'markAll',
            'backspace' => 'filterBack',
            'escape' => 'clearFilter',
        ];
    }

    /** Activate the current item (`onActivate`). */
    public function activate(): bool
    {
        $current = $this->current();
        if ($current === null) {
            return false;
        }
        $this->call($this->onActivate, $current, $this);
        return true;
    }

    /** Mark or unmark the current item; false without `multiple` (Space then types a space into the filter). */
    public function toggle(): bool
    {
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

    /** Clear the filter; false when there is none. */
    public function clearFilter(): bool
    {
        if ($this->filter === '') {
            return false;
        }
        $this->setFilter('');
        return true;
    }

    protected function onKey(Key $key): bool
    {
        if (!$this->filterable || !$key->isText()) {
            return false;
        }
        $this->setFilter($this->filter . $key->text);
        return true;
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
        return $this->filter === '' ? 0 : 1;
    }

    protected function footer(int $y): string
    {
        if ($this->filter === '') {
            return '';
        }
        $line = Text::truncate('/' . $this->filter, $this->surface->cols());
        return Ansi::moveTo(0, min($y, $this->surface->rows() - 1)) . Ansi::style($this->style('filter')->text) . $line . "\e[0m";
    }
}
