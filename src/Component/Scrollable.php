<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Mouse;

/**
 * Scrolling for a component that draws content taller than itself (a document, a list, a log):
 * the position, the keys, the wheel, following the end as content arrives, the `scroll` event
 * and a scrollbar, the same in every widget that uses it (`Log`, `Markdown`).
 *
 *     final class Lines extends Component
 *     {
 *         use Scrollable;
 *
 *         public function __construct(private array $lines = [])
 *         {
 *             $this->followTail();                     // stay at the end while lines arrive
 *         }
 *
 *         protected function contentRows(): int
 *         {
 *             return count($this->lines);
 *         }
 *
 *         protected function bindings(): array
 *         {
 *             return $this->scrollBindings();          // Up/Down, Page Up/Down, Home/End
 *         }
 *
 *         protected function onMouse(Mouse $mouse): bool
 *         {
 *             return $this->scrollWheel($mouse);
 *         }
 *
 *         protected function render(): null
 *         {
 *             $out = "\e[2J";
 *             foreach (array_slice($this->lines, $this->scrollTop(), $this->viewRows()) as $y => $line) {
 *                 $out .= Ansi::moveTo(0, $y) . $line;
 *             }
 *             $this->surface->write($out . $this->scrollbar($this->surface->cols() - 1));
 *             $this->scrolled();                       // the `scroll` event, when the view changed
 *             return null;
 *         }
 *     }
 *
 * **Rows** count from 0, the first row of the content; the view shows `viewRows()` of them from
 * `scrollTop()`. Every move is clamped to the content and renders the component again.
 *
 * **Following the end** (`followTail()`): the view sticks to the end while content arrives, until
 * the user scrolls up; scrolling back to the end (End, the wheel, `scrollTo()`) or `follow()`
 * sticks again.
 *
 * **Keys and the wheel**: `scrollBindings()` gives Up/Down a row, Page Up/Page Down a page (the
 * height less one row), Home/End the ends; each returns false when it could not move, so the key
 * goes on (to the next field, to the parent). `scrollWheel()` scrolls three rows a click.
 *
 * **The `scroll` event** (with the component): `scrolled()` emits it when the rows in view or
 * whether it follows the end changed since it was emitted last. Call it after drawing (and
 * after content arrived); the moves here call it.
 *
 * **The scrollbar**: `scrollbar($x)` draws one in column `$x`, the height of the view: the track
 * in the part style `::scrollbar`, the thumb in `::scrollbar:thumb` (the default theme: muted and
 * accent); nothing when all the content shows.
 *
 * A component that keeps its position elsewhere (`Log` scrolls a viewport) overrides
 * `scrollState()` and `setScrollState()`.
 */
trait Scrollable
{
    private int $scrollTop = 0;
    private bool $scrollTail = false;
    private bool $scrollFollowing = false;
    /** @var array{int,int,bool}|null the rows in view and the following when `scroll` was emitted last */
    private ?array $scrollShown = null;

    /** How many rows the content has (at the width the component has now). */
    abstract protected function contentRows(): int;

    /** How many rows of it the component shows at once: by default its height. */
    protected function viewRows(): int
    {
        return max(1, $this->surface->rows());
    }

    /**
     * Follow the end (or stop, with false): the view stays at the end while content arrives,
     * until the user scrolls up (see the trait's description). Call it in the constructor or
     * `mount()`, or when a prop says so.
     */
    protected function followTail(bool $on = true): void
    {
        if (!$on && $this->scrollFollowing && $this->isMounted()) {
            $this->scrollTop = $this->scrollTop();      // it stays where it is
        }
        $this->scrollTail = $on;
        $this->scrollFollowing = $on;
    }

    /** The row of the content at the top of the view. */
    public function scrollTop(): int
    {
        [$top, $following] = $this->scrollState();
        return $following ? $this->maxScrollTop() : max(0, min($top, $this->maxScrollTop()));
    }

    /** True while the view follows the end (see `followTail()`). */
    public function isFollowing(): bool
    {
        return $this->scrollState()[1];
    }

    /** Follow the end again, after the user scrolled away from it (with `followTail()`; nothing without). */
    public function follow(): void
    {
        if ($this->scrollTail && !$this->isFollowing()) {
            $this->setScrollState($this->maxScrollTop(), true);
            $this->scrolled();
        }
    }

    /**
     * Show content row $row at the top (clamped); at the end, the view follows it again (with
     * `followTail()`). False when the view did not move.
     */
    public function scrollTo(int $row): bool
    {
        $before = [$this->scrollTop(), $this->isFollowing()];
        $max = $this->maxScrollTop();
        $row = max(0, min($row, $max));
        $this->setScrollState($row, $this->scrollTail && $row >= $max);
        $this->scrolled();
        return [$this->scrollTop(), $this->isFollowing()] !== $before;
    }

    /** Scroll by $rows (negative: up); false when it could not move. */
    public function scrollBy(int $rows): bool
    {
        return $this->scrollTo($this->scrollTop() + $rows);
    }

    /** Up a row (Up). */
    public function lineUp(): bool
    {
        return $this->scrollBy(-1);
    }

    /** Down a row (Down). */
    public function lineDown(): bool
    {
        return $this->scrollBy(1);
    }

    /** Up a page: the view's height less one row (Page Up). */
    public function pageUp(): bool
    {
        return $this->scrollBy(-max(1, $this->viewRows() - 1));
    }

    /** Down a page (Page Down). */
    public function pageDown(): bool
    {
        return $this->scrollBy(max(1, $this->viewRows() - 1));
    }

    /** To the first row (Home). */
    public function toTop(): bool
    {
        return $this->scrollTo(0);
    }

    /** To the end (End); with `followTail()`, following it again. */
    public function toBottom(): bool
    {
        return $this->scrollTo($this->maxScrollTop());
    }

    /**
     * The keys of scrolling, for `bindings()`: `[...$this->scrollBindings(), 'q' => 'close']`.
     *
     * @return array<string, string>
     */
    protected function scrollBindings(): array
    {
        return [
            'up' => 'lineUp', 'down' => 'lineDown', 'pageup' => 'pageUp', 'pagedown' => 'pageDown',
            'home' => 'toTop', 'end' => 'toBottom',
        ];
    }

    /** The wheel, for `onMouse()`: three rows a click; true when $mouse was a wheel turn. */
    protected function scrollWheel(Mouse $mouse): bool
    {
        if ($mouse->action !== Mouse::WHEEL_UP && $mouse->action !== Mouse::WHEEL_DOWN) {
            return false;
        }
        $this->scrollBy($mouse->action === Mouse::WHEEL_UP ? -3 : 3);
        return true;
    }

    /** Emit `scroll` (with the component) if the rows in view or the following changed since it was emitted last. */
    protected function scrolled(): void
    {
        $top = $this->scrollTop();
        $shown = [$top, min($this->contentRows(), $top + $this->viewRows()) - 1, $this->isFollowing()];
        if ($shown !== $this->scrollShown) {
            $this->scrollShown = $shown;
            $this->emit('scroll', $this);
        }
    }

    /**
     * Escape sequences that draw the scrollbar in column $x of the component's surface, from its
     * top row down the height of the view: the thumb as large as the part in view, where it is
     * (part styles `::scrollbar` and `::scrollbar:thumb`). Blank when all the content shows.
     */
    protected function scrollbar(int $x): string
    {
        $view = $this->viewRows();
        $rows = $this->contentRows();
        $out = "\e7\e[?6l";
        if ($rows <= $view) {
            for ($y = 0; $y < $view; $y++) {
                $out .= Ansi::moveTo($x, $y) . "\e[0m ";
            }
            return $out . "\e8";
        }
        $thumb = max(1, (int) round($view * $view / $rows));
        $at = (int) round($this->scrollTop() / max(1, $rows - $view) * ($view - $thumb));
        $track = Ansi::style($this->style('scrollbar')->text);
        $bar = Ansi::style($this->style('scrollbar', 'thumb')->text);
        for ($y = 0; $y < $view; $y++) {
            $out .= Ansi::moveTo($x, $y) . ($y >= $at && $y < $at + $thumb ? $bar . '┃' : $track . '│');
        }
        return $out . "\e[0m\e8";
    }

    /**
     * Where the view is: [the top row, following the end]. Override with `setScrollState()` to
     * keep the position somewhere else (a viewport that scrolls).
     *
     * @return array{int,bool}
     */
    protected function scrollState(): array
    {
        return [$this->scrollTop, $this->scrollFollowing];
    }

    /** Put the view at $top (clamped already), following the end or not; renders the component. */
    protected function setScrollState(int $top, bool $following): void
    {
        $this->scrollTop = $top;
        $this->scrollFollowing = $following;
        $this->markDirty();
    }

    private function maxScrollTop(): int
    {
        return max(0, $this->contentRows() - $this->viewRows());
    }
}
