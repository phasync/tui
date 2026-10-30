<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Ansi;
use phasync\Tui\Event;
use phasync\Tui\Focus;
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
    public bool $clickFocus = false;

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
            $this->viewports[] = $view;
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
            if ($this->focus === null) {
                $this->focus = $view;
                if ($child->title() !== '') {
                    $this->surface->write(Ansi::title($child->title()));
                }
            }
            $this->markDirty();
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
        if ($this->capture === $view) {
            $this->capture = $this->anchor = null;
        }
        $this->blank($view->rect());
        if ($this->focus === $view) {
            $this->focus = null;
            $next = end($this->viewports);
            if ($next !== false) {
                $this->setFocus($next->source);
            }
        }
        $this->markDirty();
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
    }

    /** A viewport's look or scroll position changed. */
    public function changed(Viewport $view): void
    {
        $this->markDirty();
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
    }

    // ------------------------------------------------------------------------------------------
    // Focus

    public function setFocus(?Surface $child): void
    {
        $view = $child === null ? null : $this->find($child);
        if ($child !== null && $view === null) {
            throw new \InvalidArgumentException('Only a hosted surface can have the focus');
        }
        if ($view === $this->focus) {
            return;
        }
        $old = $this->focus;
        $this->focus = $view;
        $old?->source->dispatch(new Focus(false));
        if ($view !== null) {
            $view->source->dispatch(new Focus(true));
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

    /** Every leaf below $surface (a surface hosting nothing), as its path, in depth-first order. */
    private static function leaves(Surface $surface, array $path = []): array
    {
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
        $host = $this->surface;
        foreach ($leaves[$next] as $surface) {
            $host->focus($surface);
            $host = $surface;
        }
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
        if ($m->action === Mouse::PRESS && $this->clickFocus && $this->focus !== $view) {
            $this->setFocus($view->source);
        }
        if ($this->anchor !== null) {
            return $this->select($view, $m);
        }
        $pos = $view->toSource($m->x, $m->y, $this->capture !== null);
        if ($pos !== null) {
            $source = $view->source;
            $local = $m->at($pos[0], max(0, min($source->rows() - 1, $pos[1])));
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
