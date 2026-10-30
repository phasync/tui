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
 * The view follows the end; the mouse wheel, `scrollBy()` or `scrollTo()` look back, and it
 * stays on the same lines while more text arrives until `follow()` (or scrolling back to the
 * end). Dragging the mouse selects and copies text, as in any window. To change lines already
 * shown (re-style the selected message, filter, re-sort), give the new lines to `setLines()`:
 * they are laid out once.
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
        $this->content->on('resize', fn() => $this->layout(self::QUICK_ROWS));
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
                    $this->open .= $first;
                    $this->add($pieces);
                    $this->layout();
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

    /** @param list<string> $pieces what followed the first "\n" of an append */
    private function add(array $pieces): void
    {
        foreach ($pieces as $piece) {
            $this->lines[] = $this->open;
            $this->open = $piece;
        }
        $excess = \count($this->lines) + 1 - $this->scrollback;
        if ($excess > 0) {
            array_splice($this->lines, 0, $excess);
        }
    }

    /**
     * Replace all text with $lines (each a line, without "\n"), laid out once: to re-style, edit
     * or filter what is shown. The view follows the end again.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = \array_slice(array_values(array_map('strval', $lines)), -max(1, $this->scrollback - 1));
        $this->open = '';
        $this->layout();
        $this->view()->follow();
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
            $this->wordWrap = $wordWrap;
            $this->layout();
        }
    }

    /** True if long lines wrap between words. */
    public function wordWrap(): bool
    {
        return $this->wordWrap;
    }

    /** Scroll by $rows: negative looks back, positive forward. */
    public function scrollBy(int $rows): void
    {
        $this->view()->scrollBy($rows);
    }

    /** Show from row $row at the top: 0 is the first visible row, negative rows are older. */
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

    /**
     * Write the lines into the content surface again, for its width: all of them that fit in
     * its scrollback, or with $rows only about that many rows at the end now (after a resize,
     * so the visible part is right at once) and the rest in a moment.
     */
    private function layout(?int $rows = null): void
    {
        $c = $this->content;
        $cols = $c->cols();
        $capacity = $c->rows() + $this->scrollback;
        if ($rows !== null && $rows < $capacity) {
            $capacity = max($rows, $c->rows());
            $this->layoutLater();
        }
        $view = $this->view();
        $following = $view === null || $view->isFollowing();
        $top = $view?->top() ?? 0;
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
        if ($view !== null) {
            $following ? $view->follow() : $view->scrollTo($top);
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
                $this->layout();
            }
        });
    }

    private function view(): ?Viewport
    {
        return $this->surface->viewport($this->content);
    }
}
