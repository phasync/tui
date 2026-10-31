<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Area;
use phasync\Tui\Event;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * @internal The hosting state of a Surface: its viewports in z-order, focus, layout, and the
 * compositor that makes its frame (what it shows: its own cells with the windows blitted over
 * them, to any depth). `Surface` creates one when it hosts its first surface and delegates to it.
 */
final class Host
{
    /** @var list<Viewport> bottom first */
    public array $viewports = [];
    /** @var array<int, Viewport> the same viewports by the object id of their surface */
    private array $bySource = [];
    public ?Viewport $focus = null;
    /** Something the frame shows changed since it was made: the host's own cells, a window, a hosted surface. */
    public bool $dirty = true;
    /** @var list<Line> the frame: what the host shows (see compose()) */
    private array $frame = [];
    /** @var array<int, int> the version at which each row of the frame last changed */
    private array $rowVersion = [];
    /** A window held back a synchronized update when the frame was made. */
    private bool $holding = false;
    /** @var array<int, int> the own rows' versions when the frame was made */
    private array $ownVersion = [];
    /** @var list<array{Viewport, ?string, int, int}> each window when the frame was made: its key (`place()`) and rows */
    private array $shown = [];
    public ?Layout $layout = null;
    /** A press on a window focuses it; null: as the host's host says. */
    public ?bool $clickFocus = null;
    /** @var list<Surface> the surfaces made for layouts nested in this host's layout, in order */
    private array $nested = [];

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
        /** Tells the host's own host (and a terminal device) that what this surface shows changed. */
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
            $this->bySource[spl_object_id($child)] = $view;
            $child->shownThrough($view, true);
            $this->offs[spl_object_id($view)] = [
                $child->on('change', fn() => $this->markDirty()),
                $child->on('title', function (string $title) use ($view): void {
                    if ($this->focus === $view) {
                        $this->surface->relay('title', $title);
                    }
                }),
                $child->on('clipboard', fn(string $text) => $this->surface->relay('clipboard', $text)),
                $child->on('bell', fn() => $this->surface->relay('bell')),
            ];
            // the focus settles when the change to the tree is complete (Surface::changeTree())
            Surface::focusMayMove($this->surface);
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
        return $this->bySource[spl_object_id($child)] ?? null;
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
        unset($this->offs[$id], $this->bySource[spl_object_id($child)]);
        $this->viewports = array_values(array_filter($this->viewports, fn(Viewport $v) => $v !== $view));
        $child->shownThrough($view, false);
        if ($this->capture === $view) {
            $this->capture = $this->anchor = null;
        }
        if ($this->focus === $view) {
            $this->focus = null;    // settled again when the change to the tree is complete
        }
        Surface::focusMayMove($this->surface);
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
        Surface::focusMayMove($this->surface);
        $this->markDirty();
    }

    /** A viewport moved, changed size or look, or scrolled. */
    public function changed(): void
    {
        $this->markDirty();
        Surface::geometryChanged();
    }

    /**
     * What the frame shows changed: it is made again when it is read, and the host's host (up
     * to the root, and the terminal device showing it) is told, every time (there is nothing
     * to keep in step between levels: the root's frame is made from all of them at once).
     */
    public function markDirty(): void
    {
        $this->dirty = true;
        $this->vt->version++;       // (the surface's change count: a window showing it is drawn anew)
        ($this->notify)();
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
        $this->focus = $view;      // (a border shows the focus)
        if ($view !== null) {
            $this->surface->relay('title', $view->source->title());
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

    public function focusNext(bool $backwards, bool $wrap = true): bool
    {
        $leaves = self::leaves($this->surface);
        if ($leaves === []) {
            return false;
        }
        $current = self::focusPath($this->surface);
        $at = array_search($current, $leaves, true);
        $n = \count($leaves);
        $next = $at === false ? ($backwards ? $n - 1 : 0) : ($at + ($backwards ? $n - 1 : 1)) % $n;
        if ($at === $next || (!$wrap && $at !== false && ($backwards ? $next > $at : $next < $at))) {
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

    /**
     * Make the frame: what the host shows. Its own cells, then every window in z-order with
     * the windows it hosts in turn, to any depth, copied (`Area::cut()`/`Area::put()`) and
     * clipped by every window on the way down; a shadow shades the windows below the one that
     * casts it. Rows that differ from the previous frame get a new version (the host's damage).
     *
     * Only rows something changed on are made again: rows of the host's own cells that changed,
     * and the rows of each window drawn differently than in the last frame (where it was and
     * where it is), with every window on them; the others are the last frame's. A new size, a
     * window hosted, unhosted or raised, or a window holding back an update makes all of them.
     * It costs the rows that changed, whatever the depth of the tree.
     */
    public function compose(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->dirty = false;
        $vt = $this->vt;
        $cols = $vt->cols;
        $rows = $vt->rows;
        $views = $this->viewports;
        $above = self::shadesAbove($views, 0, 0);
        $at = [];
        foreach ($views as $i => $v) {
            $at[$i] = self::place($v, 0, 0, 0, 0, $cols, $rows, $above[$i]);
        }
        $all = $this->holding || \count($this->shown) !== \count($views);
        if (\count($this->frame) !== $rows || \count($this->frame[0]->chars) !== $cols) {
            // the first frame, or another size: what showed before is the host's own cells
            $this->frame = array_map(fn(Line $line) => clone $line, $vt->lines);
            $this->rowVersion = $vt->rowVersion;
            $all = true;
        }
        $damaged = [];
        foreach ($views as $i => $v) {
            $was = $this->shown[$i] ?? null;
            if ($all || $was[0] !== $v) {
                $all = true;
                break;
            }
            $key = $at[$i][8] ?? null;
            if ($key !== $was[1]) {
                for ($y = $was[2]; $y < $was[3]; $y++) {
                    $damaged[$y] = true;
                }
                for ($y = $at[$i][5] ?? 0, $y2 = $at[$i][7] ?? 0; $y < $y2; $y++) {
                    $damaged[$y] = true;
                }
            }
        }
        foreach ($vt->rowVersion as $y => $version) {
            if ($all || $version !== ($this->ownVersion[$y] ?? null)) {
                $damaged[$y] = true;
            }
        }
        $this->ownVersion = $vt->rowVersion;
        $this->shown = [];
        foreach ($views as $i => $v) {
            $this->shown[] = $at[$i] === null ? [$v, null, 0, 0] : [$v, $at[$i][8], $at[$i][5], $at[$i][7]];
        }
        $this->holding = false;
        if ($damaged === []) {
            return;
        }
        ksort($damaged);
        $ys = array_keys($damaged);
        $version = null;
        for ($k = 0, $n = \count($ys); $k < $n;) {
            // a band of rows: the own cells, the windows on them
            $a = $ys[$k++];
            for ($b = $a + 1; $k < $n && $ys[$k] === $b; $k++, $b++);
            $chars = $styles = [];
            for ($y = $a; $y < $b; $y++) {
                $chars[$y] = $vt->lines[$y]->chars;
                $styles[$y] = $vt->lines[$y]->styles;
            }
            foreach ($views as $i => $v) {
                if ($at[$i] !== null) {
                    $this->blit($chars, $styles, $v, $at[$i], $above[$i], $a, $b);
                }
            }
            for ($y = $a; $y < $b; $y++) {
                $line = $this->frame[$y];
                if ($line->chars !== $chars[$y] || $line->styles !== $styles[$y]) {
                    $line->chars = $chars[$y];
                    $line->styles = $styles[$y];
                    $this->rowVersion[$y] = $version ??= ++$vt->version;
                }
            }
        }
        foreach ($this->frame as $y => $line) {
            $line->wrapped = $vt->lines[$y]->wrapped;
        }
    }

    /**
     * Blit $views (a host's windows, bottom first, placed at $ox,$oy of the frame) into the
     * frame's rows $by1 to $by2 (exclusive), inside the clip rectangle $cx1,$cy1 - $cx2,$cy2.
     *
     * @param array<int, list<string>> $chars
     * @param array<int, list<Style>>  $styles
     * @param list<Viewport>           $views
     */
    private function blitAll(array &$chars, array &$styles, array $views, int $ox, int $oy, int $cx1, int $cy1, int $cx2, int $cy2, int $by1, int $by2): void
    {
        $above = self::shadesAbove($views, $ox, $oy);
        foreach ($views as $i => $v) {
            $at = self::place($v, $ox, $oy, $cx1, $cy1, $cx2, $cy2, $above[$i]);
            if ($at !== null) {
                $this->blit($chars, $styles, $v, $at, $above[$i], $by1, $by2);
            }
        }
    }

    /**
     * The shadows of the windows above each of $views, in frame coordinates.
     *
     * @param list<Viewport> $views
     * @return list<list<array{int,int,int,int,int}>>
     */
    private static function shadesAbove(array $views, int $ox, int $oy): array
    {
        $above = [];
        $shades = [];
        for ($i = \count($views) - 1; $i >= 0; $i--) {
            $above[$i] = $shades;
            if ($views[$i]->shadow() !== null) {
                foreach ($views[$i]->shadowRects() as [$sx, $sy, $sw, $sh, $color]) {
                    $shades[] = [$ox + $sx, $oy + $sy, $sw, $sh, $color];
                }
            }
        }
        return $above;
    }

    /**
     * Where window $v is in the frame (its host placed at $ox,$oy), the part of it inside the
     * clip, and the key of how it is drawn there: equal keys, equal cells (its subtree, look,
     * place, clip and shadows the same). Null when none of it shows.
     *
     * @param list<array{int,int,int,int,int}> $shades
     * @return array{int,int,int,int,int,int,int,int,string}|null x, y, width, height, left, top, right, bottom, key
     */
    private static function place(Viewport $v, int $ox, int $oy, int $cx1, int $cy1, int $cx2, int $cy2, array $shades): ?array
    {
        [$x0, $y0, $w, $h] = $v->rect();
        $x0 += $ox;
        $y0 += $oy;
        $left = max($x0, $cx1);
        $right = min($x0 + $w, $cx2);
        $top = max($y0, $cy1);
        $bottom = min($y0 + $h, $cy2);
        if ($left >= $right || $top >= $bottom) {
            return null;
        }
        $key = $v->source->changeCount() . ",$x0,$y0,$w,$h,$left,$top,$right,$bottom," . $v->lookKey() . ($shades === [] ? '' : json_encode($shades));
        return [$x0, $y0, $w, $h, $left, $top, $right, $bottom, $key];
    }

    /**
     * Blit window $v, placed as `place()` says, into the frame's rows $by1 to $by2: the cells
     * of the last frame it was drawn in when its key is the same, else drawn anew.
     *
     * @param array<int, list<string>>              $chars
     * @param array<int, list<Style>>               $styles
     * @param array{int,int,int,int,int,int,int,int,string} $at
     * @param list<array{int,int,int,int,int}>      $shades
     */
    private function blit(array &$chars, array &$styles, Viewport $v, array $at, array $shades, int $by1, int $by2): void
    {
        [$x0, $y0, $w, $h, $left, $top, $right, $bottom, $key] = $at;
        $from = max($top, $by1);
        $to = min($bottom, $by2);
        if ($from >= $to) {
            return;
        }
        $drawn = $v->drawn;
        if ($drawn !== null && $drawn[0] === $key && $drawn[1] !== null) {
            for ($y = $from; $y < $to; $y++) {
                Area::put($chars[$y], $styles[$y], $left, $drawn[1][$y - $top], $drawn[2][$y - $top]);
            }
            return;
        }
        $holding = $this->holding;
        $this->holding = false;
        $this->draw($chars, $styles, $v, $x0, $y0, $w, $h, $left, $top, $right, $bottom, $shades, $by1, $by2);
        if ($this->holding) {
            $v->drawn = null;       // (a window in it holds back an update: drawn anew next frame)
        } elseif ($drawn === null || $drawn[0] !== $key) {
            $v->drawn = [$key, null, null];     // (changed: kept once it stays as it is for a frame)
        } elseif ($from === $top && $to === $bottom) {
            $c = $s = [];
            for ($y = $top, $n = $right - $left; $y < $bottom; $y++) {
                $c[] = \array_slice($chars[$y], $left, $n);
                $s[] = \array_slice($styles[$y], $left, $n);
            }
            $v->drawn = [$key, $c, $s];
        }
        $this->holding = $this->holding || $holding;
    }

    /**
     * The spans of $a..$z (exclusive) that none of $covers covers.
     *
     * @param list<array{int,int}> $covers
     * @return list<array{int,int}>
     */
    private static function uncovered(int $a, int $z, array $covers): array
    {
        sort($covers);
        $out = [];
        foreach ($covers as [$c1, $c2]) {
            if ($c1 > $a) {
                $out[] = [$a, min($c1, $z)];
            }
            $a = max($a, $c2);
            if ($a >= $z) {
                return $out;
            }
        }
        $out[] = [$a, $z];
        return $out;
    }

    /**
     * Draw window $v at $x0,$y0 ($w x $h) of the frame, inside $left,$top - $right,$bottom, on
     * the rows $by1 to $by2 of it: its border, its source's cells in view, the windows its
     * source hosts, its selection, and the shadows of the windows above it.
     *
     * @param array<int, list<string>>         $chars
     * @param array<int, list<Style>>          $styles
     * @param list<array{int,int,int,int,int}> $shades
     */
    private function draw(array &$chars, array &$styles, Viewport $v, int $x0, int $y0, int $w, int $h, int $left, int $top, int $right, int $bottom, array $shades, int $by1, int $by2): void
    {
        $from = max($top, $by1);
        $to = min($bottom, $by2);
        $b = $v->hasBorder() ? 1 : 0;
        if ($b) {
            $style = $v->borderStyleNow();
            foreach ([0, $h - 1] as $r) {
                $y = $y0 + $r;
                if ($y >= $from && $y < $to && ($r === 0 || $h > 1)) {
                    [$bc, $bs] = $v->borderRow($r === 0, $style);
                    [$c, $s] = Area::cut($bc, $bs, $left - $x0, $right - $left);
                    Area::put($chars[$y], $styles[$y], $left, $c, $s);
                }
            }
            $side = Viewport::BORDERS[$v->borderKind()][5];
            $sides = [];
            if ($x0 >= $left) {
                $sides[] = $x0;
            }
            if ($w > 1 && $x0 + $w - 1 < $right) {
                $sides[] = $x0 + $w - 1;
            }
            for ($y = max($from, $y0 + 1); $y < min($to, $y0 + $h - 1); $y++) {
                foreach ($sides as $x) {
                    Area::put($chars[$y], $styles[$y], $x, [$side], [$style]);
                }
            }
        }
        $ix = $x0 + $b;
        $iy = $y0 + $b;
        $il = max($ix, $left);
        $ir = min($ix + $v->innerWidth(), $right);
        $it = max($iy, $top);
        $ib = min($iy + $v->innerHeight(), $bottom);
        $rt = max($it, $by1);
        $rb = min($ib, $by2);
        if ($il < $ir && $rt < $rb) {
            $source = $v->source;
            $held = $source->heldLines();
            if ($held !== null) {
                $this->holding = true;
            }
            $lines = $held ?? $source->ownLines();
            $sourceRows = \count($lines);
            $firstRow = $v->top();
            $firstCol = $v->left();
            $selection = $v->selectionRange();
            $views = $source->viewports();
            $sx = $ix - $firstCol;
            $sy = $iy - $firstRow;
            $cl = max($il, $sx);
            $ct = max($it, $sy);
            $cr = min($ir, $sx + $source->cols());
            $cb = min($ib, $sy + $source->rows());
            // what the source's windows cover of each row: its own cells are not copied there
            $covered = [];
            foreach ($views as $cv) {
                [$vx, $vy, $vw, $vh] = $cv->rect();
                $x1 = max($sx + $vx, $cl);
                $x2 = min($sx + $vx + $vw, $cr);
                if ($x1 < $x2) {
                    for ($y = max($sy + $vy, $ct, $rt), $y2 = min($sy + $vy + $vh, $cb, $rb); $y < $y2; $y++) {
                        $covered[$y][] = [$x1, $x2];
                    }
                }
            }
            $plain = Style::plain();
            for ($y = $rt; $y < $rb; $y++) {
                $row = $firstRow + $y - $iy;
                $line = $row >= 0 ? ($row < $sourceRows ? $lines[$row] : null) : $source->scrollbackLine($row);
                $spans = isset($covered[$y]) ? self::uncovered($il, $ir, $covered[$y]) : [[$il, $ir]];
                foreach ($spans as [$a, $z]) {
                    $n = $z - $a;
                    if ($line === null) {
                        Area::put($chars[$y], $styles[$y], $a, array_fill(0, $n, ' '), array_fill(0, $n, $plain));
                    } else {
                        [$c, $s] = Area::cut($line->chars, $line->styles, $firstCol + $a - $ix, $n);
                        Area::put($chars[$y], $styles[$y], $a, $c, $s);
                    }
                }
            }
            // the windows the source hosts, clipped by this window and by the source's edges
            if ($views !== []) {
                $this->blitAll($chars, $styles, $views, $sx, $sy, $cl, $ct, $cr, $cb, $by1, $by2);
            }
            // a selection shows in inverse, over all the window shows (its windows too)
            if ($selection !== null) {
                for ($y = $rt; $y < $rb; $y++) {
                    $row = $firstRow + $y - $iy;
                    for ($x = $il; $x < $ir; $x++) {
                        if ($chars[$y][$x] === '' && $x > $il) {
                            $styles[$y][$x] = $styles[$y][$x - 1];   // (a wide character is shown in the style of its first half)
                        } elseif (Viewport::inSelection($selection, $firstCol + $x - $ix, $row)) {
                            $st = $styles[$y][$x];
                            $styles[$y][$x] = $st->has(Style::INVERSE) ? $st->without(Style::INVERSE) : $st->with(Style::INVERSE);
                        }
                    }
                }
            }
        }
        foreach ($shades as [$sx, $sy, $sw, $sh, $color]) {
            $x1 = max($left, $sx);
            $x2 = min($right, $sx + $sw);
            for ($y = max($from, $sy), $y2 = min($to, $sy + $sh); $y < $y2; $y++) {
                $row = &$styles[$y];
                for ($x = $x1; $x < $x2; $x++) {
                    $row[$x] = Area::shade($row[$x], $color);
                }
                // (a wide character is shown in the style of its first half, also across the edge)
                if ($x1 > 0 && $x1 < $x2 && $chars[$y][$x1] === '') {
                    $row[$x1] = $row[$x1 - 1];
                }
                if ($x2 > $x1 && ($chars[$y][$x2] ?? null) === '') {
                    $row[$x2] = $row[$x2 - 1];
                }
                unset($row);
            }
        }
    }

    /** The frame's row $y (compose() first). */
    public function frameLine(int $y): Line
    {
        return $this->frame[$y];
    }

    /** @return array<int, int> the version at which each row of the frame last changed */
    public function frameVersions(): array
    {
        return $this->rowVersion;
    }

    /** A window holds back a synchronized update: the frame shows its previous cells. */
    public function holding(): bool
    {
        return $this->holding;
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
            if ($view->followsOnInput()) {
                $view->follow();
            }
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
                $this->surface->relay('clipboard', $text);
            }
        }
        return true;
    }
}
