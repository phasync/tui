<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Stack;
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
 * The view follows the end; the mouse wheel, `scrollToLine()` or `scrollBy()` look back, and it
 * stays on the same lines while more text arrives until `follow()` (or scrolling back to the
 * end). Dragging the mouse selects and copies text, as in any window. A view scrolled back stays
 * on the same line when the log is laid out again (a resize, `setLines()`).
 *
 * **Lines, not rows.** Lines are numbered as in `lines()` (0 the oldest kept); a long line takes
 * several rows of the window, depending on the width. `scrollToLine()`, `topLine()` and
 * `bottomLine()` count lines, so "go to line N" and "line X of Y" need no knowledge of the
 * layout; `scrollBy()`/`scrollTo()` count rows, for paging by the window's height:
 *
 *     $log->scrollToLine($n);
 *     $status->set(sprintf('line %d of %d', $log->topLine() + 1, count($log->lines())));
 *
 * **Filtering and editing.** To change lines already shown (filter them, re-style the selected
 * message, re-sort), keep the full list yourself and give what should show to `setLines()`: it
 * replaces the text in one go and lays it out once. A view following the end keeps following; a
 * view scrolled back stays on the line at its top if that line is still there, else it goes to
 * the end:
 *
 *     $log->setLines(array_values(array_filter($all, fn($l) => str_contains($l, $query))));
 */
final class Log extends Component
{
    /** Lines laid out at once on a resize; the rest of the scrollback follows a moment later. */
    private const QUICK_ROWS = 200;

    private Surface $content;
    /** @var list<string> complete lines (each ended by "\n" when given) */
    private array $lines = [];
    /** The last line, not ended yet. */
    private string $open = '';
    /** Rows the open line takes, in word wrap mode. */
    private int $openRows = 1;
    private bool $fullLayoutPending = false;
    /** The width the text on the content surface was laid out for. */
    private int $laidCols = 80;

    /**
     * @param int  $scrollback how many lines are kept (older ones are dropped), and how many rows
     *                         above the visible ones the view can scroll back
     * @param bool $wordWrap   wrap long lines between words instead of at any character
     */
    public function __construct(private readonly int $scrollback = 10000, private bool $wordWrap = false)
    {
    }

    protected function mount(): void
    {
        // the text is laid out in a surface of its own, shown through a viewport that scrolls it
        $this->content = new Surface($this->surface->cols(), $this->surface->rows(), $this->scrollback);
        $this->surface->setLayout((new Stack())->add($this->content));
        $this->content->on('resize', fn() => $this->layout(self::QUICK_ROWS, $this->pinnedLine()));
        $this->layout();
    }

    /**
     * Add text at the end. Line breaks ("\n") end lines; text without one continues the last
     * line. Escape sequences in it (colors) work as in a terminal.
     */
    public function append(string $text): void
    {
        if ($text === '') {
            return;
        }
        $pieces = explode("\n", str_replace("\r\n", "\n", $text));
        $first = array_shift($pieces);
        $out = '';
        if ($first !== '') {
            if ($this->wordWrap) {
                // the open line is laid out again with what it gets
                if ($this->openRows - 1 >= $this->content->rows()) {
                    $top = $this->pinnedLine();
                    $this->open .= $first;
                    $dropped = $this->add($pieces);
                    $this->layout(null, $top === null || $top < $dropped ? null : $top - $dropped);
                    return;
                }
                $out .= "\r" . ($this->openRows > 1 ? "\e[" . ($this->openRows - 1) . 'A' : '') . "\e[J";
                $this->open .= $first;
                $out .= $this->rowsOf($this->open);
            } else {
                $this->open .= $first;
                $out .= $first;
            }
        }
        foreach ($pieces as $piece) {
            $out .= "\r\n" . ($this->wordWrap ? $this->rowsOf($piece) : $piece);
        }
        $this->add($pieces);
        if ($this->wordWrap) {
            $this->openRows = \count(Text::wrap($this->open, $this->content->cols(), true));
        }
        $this->content->write($out);
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
     * following; one scrolled back stays on the line at its top (the same text) when it is among
     * the new lines, and follows the end when it is not.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $top = $this->pinnedLine();
        $text = $top === null ? null : ([...$this->lines, $this->open][$top] ?? null);
        $this->lines = \array_slice(array_values(array_map('strval', $lines)), -max(1, $this->scrollback - 1));
        $this->open = '';
        $found = $text === null ? false : array_search($text, $this->lines, true);
        $this->layout(null, $found === false ? null : $found);
        if ($found === false) {
            $this->view()->follow();
        }
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
        if ($wordWrap !== $this->wordWrap) {
            $top = $this->pinnedLine();
            $this->wordWrap = $wordWrap;
            $this->layout(null, $top);
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
        $rows = $this->lineRows($this->laidCols);
        $line = max(0, min($line, $last));
        $this->view()->scrollTo($rows[$line] ?? $rows[array_key_last($rows)]);
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

    /**
     * Scroll by $rows of the laid-out text (a long line takes several rows): negative looks back,
     * positive forward. For scrolling by the window's height (Page Up/Down); to go to a line use
     * `scrollToLine()`.
     */
    public function scrollBy(int $rows): void
    {
        $this->view()->scrollBy($rows);
    }

    /**
     * Show from row $row of the laid-out text at the top: 0 is the top row of the window when it
     * follows the end, negative rows are older. Rows depend on the width and the wrapping; to
     * address text use `scrollToLine()`, `topLine()` and `bottomLine()`, which count lines.
     */
    public function scrollTo(int $row): void
    {
        $this->view()->scrollTo($row);
    }

    /** Follow the end again. */
    public function follow(): void
    {
        $this->view()->follow();
    }

    /** True while the view follows the end. */
    public function isFollowing(): bool
    {
        return $this->view()->isFollowing();
    }

    /** $line laid out for the content's width, rows joined with "\r\n" (word wrap mode). */
    private function rowsOf(string $line): string
    {
        return implode("\r\n", Text::wrap($line, $this->content->cols(), true));
    }

    /** How many rows $line takes at $cols columns. */
    private function rowCount(string $line, int $cols): int
    {
        return $this->wordWrap ? max(1, \count(Text::wrap($line, $cols, true))) : max(1, (int) ceil(Text::width($line) / max(1, $cols)));
    }

    /**
     * The first row on the content surface of each line, as laid out for $cols: the lines and the
     * open line after them end on the cursor's row. Keys are indexes into lines + [open].
     *
     * @return array<int, int>
     */
    private function lineRows(int $cols): array
    {
        $seq = [...$this->lines, $this->open];
        $starts = [];
        $end = $this->content->cursor()->y;
        for ($k = \count($seq) - 1; $k >= 0 && $end >= -$this->scrollback; $k--) {
            $starts[$k] = $end - $this->rowCount($seq[$k], $cols) + 1;
            $end = $starts[$k] - 1;
        }
        return $starts;
    }

    /** The line at the top of a view scrolled back (an index into lines + [open]), or null when following. */
    private function pinnedLine(): ?int
    {
        $view = $this->view();
        if ($view === null || $view->isFollowing()) {
            return null;
        }
        $top = $view->top();
        foreach ($this->lineRows($this->laidCols) as $k => $start) {
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
        $rows = $this->lineRows($this->laidCols);       // newest first
        foreach ($rows as $k => $start) {
            if ($start <= $row) {
                return min($k, $last);
            }
        }
        return min((int) array_key_last($rows), $last);
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
        $all = $this->lines();
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
            $shown = array_map($this->rowsOf(...), $shown);
        }
        $out .= implode("\r\n", $shown);
        if ($this->open === '' && $all !== []) {
            $out .= "\r\n";     // the cursor waits on the row after the last ended line
        }
        $this->openRows = $this->wordWrap ? \count(Text::wrap($this->open, $cols, true)) : 1;
        $c->write($out);
        $this->laidCols = $cols;
        if ($view !== null && !$view->isFollowing()) {
            $row = $top === null ? null : ($this->lineRows($cols)[$top] ?? null);
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
            }
        });
    }

    private function view(): ?Viewport
    {
        return $this->surface->viewport($this->content);
    }
}
