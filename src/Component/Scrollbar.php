<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;

/**
 * A scrollbar of its own, for a view you scroll yourself: a column (or a row) with a thumb as
 * large as the part in view, where it is.
 *
 *     new Row([$this->child('text', fn() => new Log()), new Scrollbar(total: 500, view: 20, position: $top,
 *         onScroll: fn(int $to) => $this->scrollTo($to))])
 *
 * `$total` is how many rows (columns, with `horizontal: true`) there are, `$view` how many show,
 * `$position` the first one shown; all three are props, so the owner passes the current ones on
 * every render. Nothing is drawn when everything shows. A click on the track moves the thumb
 * there (its middle to the click), a press on the thumb and a drag move it with the mouse, the
 * wheel scrolls three; each calls `onScroll($position, $scrollbar)` with the new first row and
 * emits `scroll`. It does not move by itself: the owner scrolls its view and passes the new
 * position (or reads `position()`, which follows the mouse). It takes no keys; the owner binds
 * them. One column wide (one row high) and the whole height (width) it is given.
 * (`Log`, `Markdown`, `Tree` and the others that scroll have their own.)
 *
 * **Style**: `Scrollbar` (the track), the part `Scrollbar::thumb`; the default theme: muted and
 * the accent colour.
 */
class Scrollbar extends Component
{
    private int $grab = 0;

    /**
     * @param (\Closure(int $position, Scrollbar $scrollbar): void)|null $onScroll
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public int $total = 0,
        public int $view = 0,
        public int $position = 0,
        public bool $horizontal = false,
        private readonly ?\Closure $onScroll = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
    }

    /** The first row (column) shown, clamped to what can scroll. */
    public function position(): int
    {
        return max(0, min($this->position, $this->total - $this->view));
    }

    public function setPosition(int $position): void
    {
        $position = max(0, min($position, $this->total - $this->view));
        if ($position !== $this->position()) {
            $this->position = $position;
            $this->call($this->onScroll, $position, $this);
            $this->emit('scroll', $this);
            $this->requestRender();
        }
    }

    private function length(): int
    {
        return $this->horizontal ? $this->surface->cols() : $this->surface->rows();
    }

    /** The thumb's size in cells. */
    private function thumb(): int
    {
        $length = $this->length();
        return max(1, min($length, (int) round($length * $this->view / max(1, $this->total))));
    }

    /** The thumb's first cell for the position. */
    private function thumbAt(): int
    {
        return (int) round($this->position() / max(1, $this->total - $this->view) * ($this->length() - $this->thumb()));
    }

    /** Put the thumb's first cell at $cell. */
    private function dragTo(int $cell): void
    {
        $room = $this->length() - $this->thumb();
        $this->setPosition($room <= 0 ? 0 : (int) round(max(0, min($room, $cell)) / $room * ($this->total - $this->view)));
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled') || $this->total <= $this->view) {
            return false;
        }
        $cell = $this->horizontal ? $mouse->x : $mouse->y;
        if ($mouse->action === Mouse::WHEEL_UP || $mouse->action === Mouse::WHEEL_DOWN) {
            $this->setPosition($this->position() + ($mouse->action === Mouse::WHEEL_UP ? -3 : 3));
        } elseif ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $at = $this->thumbAt();
            if ($cell >= $at && $cell < $at + $this->thumb()) {
                $this->grab = $cell - $at;          // (on the thumb: drag it from where it was taken)
            } else {
                $this->grab = intdiv($this->thumb(), 2);
                $this->dragTo($cell - $this->grab);
            }
        } elseif ($mouse->action === Mouse::DRAG && $mouse->button === Mouse::LEFT) {
            $this->dragTo($cell - $this->grab);
        } else {
            return false;
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->horizontal ? [$cols, 1] : [1, $rows];
    }

    protected function render(): null
    {
        $length = $this->length();
        $out = "\e[?25l\e[0m\e[2J\e[H";
        if ($this->total > $this->view) {
            $at = $this->thumbAt();
            $thumb = $this->thumb();
            $track = Ansi::style($this->style()->text);
            $bar = Ansi::style($this->style('thumb')->text);
            for ($i = 0; $i < $length; $i++) {
                $on = $i >= $at && $i < $at + $thumb;
                $glyph = $this->horizontal ? ($on ? '━' : '─') : ($on ? '┃' : '│');
                $out .= ($on ? $bar : $track) . $glyph . ($this->horizontal ? '' : ($i < $length - 1 ? "\r\n" : ''));
            }
        }
        $this->surface->write($out);
        return null;
    }
}
