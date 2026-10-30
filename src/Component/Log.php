<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Stack;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * Append-only text with scrollback that follows the newest line, for logs and chat histories.
 *
 *     $log = $this->child('log', fn() => new Log());
 *     $log->append("server started\n");
 *     $log->append("\e[31merror\e[0m: disk full\n");    // escape sequences style the text
 *
 * The text wraps at the component's width and older lines go into the scrollback (`$scrollback`
 * lines are kept). The view follows the end; the mouse wheel, `scrollBy()` or `scrollTo()` look
 * back, and it stays on the same lines while more text arrives until `follow()` (or scrolling
 * back to the end). Dragging the mouse selects and copies text, as in any window.
 */
final class Log extends Component
{
    private Surface $content;

    /** @param int $scrollback how many lines are kept above the visible ones */
    public function __construct(private readonly int $scrollback = 10000)
    {
    }

    protected function mount(): void
    {
        // the text lives in a surface of its own, shown through a viewport that scrolls it
        $this->content = new Surface($this->surface->cols(), $this->surface->rows(), $this->scrollback);
        $this->content->write("\e[?25l");
        $this->surface->setLayout((new Stack())->add($this->content));
    }

    /**
     * Add text at the end. Line breaks ("\n") start new lines; text without one continues the
     * current line. Escape sequences in it (colors) work as in a terminal.
     */
    public function append(string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->content->write(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text)));
    }

    /** Remove all text, scrollback included. */
    public function clear(): void
    {
        $this->content->write("\e[0m\e[3J\e[2J\e[H");
        $this->view()->follow();
    }

    /** Scroll by $rows: negative looks back, positive forward. */
    public function scrollBy(int $rows): void
    {
        $this->view()->scrollBy($rows);
    }

    /** Show from line $row at the top: 0 is the first visible row, negative rows are older. */
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

    /**
     * Every line, oldest first, as plain text (wrapped lines joined back together).
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $c = $this->content;
        $last = $c->rows() - 1;
        while ($last >= 0 && $c->line($last) === '' && !($last > 0 && $c->isWrapped($last - 1))) {
            $last--;
        }
        $lines = [];
        $current = '';
        for ($y = -$c->scrollbackSize(); $y <= $last; $y++) {
            $current .= $c->line($y);
            if (!$c->isWrapped($y)) {
                $lines[] = $current;
                $current = '';
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    private function view(): Viewport
    {
        return $this->surface->viewport($this->content);
    }
}
