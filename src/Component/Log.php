<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Split;
use phasync\Tui\Surface;
use phasync\Tui\Text;
use phasync\Tui\Viewport;

/**
 * Lines of text with scrollback that follows the newest line, for logs and chat histories.
 *
 *     $log = $this->child('log', fn() => new Log(wordWrap: true));
 *     $log->append("server started\n");
 *     $log->append("\e[31merror\e[0m: disk full\n");    // escape sequences style the text
 *
 * The Log owns its text: it keeps the lines as they were given (`lines()`) and lays them out for
 * the width it has now, wrapping long lines by display width and grapheme (wide characters and
 * emoji take two cells), at any character or, with `$wordWrap`, between words. When it is
 * resized (also when it gets its first real size, after lines were already added) it lays them
 * out again, so nothing is cut off when the terminal narrows and nothing stays cropped when it
 * widens. The newest `$scrollback` lines are kept, and the view scrolls back `$scrollback` rows
 * (fewer lines than that when long lines take several rows).
 *
 * The view follows the end; the mouse wheel, the keys (Up/Down, Page Up/Page Down, Home/End,
 * when the Log has the focus), `scrollToLine()` or `scrollBy()` look back, and it stays on the
 * same lines while more text arrives until `follow()` (or scrolling back to the end). Scrolling
 * is that of every scrolling widget (`Scrollable`). Dragging the mouse selects and copies text,
 * as in any window. A view scrolled back stays on the same line when the log is laid out again
 * (a resize, `setLines()`). `scrollbar: true` shows a scrollbar in the last column.
 *
 * **Lines, not rows.** Lines are numbered as in `lines()` (0 the oldest kept); a long line takes
 * several rows of the window, depending on the width. `scrollToLine()`, `topLine()` and
 * `bottomLine()` count lines, so "go to line N" and "line X of Y" need no knowledge of the
 * layout; `scrollBy()`/`scrollTo()`/`scrollTop()` count rows (0 is the oldest row kept), for
 * paging by the window's height:
 *
 *     $log->scrollToLine($n);
 *     $status->set(sprintf('line %d of %d', $log->topLine() + 1, count($log->lines())));
 *
 * **Filtering and editing.** To change lines already shown (filter them, re-style the selected
 * message, re-sort), keep the full list yourself and give what should show to `setLines()`: it
 * replaces the text in one go and lays it out once. A view following the end keeps following; a
 * view scrolled back stays on the line at its top: on the same text when that line is still
 * there (filtering), else at the same line number (re-styling or editing lines in place):
 *
 *     $log->setLines(array_values(array_filter($all, fn($l) => str_contains($l, $query))));
 *
 * **Events.** `scroll` (see `Component::on()`), with the Log: the lines in view or whether it
 * follows the end changed (`topLine()`, `bottomLine()`, `isFollowing()`), by scrolling, by text
 * arriving while it follows, by `setLines()` or a resize. For a component that shows them:
 *
 *     $log->on('scroll', fn() => $this->requestRender());
 */
final class Log extends Component
{
    use Scrollable;

    /** Lines laid out at once on a resize; the rest of the scrollback follows a moment later. */
    private const QUICK_ROWS = 200;

    private Surface $content;
    /** The scrollbar's column, with `scrollbar: true`. */
    private ?Surface $bar = null;
    /** @var list<string> complete lines (each ended by "\n" when given) */
    private array $lines = [];
    /** The last line, not ended yet. */
    private string $open = '';
    /** Rows the open line takes, in word wrap mode. */
    private int $openRows = 1;
    private bool $fullLayoutPending = false;
    /** The width the text on the content surface was laid out for. */
    private int $laidCols = 80;
    /** @var array{int,int,bool}|null topLine, bottomLine, following when `scroll` was emitted last */
    private ?array $place = null;

    /**
     * @param int  $scrollback how many lines are kept (older ones are dropped), and how many rows
     *                         above the visible ones the view can scroll back
     * @param bool $wordWrap   wrap long lines between words instead of at any character
     * @param bool $scrollbar  show a scrollbar in the last column (part styles `Log::scrollbar`, `Log::scrollbar:thumb`)
     */
    public function __construct(private readonly int $scrollback = 10000, private bool $wordWrap = false, private readonly bool $scrollbar = false)
    {
        $this->followTail();
    }

    protected function mount(): void
    {
        // the text is laid out in a surface of its own, shown through a viewport that scrolls it
        $this->content = new Surface($this->surface->cols(), $this->surface->rows(), $this->scrollback);
        $split = Split::horizontal()->add($this->content);
        if ($this->scrollbar) {
            $this->bar = new Surface(1, $this->surface->rows(), 0);
            $this->bar->setFocusable(false);
            $split->add($this->bar, 1);
        }
        $this->surface->setLayout($split);
        $this->content->on('resize', function (): void {
            $this->layout(self::QUICK_ROWS, $this->pinnedLine());
            $this->scrolled();
        });
        $this->layout();
    }

    protected function bindings(): array
    {
        return $this->scrollBindings();
    }

    /** The wheel scrolls the text (by three rows), as it would any window. */
    protected function onMouse(Mouse $mouse): bool
    {
        return $this->scrollWheel($mouse);
    }

    /**
     * After every change of the view: the scrollbar, and the `scroll` event if the lines in view
     * or the following changed since it was emitted last.
     */
    protected function scrolled(): void
    {
        if ($this->view() === null) {
            return;
        }
        $this->bar?->write($this->scrollbar(0));
        if (!$this->hasListeners('scroll')) {
            return;
        }
        $place = [$this->topLine(), $this->bottomLine(), $this->isFollowing()];
        if ($place !== $this->place) {
            $this->place = $place;
            $this->emit('scroll', $this);
        }
    }

    protected function contentRows(): int
    {
        return $this->content->scrollbackSize() + $this->content->rows();
    }

    protected function viewRows(): int
    {
        return max(1, $this->view()?->innerHeight() ?? $this->content->rows());
    }

    /** The view is a viewport onto the content surface: its row 0 is the top row when following. */
    protected function scrollState(): array
    {
        $view = $this->view();
        return $view === null ? [0, true] : [$view->top() + $this->content->scrollbackSize(), $view->isFollowing()];
    }

    protected function setScrollState(int $top, bool $following): void
    {
        $view = $this->view();
        if ($view !== null) {
            $following ? $view->follow() : $view->scrollTo($top - $this->content->scrollbackSize());
        }
    }

    /**
     * Add text at the end. Line breaks ("\n") end lines; text without one continues the last
     * line. Markup in it (`<fg=red>error</>`, see `Markup`) is read per line, and escape
     * sequences (colors) work as in a terminal. `lines()` keeps the text as it was given.
     */
    public function append(string $text): void
    {
        if ($text === '') {
            return;
        }
        $pieces = explode("\n", str_replace("\r\n", "\n", $text));
        $first = array_shift($pieces);
        if (!isset($this->content)) {
            // not shown yet: kept, and laid out when it is
            $this->open .= $first;
            $this->add($pieces);
            return;
        }
        $out = '';
        if ($first !== '') {
            // (an open line with markup is written again whole, so a tag that closes later shows)
            if ($this->wordWrap || str_contains($this->open . $first, '<')) {
                // the open line is laid out again with what it gets
                if ($this->openRows - 1 >= $this->content->rows()) {
                    $top = $this->pinnedLine();
                    $this->open .= $first;
                    $dropped = $this->add($pieces);
                    $this->layout(null, $top === null || $top < $dropped ? null : $top - $dropped);
                    $this->scrolled();
                    return;
                }
                $out .= "\r" . ($this->openRows > 1 ? "\e[" . ($this->openRows - 1) . 'A' : '') . "\e[J";
                $this->open .= $first;
                $out .= $this->wordWrap ? $this->rowsOf($this->open) : $this->shown($this->open);
            } else {
                $this->open .= $first;
                $out .= $first;
            }
        }
        foreach ($pieces as $piece) {
            $out .= "\r\n" . ($this->wordWrap ? $this->rowsOf($piece) : $this->shown($piece));
        }
        $this->add($pieces);
        $this->openRows = $this->rowCount($this->open, $this->content->cols());
        $this->content->write($out);
        $this->scrolled();
    }

    /**
     * @param list<string> $pieces what followed the first "\n" of an append
     * @return int how many old lines were dropped to stay within the scrollback
     */
    private function add(array $pieces): int
    {
        foreach ($pieces as $piece) {
            $this->lines[] = $this->open;
            $this->open = $piece;
        }
        $excess = \count($this->lines) + 1 - $this->scrollback;
        if ($excess > 0) {
            array_splice($this->lines, 0, $excess);
        }
        return max(0, $excess);
    }

    /**
     * Replace all text with $lines (each a line, without "\n"), laid out once: the way to
     * filter, re-style, edit or re-sort what is shown. A view that follows the end keeps
     * following. One scrolled back stays on the line at its top: where the same text is in the
     * new lines (the nearest such line to where it was; so filtering keeps the place), else at
     * the same line number (so re-styling or editing lines in place keeps it too), the last
     * line when there are fewer lines now.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        if (!isset($this->content)) {
            $this->lines = \array_slice(array_values(array_map('strval', $lines)), -max(1, $this->scrollback - 1));
            $this->open = '';
            return;     // (laid out when it is shown)
        }
        $top = $this->pinnedLine();
        $text = $top === null ? null : ([...$this->lines, $this->open][$top] ?? null);
        $this->lines = \array_slice(array_values(array_map('strval', $lines)), -max(1, $this->scrollback - 1));
        $this->open = '';
        $line = null;
        if ($top !== null) {
            $line = max(0, min($top, \count($this->lines) - 1));
            if (($this->lines[$top] ?? null) !== $text) {
                $best = null;
                foreach (array_keys($this->lines, $text, true) as $k) {
                    if ($best === null || abs($k - $top) < abs($best - $top)) {
                        $best = $k;
                    }
                }
                $line = $best ?? $line;
            }
        }
        $this->layout(null, $line);
        $this->scrolled();
    }

    /** Remove all text, scrollback included. */
    public function clear(): void
    {
        $this->setLines([]);
    }

    /**
     * Every line, oldest first, as it was given (escape sequences included); a last line not
     * ended with "\n" yet is included.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->open === '' ? $this->lines : [...$this->lines, $this->open];
    }

    /** Wrap long lines between words (true) or at any character (false), and lay out again. */
    public function setWordWrap(bool $wordWrap): void
    {
        if ($wordWrap !== $this->wordWrap && !isset($this->content)) {
            $this->wordWrap = $wordWrap;
        } elseif ($wordWrap !== $this->wordWrap) {
            $top = $this->pinnedLine();
            $this->wordWrap = $wordWrap;
            $this->layout(null, $top);
            $this->scrolled();
        }
    }

    /** True if long lines wrap between words. */
    public function wordWrap(): bool
    {
        return $this->wordWrap;
    }

    /**
     * Show line $line (an index into `lines()`: 0 is the oldest line kept) at the top of the
     * view: "go to line N". The view stays there while more text arrives, until `follow()`;
     * near the end it shows the last lines and follows. Out-of-range numbers are clamped; a line
     * older than the scrollback rows can show (when long lines take several rows) brings the
     * oldest one that can.
     */
    public function scrollToLine(int $line): void
    {
        $last = \count($this->lines()) - 1;
        if ($last < 0) {
            return;
        }
        if ($this->fullLayoutPending) {
            $this->layout();        // the rows of every line, not only those laid out after a resize
        }
        $line = max(0, min($line, $last));
        $oldest = 0;
        foreach ($this->lineStarts($this->laidCols) as $k => $start) {
            $oldest = $start;
            if ($k <= $line) {
                break;
            }
        }
        $this->view()->scrollTo($oldest);
        $this->scrolled();
    }

    /**
     * The line (an index into `lines()`) at the top of the view, also when it follows the end;
     * for a long line that starts above the view, that line. With `bottomLine()` and
     * `count($log->lines())`: a "lines 120-160 of 300" indicator. 0 for an empty log.
     */
    public function topLine(): int
    {
        return $this->lineAt($this->view()->top());
    }

    /** The last line (an index into `lines()`) the view shows, at least in part. 0 for an empty log. */
    public function bottomLine(): int
    {
        $view = $this->view();
        return $this->lineAt($view->top() + max(1, $view->innerHeight()) - 1);
    }

    /** $line as it is shown: its markup turned into escape sequences. */
    private function shown(string $line): string
    {
        return str_contains($line, '<') ? Markup::toAnsi($line, null, $this->stylesheet()) : $line;
    }

    /** $line laid out for the content's width, rows joined with "\r\n" (word wrap mode). */
    private function rowsOf(string $line): string
    {
        return implode("\r\n", Text::wrap($this->shown($line), $this->content->cols(), true));
    }

    /** How many rows $line takes at $cols columns. */
    private function rowCount(string $line, int $cols): int
    {
        $line = $this->shown($line);
        return $this->wordWrap ? max(1, \count(Text::wrap($line, $cols, true))) : max(1, (int) ceil(Text::width($line) / max(1, $cols)));
    }

    /**
     * The first row on the content surface of each line, as laid out for $cols, newest line
     * first (stop early: the walk costs a line per step): the lines and the open line after them
     * end on the cursor's row. Keys are indexes into lines + [open].
     *
     * @return \Generator<int, int>
     */
    private function lineStarts(int $cols): \Generator
    {
        $end = $this->content->cursor()->y;
        for ($k = \count($this->lines); $k >= 0 && $end >= -$this->scrollback; $k--) {
            $start = $end - $this->rowCount($k === \count($this->lines) ? $this->open : $this->lines[$k], $cols) + 1;
            yield $k => $start;
            $end = $start - 1;
        }
    }

    /** The line at the top of a view scrolled back (an index into lines + [open]), or null when following. */
    private function pinnedLine(): ?int
    {
        $view = $this->view();
        if ($view === null || $view->isFollowing()) {
            return null;
        }
        $top = $view->top();
        foreach ($this->lineStarts($this->laidCols) as $k => $start) {
            if ($start <= $top) {
                return $k;
            }
        }
        return null;
    }

    /** The line (an index into `lines()`) that content row $row belongs to, the nearest one when none does. */
    private function lineAt(int $row): int
    {
        $last = max(0, \count($this->lines()) - 1);
        $oldest = 0;
        foreach ($this->lineStarts($this->laidCols) as $k => $start) {
            if ($start <= $row) {
                return min($k, $last);
            }
            $oldest = $k;
        }
        return min($oldest, $last);
    }

    /**
     * Write the lines into the content surface again, for its width: all of them that fit in
     * its scrollback, or with $rows only about that many rows at the end now (after a resize,
     * so the visible part is right at once) and the rest in a moment. A view scrolled back is
     * put on line $top (an index into lines + [open]) at the top, or left following the end.
     */
    private function layout(?int $rows = null, ?int $top = null): void
    {
        $c = $this->content;
        $cols = $c->cols();
        $capacity = $c->rows() + $this->scrollback;
        if ($rows !== null && $rows < $capacity) {
            $capacity = max($rows, $c->rows());
            $this->layoutLater();
        }
        $view = $this->view();
        // the lines that fit, from the end
        $all = array_map($this->shown(...), $this->lines());
        $first = \count($all);
        $count = 0;
        while ($first > 0 && $count < $capacity) {
            $line = $all[$first - 1];
            $count += $this->wordWrap ? \count(Text::wrap($line, $cols, true)) : max(1, (int) ceil(Text::width($line) / max(1, $cols)));
            $first--;
        }
        // styles set by lines left out still apply to the ones shown
        $out = "\e[0m\e[3J\e[H\e[2J\e[?25l";
        if ($first > 0 && preg_match_all('/\e\[[0-9;:]*m/', implode('', \array_slice($all, 0, $first)), $m)) {
            $out .= implode('', $m[0]);
        }
        $shown = \array_slice($all, $first);
        if ($this->wordWrap) {
            $shown = array_map(fn(string $line) => implode("\r\n", Text::wrap($line, $cols, true)), $shown);
        }
        $out .= implode("\r\n", $shown);
        if ($this->open === '' && $all !== []) {
            $out .= "\r\n";     // the cursor waits on the row after the last ended line
        }
        $this->openRows = $this->rowCount($this->open, $cols);
        $c->write($out);
        $this->laidCols = $cols;
        if ($view !== null && !$view->isFollowing()) {
            $row = null;
            foreach ($top === null ? [] : $this->lineStarts($cols) as $k => $start) {
                if ($k === $top) {
                    $row = $start;
                    break;
                }
            }
            $row === null ? $view->follow() : $view->scrollTo($row);
        }
    }

    /** Lay out the whole scrollback once things are quiet (several resizes give one layout). */
    private function layoutLater(): void
    {
        if ($this->fullLayoutPending || !$this->isMounted()) {
            return;
        }
        $this->fullLayoutPending = true;
        $this->go(function (): void {
            $this->sleep(0.1);
            $this->fullLayoutPending = false;
            if ($this->isMounted()) {
                $this->layout(null, $this->pinnedLine());
                $this->scrolled();
            }
        });
    }

    private function view(): ?Viewport
    {
        return $this->surface->viewport($this->content);
    }
}
