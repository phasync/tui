<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Ansi;
use phasync\Tui\Event;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * @internal The hosting state of a Surface: its viewports in z-order, focus, layout, and the
 * compositor that repaints hosted surfaces into the host by writing escape sequences to it.
 * `Surface` creates one when it hosts its first surface and delegates to it.
 */
final class Host
{
    /** How long a hosted surface may hold back its output with synchronized output (mode 2026). */
    private const SYNC_TIMEOUT = 0.5;

    /** @var list<Viewport> bottom first */
    public array $viewports = [];
    public ?Viewport $focus = null;
    /** A hosted surface changed (or a viewport did) since the last compose. */
    public bool $dirty = false;
    public ?Layout $layout = null;
    /** A press on a window focuses it; null: as the host's host says. */
    public ?bool $clickFocus = null;
    /** @var list<Surface> the surfaces made for layouts nested in this host's layout, in order */
    private array $nested = [];

    private bool $composing = false;
    /** The host's version after the last compose; rows changed after it were changed by its own producer. */
    private int $composedVersion = -1;
    /** @var array<int, list<\Closure>> listener removers per viewport */
    private array $offs = [];
    /** @var array<int, true>|null surfaces placed by the layout being applied */
    private ?array $placed = null;
    private ?Viewport $capture = null;
    /** @var array{int,int}|null where a selection drag started, in source coordinates */
    private ?array $anchor = null;
    private bool $dragged = false;

    public function __construct(
        public readonly Surface $surface,
        private readonly Emulator $vt,
        /** Tells the host's own host (and a terminal device) that this surface needs composing. */
        private readonly \Closure $notify,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Viewports

    public function host(Surface $child, ?int $x, ?int $y, ?int $width, ?int $height): Viewport
    {
        $view = $this->find($child);
        if ($view === null) {
            if ($child === $this->surface || self::hosts($child, $this->surface)) {
                throw new \InvalidArgumentException('A surface cannot host itself or a surface that hosts it');
            }
            $view = new Viewport($child, $this, $x ?? 0, $y ?? 0, $width ?? $child->cols(), $height ?? $child->rows());
            if ($child->viewport() === null) {
                $child->joinTree();
            }
            $this->viewports[] = $view;
            $child->shownThrough($view, true);
            $this->offs[spl_object_id($view)] = [
                $child->on('change', fn() => $this->markDirty()),
                $child->on('title', function (string $title) use ($view): void {
                    if ($this->focus === $view) {
                        $this->surface->write(Ansi::title($title));
                    }
                }),
                $child->on('clipboard', fn(string $text) => $this->surface->write(Ansi::clipboard($text))),
                $child->on('bell', fn() => $this->surface->write("\x07")),
            ];
            // the focus settles when the change to the tree is complete (Surface::changeTree())
            $this->markDirty();
            Surface::geometryChanged();
        } else {
            if ($x !== null || $y !== null) {
                $view->move($x ?? $view->x(), $y ?? $view->y());
            }
            if ($width !== null || $height !== null) {
                $view->resize($width ?? $view->width(), $height ?? $view->height());
            }
        }
        if ($this->placed !== null) {
            $this->placed[spl_object_id($child)] = true;
        }
        return $view;
    }

    /** True if $surface hosts $other, directly or further down. */
    private static function hosts(Surface $surface, Surface $other): bool
    {
        foreach ($surface->viewports() as $v) {
            if ($v->source === $other || self::hosts($v->source, $other)) {
                return true;
            }
        }
        return false;
    }

    public function find(Surface $child): ?Viewport
    {
        foreach ($this->viewports as $v) {
            if ($v->source === $child) {
                return $v;
            }
        }
        return null;
    }

    public function unhost(Surface $child): void
    {
        $view = $this->find($child);
        if ($view === null) {
            return;
        }
        $id = spl_object_id($view);
        foreach ($this->offs[$id] as $off) {
            $off();
        }
        unset($this->offs[$id]);
        $this->viewports = array_values(array_filter($this->viewports, fn(Viewport $v) => $v !== $view));
        $child->shownThrough($view, false);
        if ($this->capture === $view) {
            $this->capture = $this->anchor = null;
        }
        $this->blank($view->rect());
        if ($this->focus === $view) {
            $this->focus = null;    // settled again when the change to the tree is complete
        }
        $this->markDirty();
        Surface::geometryChanged();
    }

    public function raise(Surface $child): void
    {
        $view = $this->find($child);
        if ($view === null || end($this->viewports) === $view) {
            return;
        }
        $this->viewports = [...array_filter($this->viewports, fn(Viewport $v) => $v !== $view), $view];
        $view->invalidate();
        $this->markDirty();
    }

    /** A viewport moved or changed size: blank where it was, repaint it where it is. */
    public function moved(Viewport $view, array $old): void
    {
        $this->blank($old);
        $this->markDirty();
        Surface::geometryChanged();
    }

    /** A viewport's look or scroll position changed. */
    public function changed(Viewport $view): void
    {
        $this->markDirty();
        Surface::geometryChanged();
    }

    /**
     * Erase a rectangle of the host (with the default style), as when a window closes or moves
     * away. What other windows had there is repainted at the next compose, because the host's
     * own writes count as damage under the viewports.
     *
     * @param array{int,int,int,int} $rect
     */
    private function blank(array $rect): void
    {
        [$x, $y, $w, $h] = $rect;
        $x1 = max(0, $x);
        $x2 = min($this->vt->cols, $x + $w);
        if ($x1 >= $x2) {
            return;
        }
        $out = '';
        for ($r = max(0, $y); $r < min($this->vt->rows, $y + $h); $r++) {
            $out .= Ansi::moveTo($x1, $r) . "\e[" . ($x2 - $x1) . 'X';
        }
        if ($out !== '') {
            $this->surface->write($this->wrap("\e[0m" . $out));
        }
    }

    /** The host was written to: what its producer wrote under a window is painted over again. */
    public function wrote(): void
    {
        if (!$this->composing && $this->viewports !== []) {
            $this->markDirty();
        }
    }

    public function markDirty(): void
    {
        if (!$this->dirty) {
            $this->dirty = true;
            ($this->notify)();
        }
    }

    // ------------------------------------------------------------------------------------------
    // Layout

    public function arrange(): void
    {
        if ($this->layout === null) {
            return;
        }
        $outer = $this->placed;
        $this->placed = [];
        try {
            $this->layout->arrange($this->surface);
            $placed = $this->placed;
        } finally {
            $this->placed = $outer;
        }
        foreach ($this->viewports as $v) {
            if (!isset($placed[spl_object_id($v->source)])) {
                $this->unhost($v->source);
            }
        }
        // surfaces of nested layouts that the layout no longer has
        foreach ($this->nested as $i => $surface) {
            if (!isset($placed[spl_object_id($surface)])) {
                unset($this->nested[$i]);
                $surface->giveUp();
            }
        }
        $this->nested = array_values($this->nested);
    }

    /**
     * This surface was made for a nested layout that is gone: unhost its windows, and give up
     * the surfaces of its own nested layouts the same way, so that no window stays hosted (and
     * focused) in a part of the tree that is no longer shown.
     */
    public function giveUp(): void
    {
        $this->layout = null;
        $nested = $this->nested;
        $this->nested = [];
        foreach ($this->viewports as $v) {
            $this->unhost($v->source);
        }
        foreach ($nested as $surface) {
            $surface->giveUp();
        }
    }

    /**
     * The surface for the $index-th layout nested in this host's layout: the same one on every
     * arrange, so a layout made anew (by a component's render) keeps its windows and focus.
     */
    public function nested(int $index): Surface
    {
        return $this->nested[$index] ??= new Surface($this->vt->cols, $this->vt->rows, 0);
    }

    // ------------------------------------------------------------------------------------------
    // Focus

    /**
     * Point the focus at $view (null: the host itself). Only the settling of the tree's focus
     * calls this (Surface::changeTree()); it tells nobody, the settling does. The focused
     * window's title becomes the host's.
     */
    public function setFocus(?Viewport $view): void
    {
        if ($view === $this->focus) {
            return;
        }
        $this->focus = $view;
        if ($view !== null) {
            $this->surface->write(Ansi::title($view->source->title()));
        }
        $this->markDirty();
    }

    /** The focus path below $surface: the viewports from $surface down to the focused leaf. */
    private static function focusPath(Surface $surface): array
    {
        $path = [];
        while (($child = $surface->focused()) !== null) {
            $path[] = $child;
            $surface = $child;
        }
        return $path;
    }

    /** Every leaf below $surface (a surface hosting nothing) that takes the focus, as its path, depth first. */
    private static function leaves(Surface $surface, array $path = []): array
    {
        if (!$surface->isFocusable()) {
            return [];
        }
        $views = $surface->viewports();
        if ($views === []) {
            return $path === [] ? [] : [$path];
        }
        $out = [];
        foreach ($views as $v) {
            array_push($out, ...self::leaves($v->source, [...$path, $v->source]));
        }
        return $out;
    }

    public function focusNext(bool $backwards): bool
    {
        $leaves = self::leaves($this->surface);
        if ($leaves === []) {
            return false;
        }
        $current = self::focusPath($this->surface);
        $at = array_search($current, $leaves, true);
        $n = \count($leaves);
        $next = $at === false ? ($backwards ? $n - 1 : 0) : ($at + ($backwards ? $n - 1 : 1)) % $n;
        if ($at === $next) {
            return false;
        }
        end($leaves[$next])->focus();
        return true;
    }

    /**
     * Focus the window next to the focused leaf in $direction, by the leaves' areas on the root:
     * of those entirely on that side, the ones alongside the focused window (overlapping it
     * across the direction) first, then the nearest, then the one best centred.
     */
    public function focusToward(string $direction): bool
    {
        $leaves = array_map(fn(array $path) => end($path), self::leaves($this->surface));
        if ($leaves === []) {
            return false;
        }
        $path = self::focusPath($this->surface);
        $current = $path === [] ? null : end($path);
        if ($current === null || !\in_array($current, $leaves, true)) {
            $leaves[0]->focus();
            return true;
        }
        $from = $current->area();
        $best = null;
        $bestScore = null;
        foreach ($leaves as $leaf) {
            if ($leaf === $current) {
                continue;
            }
            $to = $leaf->area();
            if ($to->isEmpty()) {
                continue;
            }
            $gap = match ($direction) {
                'left' => $from->x - ($to->x + $to->width),
                'right' => $to->x - ($from->x + $from->width),
                'up' => $from->y - ($to->y + $to->height),
                'down' => $to->y - ($from->y + $from->height),
            };
            if ($gap < 0) {
                continue;       // not entirely on that side
            }
            $across = $direction === 'left' || $direction === 'right';
            [$a1, $a2, $b1, $b2] = $across
                ? [$from->y, $from->y + $from->height, $to->y, $to->y + $to->height]
                : [$from->x, $from->x + $from->width, $to->x, $to->x + $to->width];
            $overlap = min($a2, $b2) - max($a1, $b1);
            $offCentre = abs(($a1 + $a2) - ($b1 + $b2));
            $score = [$overlap > 0 ? 0 : 1, $gap, $offCentre];
            if ($bestScore === null || $score < $bestScore) {
                $best = $leaf;
                $bestScore = $score;
            }
        }
        if ($best === null) {
            return false;
        }
        $best->focus();
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Compositing

    public function compose(): void
    {
        if ($this->composing) {
            return;
        }
        $this->composing = true;
        try {
            // children first; one still holding back an update keeps this host dirty too, so
            // the hosts above (and the terminal device) keep looking until it is shown
            $this->dirty = false;
            foreach ($this->viewports as $v) {
                $v->source->compose();
                if ($v->source->needsCompose()) {
                    $this->dirty = true;
                }
            }
            $cols = $this->vt->cols;
            $rows = $this->vt->rows;
            // rows the host's own producer changed: the windows over them are painted again
            $own = [];
            foreach ($this->vt->rowVersion as $y => $version) {
                if ($version > $this->composedVersion) {
                    $own[$y] = true;
                }
            }
            $out = '';
            $count = \count($this->viewports);
            foreach ($this->viewports as $index => $v) {
                [, $vy, , $vh] = $v->rect();
                $age = $v->source->syncAge();
                if ($age !== null && $age < self::SYNC_TIMEOUT) {
                    // the program is drawing a frame: show it once it is complete
                    $this->dirty = true;
                    continue;
                }
                $set = [];
                foreach ($v->damagedRows() as $y) {
                    $set[$y] = true;
                }
                $forget = [];
                for ($y = max(0, $vy); $y < min($rows, $vy + $vh); $y++) {
                    if (isset($own[$y])) {
                        $set[$y] = true;
                        $forget[] = $y;
                    }
                }
                $v->forgetRows($forget);
                if ($set === []) {
                    continue;
                }
                // a window writes no cell that a window above it covers, so it never has to
                // repaint the windows above
                $covers = [];
                for ($j = $index + 1; $j < $count; $j++) {
                    $covers[] = $this->viewports[$j]->rect();
                }
                $out .= $v->paint($set, $cols, $rows, $covers);
            }
            if ($out !== '') {
                $this->surface->write($this->wrap($out));
            }
            $this->composedVersion = $this->vt->version;
        } finally {
            $this->composing = false;
        }
        if ($this->dirty) {
            ($this->notify)();
        }
    }

    /**
     * The compositor's drawing, wrapped so the producer of the host keeps its state: its cursor,
     * pen and character sets are saved (DECSC) and restored (DECRC), and what would bend the
     * drawing (origin mode, insert mode, a shifted character set) is off in between.
     */
    private function wrap(string $drawing): string
    {
        $insert = $this->vt->insertMode;
        return "\e7\e[?6l\x0f\e(B" . ($insert ? "\e[4l" : '') . $drawing . "\e[0m" . ($insert ? "\e[4h" : '') . "\e8";
    }

    // ------------------------------------------------------------------------------------------
    // Input

    /** Route an input event to the hosted surfaces; false when it is for the host itself. */
    public function route(Event $event): bool
    {
        if ($event instanceof Mouse) {
            return $this->mouse($event);
        }
        $view = $this->focus;
        if ($view === null) {
            return false;
        }
        if ($event instanceof Key || $event instanceof Paste) {
            // typing goes to the bottom of the output, as in a terminal
            $view->clearSelection();
            $view->follow();
        }
        return $view->source->dispatch($event);
    }

    private function mouse(Mouse $m): bool
    {
        $view = $this->capture;
        if ($view === null) {
            for ($i = \count($this->viewports) - 1; $i >= 0; $i--) {
                if ($this->viewports[$i]->contains($m->x, $m->y)) {
                    $view = $this->viewports[$i];
                    break;
                }
            }
        }
        if ($view === null) {
            return false;
        }
        if ($m->action === Mouse::PRESS && $this->focus !== $view && ($this->clickFocus ?? $this->surface->focusesOnClick())) {
            $view->source->focus();
        }
        if ($this->anchor !== null) {
            return $this->select($view, $m);
        }
        if ($this->capture !== null) {
            // the window that took the press gets the whole drag, in its coordinates, also
            // outside it (negative, or past its size); a program's report is clamped when encoded
            $b = $view->hasBorder() ? 1 : 0;
            $pos = [$view->left() + $m->x - $view->x() - $b, $view->top() + $m->y - $view->y() - $b];
        } else {
            $pos = $view->toSource($m->x, $m->y);
        }
        if ($pos !== null) {
            $source = $view->source;
            $local = $m->at($pos[0], $this->capture !== null ? $pos[1] : max(0, min($source->rows() - 1, $pos[1])));
            if ($source->dispatch($local)) {
                if ($m->action === Mouse::PRESS) {
                    $this->capture = $view;
                } elseif ($m->action === Mouse::RELEASE) {
                    $this->capture = null;
                }
                return true;
            }
        }
        // the program did not want it: the host scrolls and selects
        if ($m->action === Mouse::WHEEL_UP || $m->action === Mouse::WHEEL_DOWN) {
            $view->scrollBy($m->action === Mouse::WHEEL_UP ? -3 : 3);
            return true;
        }
        if ($pos !== null && $m->action === Mouse::PRESS && $m->button === Mouse::LEFT) {
            foreach ($this->viewports as $v) {
                $v->clearSelection();
            }
            $this->capture = $view;
            $this->anchor = $pos;
            $this->dragged = false;
            return true;
        }
        if ($m->action === Mouse::RELEASE) {
            $this->capture = null;
        }
        return $pos !== null || $view->contains($m->x, $m->y);
    }

    private function select(Viewport $view, Mouse $m): bool
    {
        $pos = $view->toSource($m->x, $m->y, true);
        if ($pos !== null && ($m->action === Mouse::DRAG || ($m->action === Mouse::RELEASE && $this->dragged))) {
            if ($pos !== $this->anchor || $this->dragged) {
                $view->select($this->anchor[0], $this->anchor[1], $pos[0], $pos[1]);
                $this->dragged = true;
            }
        }
        if ($m->action === Mouse::RELEASE) {
            $this->capture = $this->anchor = null;
            if ($this->dragged && ($text = $view->selectedText()) !== '') {
                $this->surface->write(Ansi::clipboard($text));
            }
        }
        return true;
    }
}
