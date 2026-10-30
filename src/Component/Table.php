<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Internal\Size;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * Rows of cells under a header, in columns with widths and alignment: a process list, query
 * results, a file listing.
 *
 *     $table = $this->child('procs', fn() => new Table(
 *         columns: ['PID' => ['width' => 7, 'align' => 'right'], 'Command' => '1fr', 'CPU%' => ['align' => 'right']],
 *         rows: [1201 => ['1201', 'php server.php', '3.2'], 88 => ['88', 'sshd', '0.0']],
 *     ));
 *     $table->rows = $fresh;       // (a prop: or place `new Table(...)` in the tree on every render)
 *
 * **Columns** by title, each a width or an array: `width` (cells, `'25%'`, `'1fr'` of what is
 * left, or `'auto'`, the default: as wide as its widest cell and title), `align` (`left`,
 * `right`, `center`) and `sortable` (default true). **Rows** by key (your ids: pids, file
 * names), each a list of cells in column order; a cell may carry SGR escape sequences for
 * colours. A cell wider than its column is cut with "…". When the columns do not fit, the
 * table scrolls sideways by column (Left/Right, the wheel sideways), the first column shown
 * at the left edge.
 *
 * **Keys**: Up/Down move the current row (Ctrl-P/Ctrl-N), Page Up/Page Down, Home/End,
 * Left/Right scroll the columns. **Mouse**: a click on a row makes it current, a click on a
 * column's title sorts by it (again: the other way), the wheel scrolls. `sortBy()` sorts from
 * code; sorting compares numbers as numbers, text without its escape sequences. The current
 * row stays the same row (by key) through new rows and sorting.
 *
 * **Style** parts: `Table::header` (state `sorted` for the sorted column's title),
 * `Table::row` (states `current`, and `selected` in a `Select`); the default theme shows the
 * current row inverted. **Events**: `scroll` with the table, when the rows in view changed.
 */
class Table extends Component
{
    /** @var int|string|null the key of the current row */
    protected int|string|null $currentKey = null;
    /** The position of the current row in the shown rows, as it was last seen. */
    protected int $currentAt = 0;
    /** The first row in view, as a position in the shown rows. */
    protected int $top = 0;
    /** The first column shown at the left edge. */
    protected int $left = 0;
    /** The wheel moved the view away from the current row; the next move brings it back. */
    private bool $free = false;
    private ?int $sortColumn = null;
    private bool $descending = false;
    /** @var array{int,int}|null the rows in view when `scroll` was emitted last */
    private ?array $inView = null;
    /** @var list<array{int,int}> the column positions drawn last (x, width), for clicks */
    private array $drawn = [];

    /**
     * @param array<string, int|string|array{width?: int|string, align?: string, sortable?: bool}> $columns by title, in order
     * @param array<int|string, list<string>> $rows the cells of each row by key, in order
     * @param bool $header show the titles as a header row
     */
    public function __construct(
        public array $columns = [],
        public array $rows = [],
        public bool $header = true,
    ) {
    }

    /** The key of the current row, or null when there are no rows (in view). */
    public function current(): int|string|null
    {
        $keys = $this->shown();
        if ($keys === []) {
            return null;
        }
        return \in_array($this->currentKey, $keys, true) ? $this->currentKey : $keys[0];
    }

    /** Make the row with key $key current and scroll it into view; false if there is no such row. */
    public function setCurrent(int|string $key): bool
    {
        if (!\in_array($key, $this->shown(), true)) {
            return false;
        }
        $this->moveTo(array_search($key, $this->shown(), true));
        return true;
    }

    /**
     * Sort the rows by the cells of column $column (its position, from 0), or back to the
     * order given with null.
     */
    public function sortBy(?int $column, bool $descending = false): void
    {
        $this->sortColumn = $column;
        $this->descending = $descending;
        $this->requestRender();
    }

    /** @return array{?int, bool} the column sorted by (null: as given) and whether descending */
    public function sorting(): array
    {
        return [$this->sortColumn, $this->descending];
    }

    /** The position (from 0) in the shown rows of the row at the top of the view. */
    public function topIndex(): int
    {
        return $this->top;
    }

    /** The position in the shown rows of the last row in view. */
    public function bottomIndex(): int
    {
        return max(0, min(\count($this->shown()), $this->top + $this->bodyRows()) - 1);
    }

    protected function bindings(): array
    {
        return [
            'up' => 'previous', 'ctrl+p' => 'previous', 'down' => 'next', 'ctrl+n' => 'next',
            'pageup' => 'pageUp', 'pagedown' => 'pageDown', 'home' => 'first', 'end' => 'last',
            'left' => 'scrollLeft', 'right' => 'scrollRight',
        ];
    }

    /** The row above becomes current; false on the first row (the key goes on). */
    public function previous(): bool
    {
        return $this->move(-1);
    }

    /** The row below becomes current; false on the last row. */
    public function next(): bool
    {
        return $this->move(1);
    }

    /** One screen of rows up. */
    public function pageUp(): void
    {
        $this->move(-max(1, $this->bodyRows() - 1));
    }

    /** One screen of rows down. */
    public function pageDown(): void
    {
        $this->move(max(1, $this->bodyRows() - 1));
    }

    /** The first row becomes current. */
    public function first(): void
    {
        $this->moveTo(0);
    }

    /** The last row becomes current. */
    public function last(): void
    {
        $this->moveTo(\count($this->shown()) - 1);
    }

    /** Show the columns from one further left; false when the first is shown. */
    public function scrollLeft(): bool
    {
        if ($this->left === 0) {
            return false;
        }
        $this->left--;
        return true;
    }

    /** Show the columns from one further right; false when the last ones are in view. */
    public function scrollRight(): bool
    {
        if ($this->left >= $this->lastLeft()) {
            return false;
        }
        $this->left++;
        return true;
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The keys of the rows shown, in the order shown: sorted, and (in a `Select`) filtered.
     *
     * @return list<int|string>
     */
    protected function shown(): array
    {
        $keys = array_keys($this->rows);
        if ($this->sortColumn === null) {
            return $keys;
        }
        $col = $this->sortColumn;
        $plain = [];
        foreach ($this->rows as $key => $cells) {
            $plain[$key] = self::plain((string) (array_values($cells)[$col] ?? ''));
        }
        usort($keys, function ($a, $b) use ($plain): int {
            $x = $plain[$a];
            $y = $plain[$b];
            $c = is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp($x, $y);
            return $c === 0 ? 0 : ($this->descending ? -$c : $c);
        });
        return $keys;
    }

    /** The cell text without escape sequences. */
    protected static function plain(string $text): string
    {
        return preg_replace('/\e\[[0-9;:?]*[A-Za-z]/', '', $text);
    }

    /** Move the current row by $n; false when it could not move at all. */
    private function move(int $n): bool
    {
        $keys = $this->shown();
        $at = array_search($this->current(), $keys, true);
        if ($keys === [] || $at === false || ($at + $n < 0 && $at === 0) || ($at + $n >= \count($keys) && $at === \count($keys) - 1)) {
            return false;
        }
        $this->moveTo($at + $n);
        return true;
    }

    /** Make the shown row at position $at current. */
    protected function moveTo(int $at): void
    {
        $keys = $this->shown();
        if ($keys === []) {
            return;
        }
        $this->free = false;
        $this->currentAt = max(0, min(\count($keys) - 1, $at));
        $key = $keys[$this->currentAt];
        $old = $this->current();
        $this->currentKey = $key;
        if ($old !== $key) {
            $this->currentChanged($key);
        }
        $this->requestRender();
    }

    /** The current row changed (to $key): for subclasses. */
    protected function currentChanged(int|string $key): void
    {
    }

    /** The rows the body has (the surface less the header and the lines a subclass adds). */
    protected function bodyRows(): int
    {
        return max(1, $this->surface->rows() - ($this->header ? 1 : 0) - $this->footerRows());
    }

    /** Rows under the body that a subclass draws (a filter line). */
    protected function footerRows(): int
    {
        return 0;
    }

    /**
     * The columns as [title, width spec, align, sortable].
     *
     * @return list<array{string, int|string, string, bool}>
     */
    private function spec(): array
    {
        $out = [];
        foreach ($this->columns as $title => $c) {
            $c = \is_array($c) ? $c : ['width' => $c];
            $out[] = [(string) $title, $c['width'] ?? 'auto', $c['align'] ?? 'left', $c['sortable'] ?? true];
        }
        if ($out === [] && $this->rows !== []) {
            foreach (array_values(reset($this->rows)) as $i => $_) {
                $out[] = ['', 'auto', 'left', false];
            }
        }
        return $out;
    }

    /**
     * The width of each column for $cols columns: fixed, shares and auto as given; when they do
     * not fit, shares get their title's width and the table scrolls sideways.
     *
     * @return list<int>
     */
    private function widths(int $cols): array
    {
        $spec = $this->spec();
        $sizes = [];
        foreach ($spec as $i => [$title, $width]) {
            if ($width === 'auto') {
                $w = Text::width($title) + ($this->sortColumn === $i ? 2 : 0);
                foreach ($this->rows as $cells) {
                    $w = max($w, Text::width((string) (array_values($cells)[$i] ?? '')));
                }
                $width = $w;
            }
            $sizes[] = Size::parse($width);
        }
        $room = max(0, $cols - max(0, \count($spec) - 1));
        $widths = Size::distribute($sizes, $room);
        foreach ($spec as $i => [$title, $width]) {
            if ($sizes[$i][0] === 'fr') {
                $widths[$i] = max($widths[$i], Text::width($title), 1);
            } elseif ($sizes[$i][0] === 'cells') {
                $widths[$i] = (int) $sizes[$i][1];   // fixed columns keep their width (the table scrolls)
            }
        }
        return $widths;
    }

    /** The largest first column that still leaves no blank on the right. */
    private function lastLeft(): int
    {
        $widths = $this->widths($this->surface->cols());
        $cols = $this->surface->cols();
        $used = 0;
        for ($i = \count($widths) - 1; $i >= 0; $i--) {
            $used += $widths[$i] + ($i < \count($widths) - 1 ? 1 : 0);
            if ($used > $cols) {
                return min(\count($widths) - 1, $i + 1);
            }
        }
        return 0;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, max(1, \count($this->shown()) + ($this->header ? 1 : 0) + $this->footerRows())];
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->isWheel()) {
            match ($mouse->action) {
                Mouse::WHEEL_LEFT => $this->scrollLeft(),
                Mouse::WHEEL_RIGHT => $this->scrollRight(),
                default => [$this->free, $this->top] = [true, max(0, min(max(0, \count($this->shown()) - $this->bodyRows()), $this->top + ($mouse->action === Mouse::WHEEL_UP ? -3 : 3)))],
            };
            return true;
        }
        if ($mouse->action !== Mouse::PRESS || $mouse->button !== Mouse::LEFT) {
            return $mouse->action === Mouse::RELEASE || $mouse->action === Mouse::DRAG;
        }
        $this->focus();
        if ($this->header && $mouse->y === 0) {
            foreach ($this->drawn as $i => [$x, $w]) {
                if ($mouse->x >= $x && $mouse->x < $x + $w && $this->spec()[$i][3]) {
                    $this->sortBy($i, $this->sortColumn === $i && !$this->descending);
                }
            }
            return true;
        }
        $at = $this->top + $mouse->y - ($this->header ? 1 : 0);
        if ($at >= 0 && $at < \count($this->shown()) && $mouse->y < ($this->header ? 1 : 0) + $this->bodyRows()) {
            $this->clicked($this->shown()[$at]);
        }
        return true;
    }

    /** A click on the row $key: it becomes current. */
    protected function clicked(int|string $key): void
    {
        $this->setCurrent($key);
    }

    /** @return list<string> the states of row $key for its style */
    protected function rowStates(int|string $key, bool $current): array
    {
        return $current ? ['current'] : [];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $keys = $this->shown();
        $body = $this->bodyRows();
        $current = $this->current();
        $at = $current === null ? null : array_search($current, $keys, true);
        if ($at !== null && $at !== false) {
            $this->currentAt = $at;
            if (!$this->free) {
                $this->top = min(max($this->top, $at - $body + 1), $at);
            }
        }
        $this->top = max(0, min($this->top, max(0, \count($keys) - $body)));
        $this->left = max(0, min($this->left, $this->lastLeft()));
        $spec = $this->spec();
        $widths = $this->widths($cols);
        $base = $this->style();
        $out = "\e[0m\e[?7l\e[?25l" . Ansi::style($base->text) . "\e[2J";
        // the columns in view
        $this->drawn = [];
        $x = 0;
        for ($i = $this->left; $i < \count($spec) && $x < $cols; $i++) {
            $this->drawn[$i] = [$x, min($widths[$i], $cols - $x)];
            $x += $widths[$i] + 1;
        }
        $y = 0;
        if ($this->header) {
            $cells = [];
            foreach ($this->drawn as $i => [, $w]) {
                $title = $spec[$i][0] . ($this->sortColumn === $i ? ($this->descending ? ' ▼' : ' ▲') : '');
                $style = $this->style('header', ...($this->sortColumn === $i ? ['sorted'] : []))->text;
                $cells[$i] = [Text::pad(Text::truncate($title, $w), $w, $spec[$i][2]), $style];
            }
            $out .= $this->line(0, $cells, $this->style('header')->text, $cols);
            $y = 1;
        }
        for ($r = 0; $r < $body && $this->top + $r < \count($keys); $r++) {
            $key = $keys[$this->top + $r];
            $row = array_values($this->rows[$key]);
            $style = $this->style('row', ...$this->rowStates($key, $key === $current))->text;
            $cells = [];
            foreach ($this->drawn as $i => [, $w]) {
                $cells[$i] = [Text::pad(Text::truncate((string) ($row[$i] ?? ''), $w), $w, $spec[$i][2]), $style];
            }
            $out .= $this->line($y + $r, $cells, $style, $cols);
        }
        $this->surface->write($out . $this->footer($y + $body) . "\e[0m\e[?7h");
        if ([$this->topIndex(), $this->bottomIndex()] !== $this->inView) {
            $this->inView = [$this->topIndex(), $this->bottomIndex()];
            $this->emit('scroll', $this);
        }
        return null;
    }

    /** What a subclass draws under the body, at row $y (escape sequences). */
    protected function footer(int $y): string
    {
        return '';
    }

    /**
     * One row: its cells in their styles, and the gaps and the rest of the row in $rowStyle
     * (so a highlighted row is highlighted across).
     *
     * @param array<int, array{string, Style}> $cells
     */
    private function line(int $y, array $cells, Style $rowStyle, int $cols): string
    {
        $out = Ansi::moveTo(0, $y) . Ansi::style($rowStyle) . str_repeat(' ', $cols) . Ansi::moveTo(0, $y);
        foreach ($cells as $i => [$text, $style]) {
            $set = Ansi::style($style);
            // the row's style over the cell's own colours: again after each of its SGRs
            $add = preg_replace('/^\e\[0;?/', "\e[", $set);
            $text = preg_replace('/\e\[[0-9;:]*m/', '$0' . ($add === "\e[m" ? '' : $add), $text);
            $out .= Ansi::moveTo($this->drawn[$i][0], $y) . $set . $text;
        }
        return $out . "\e[0m";
    }
}
