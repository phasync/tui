<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Internal\ElementLayout;
use phasync\Tui\Internal\LayoutItem;
use phasync\Tui\Internal\ScrollLayout;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Mouse;
use phasync\Tui\Resize;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * Content taller than its box, scrolling: a long form in a small dialog, a page of text.
 *
 *     new ScrollView(new Column([$this->child('a', fn() => new Editor()), new Text($longHelp)]))
 *
 * The content (any element tree, a prop) is laid out at the view's width and as high as it
 * needs, on a surface of its own behind the view; the view shows part of it. The element that
 * has the focus is kept in view (Tab from field to field scrolls along). The wheel scrolls it,
 * and so do Up/Down, Page Up/Page Down and Home/End when nothing in it takes them (the keys go
 * on when it cannot scroll further, to move to the next field). Without a size of its own it
 * is as high as its content (`measure()`), so it only scrolls when its box is smaller: in a
 * dialog shrunk to a small terminal, for instance.
 */
class ScrollView extends Component
{
    private ?Surface $inside = null;
    private int $top = 0;
    /** The focused element was looked for at this top already (the user scrolled away from it). */
    private ?Surface $kept = null;

    /** @param Element $content what scrolls (a prop) */
    public function __construct(public Element $content)
    {
    }

    protected function bindings(): array
    {
        return [
            'up' => 'lineUp', 'down' => 'lineDown', 'pageup' => 'pageUp', 'pagedown' => 'pageDown',
            'home' => 'toTop', 'end' => 'toBottom',
        ];
    }

    /** The first row of the content in view. */
    public function top(): int
    {
        return $this->top;
    }

    /** Show content row $row at the top (clamped). */
    public function scrollTo(int $row): void
    {
        $this->top = max(0, min($row, $this->maxTop()));
        $this->surface->viewport($this->inside())?->scrollTo($this->top);
    }

    /** Scroll by $rows (negative: up); false when it could not move. */
    public function scrollBy(int $rows): bool
    {
        $before = $this->top;
        $this->scrollTo($this->top + $rows);
        return $this->top !== $before;
    }

    public function lineUp(): bool
    {
        return $this->scrollBy(-1);
    }

    public function lineDown(): bool
    {
        return $this->scrollBy(1);
    }

    public function pageUp(): bool
    {
        return $this->scrollBy(-max(1, $this->surface->rows() - 1));
    }

    public function pageDown(): bool
    {
        return $this->scrollBy(max(1, $this->surface->rows() - 1));
    }

    public function toTop(): bool
    {
        return $this->scrollBy(-$this->top);
    }

    public function toBottom(): bool
    {
        return $this->scrollBy($this->maxTop() - $this->top);
    }

    private function maxTop(): int
    {
        return max(0, $this->inside()->rows() - $this->surface->rows());
    }

    private function inside(): Surface
    {
        return $this->inside ??= new Surface($this->surface->cols(), $this->surface->rows(), 0);
    }

    protected function onKey(Key $key): bool
    {
        // a key that moves the focus inside may need the view to follow: lay out again after it
        $this->markDirty();
        return false;
    }

    /** A new height: the focused element is brought into view again (a dialog that shrank). */
    protected function onResize(Resize $size): void
    {
        $this->kept = null;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->action === Mouse::WHEEL_UP || $mouse->action === Mouse::WHEEL_DOWN) {
            $this->scrollBy($mouse->action === Mouse::WHEEL_UP ? -3 : 3);
            return true;
        }
        return false;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->measureElement($this->content, $cols, $rows);
    }

    protected function render(): Layout
    {
        $inner = $this->layoutElement($this->content);
        // as high as the content needs; content that fills (a field that takes any room) as
        // high as its parts need at least: what does not fit is cropped and scrolls, not squeezed
        $height = fn(int $cols) => $this->measureElement($this->content, $cols, LayoutItem::TALL)[1]
            ?? ($inner instanceof ElementLayout ? $inner->least($cols) : 0);
        return new ScrollLayout($this->inside(), $inner, $height, $this->follow(...));
    }

    /** After the layout: the top kept, and the focused element in view. */
    private function follow(Viewport $view): void
    {
        $this->top = max(0, min($this->top, $this->maxTop()));
        $leaf = $this->inside();
        while (($next = $leaf->focused()) !== null) {
            $leaf = $next;
        }
        if ($leaf !== $this->inside && $leaf !== $this->kept) {
            // the field with what frames it: up to the largest window around it that holds no
            // other field and fits in the view (a Box with its border and title)
            $shown = $leaf;
            while (($v = $shown->viewport()) !== null && ($host = $v->host()) !== $this->inside
                && ($hv = $host->viewport()) !== null && $hv->height() <= $this->surface->rows()
                && \count(array_filter($host->viewports(), fn(Viewport $view) => $view->source->isFocusable())) === 1) {
                $shown = $host;
            }
            $v = $shown->viewport();
            $y = $v?->y() ?? 0;
            $h = $v?->height() ?? $shown->rows();
            for ($s = $v?->host(); $s !== null && $s !== $this->inside && ($u = $s->viewport()) !== null; $s = $u->host()) {
                $y += $u->y() + ($u->hasBorder() ? 1 : 0) - $u->top();
            }
            if ($y < $this->top) {
                $this->top = $y;
            } elseif ($y + $h > $this->top + $this->surface->rows()) {
                $this->top = min($y, $y + $h - $this->surface->rows());
            }
            $this->kept = $leaf;
        }
        $view->scrollTo($this->top);
    }
}
