<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Ansi;
use phasync\Tui\Layout;
use phasync\Tui\Style;
use phasync\Tui\Surface;

/**
 * @internal The layout of one container of an element tree (`Column`, `Row`, `Layers`, or the
 * inside of a `Box`): places its items' surfaces on the host, frames them, fills nested
 * surfaces with their own layouts and paints text, on every arrange (so also on resizes).
 *
 * A container item that only arranges (LayoutItem::$flat: no frame, background, handler or
 * focus look of its own) has no window: its items are placed on this host, in its rectangle.
 * Every window is a level of compositing (its host parses what it writes), so a `Column` of
 * `Row`s of `Text` costs one level, not three.
 */
final class ElementLayout implements Layout
{
    public const COLUMN = 'column';
    public const ROW = 'row';
    public const LAYERS = 'layers';
    public const SINGLE = 'single';

    /** @var \WeakMap<Surface, string>|null the fill last written on each host */
    private static ?\WeakMap $filled = null;
    /** @var \WeakMap<Surface, array<int, LayoutItem>>|null the items placed on each host last, by their surface */
    private static ?\WeakMap $placed = null;
    /** @var \WeakMap<Surface, array{Surface, int, int, int, int}>|null the surface of each flat container: the host its items are on, and its rectangle there */
    private static ?\WeakMap $flatAt = null;

    /**
     * @param list<LayoutItem>       $items
     * @param array{int,int,int,int} $padding    top, right, bottom, left: blank cells inside the host
     * @param Style|null             $fill       the host's background, written when it changed
     * @param string                 $alignItems COLUMN/ROW: the items across: stretch, start, center, end
     * @param string                 $justify    COLUMN/ROW: the items along: start, center, end, between
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $items,
        public readonly int $gap = 0,
        public readonly array $padding = [0, 0, 0, 0],
        public readonly ?Style $fill = null,
        public readonly string $alignItems = 'stretch',
        public readonly string $justify = 'start',
    ) {
    }

    public function arrange(Surface $host): void
    {
        $this->fill($host);
        // an item placed here before, the same and in the same place, stays as it is: only one
        // with a component below that may want another size now (stale) is arranged again
        self::$placed ??= new \WeakMap();
        $now = [];
        $this->arrangeAt($host, 0, 0, $host->cols(), $host->rows(), self::$placed[$host] ?? [], $now);
        self::$placed[$host] = $now;
        if ($this->kind === self::LAYERS) {
            // the hosting order is the z-order: keep it the layers' order
            $order = array_map(fn(LayoutItem $item) => $item->surface, $this->items);
            $current = [];
            foreach ($host->viewports() as $v) {
                if (\in_array($v->source, $order, true)) {
                    $current[] = $v->source;
                }
            }
            if ($current !== $order) {
                foreach ($order as $surface) {
                    $host->raise($surface);
                }
            }
        }
    }

    /**
     * The surface of a flat container (see LayoutItem::$flat) and where it is: the host its
     * items are on and its rectangle there, or null when it has a window of its own (or is not
     * shown). For `Mouse::over()`, which lists it where the pointer is in that rectangle.
     *
     * @return array{Surface, int, int, int, int}|null
     */
    public static function flatAt(Surface $surface): ?array
    {
        return self::$flatAt[$surface] ?? null;
    }

    /**
     * Place the items in the rectangle at ($ox, $oy), $cols x $rows, of $host; a flat item's
     * items are placed the same way in its own rectangle.
     *
     * @param array<int, LayoutItem> $before the items placed on $host by its last arrange
     * @param array<int, LayoutItem> $now    the items placed on it by this one
     */
    private function arrangeAt(Surface $host, int $ox, int $oy, int $cols, int $rows, array $before, array &$now): void
    {
        $rects = $this->rects($cols, $rows);
        foreach ($this->items as $i => $item) {
            $id = spl_object_id($item->surface);
            // (the margin is room of the item's that it leaves blank)
            [$mt, $mr, $mb, $ml] = $item->margin;
            $rects[$i] = [$ox + $rects[$i][0] + $ml, $oy + $rects[$i][1] + $mt, max(0, $rects[$i][2] - $ml - $mr), max(0, $rects[$i][3] - $mt - $mb)];
            [$x, $y, $w, $h] = $rects[$i];
            if ($this->flattens($item)) {
                self::$flatAt ??= new \WeakMap();
                self::$flatAt[$item->surface] = [$host, $x, $y, $w, $h];
                if ($item->surface->viewports() !== []) {
                    $item->surface->giveUp();      // (it had a window of its own before: its items move here)
                }
                $item->stale = false;
                $item->layout->arrangeAt($host, $x, $y, $w, $h, $before, $now);
                continue;
            }
            if (self::$flatAt !== null) {
                unset(self::$flatAt[$item->surface]);
            }
            $view = ($before[$id] ?? null) === $item ? $host->viewport($item->surface) : null;
            if ($view !== null && $view->x() === $x && $view->y() === $y && $view->width() === $w && $view->height() === $h
                && $view->hasBorder() === ($item->border !== false) && $view->title() === $item->title && $view->titleAlign() === $item->titleAlign) {
                $host->host($item->surface);
                if ($item->layout !== null && ($item->stale || $item->surface->layout() !== $item->layout)) {
                    $item->stale = false;
                    $item->surface->setLayout($item->layout);
                }
            } else {
                self::place($host, $item, ...$rects[$i]);
            }
            $now[$id] = $item;
        }
    }

    /**
     * True if $item is placed without a window: a flat container in a column, row or box (not
     * in layers, whose windows' hosting order is their z-order) whose background is this one's.
     */
    private function flattens(LayoutItem $item): bool
    {
        return $item->flat && $this->kind !== self::LAYERS && $item->layout !== null && $item->layout->kind !== self::LAYERS
            && $item->layout->fill === $this->fill && $item->border === false && $item->shadow === null && $item->title === '';
    }

    /** True if an item has a component below it that may want another size (it is arranged again). */
    public function stale(): bool
    {
        foreach ($this->items as $item) {
            if ($item->stale || ($item->flat && $item->layout?->stale())) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fewest rows the items need at $cols wide without being squeezed (see
     * LayoutItem::least()): what a `ScrollView` makes its content at least as high as.
     */
    public function least(int $cols): int
    {
        $least = 0;
        [$t, $r, $b, $l] = $this->padding;
        $inner = max(0, $cols - $l - $r);
        switch ($this->kind) {
            case self::SINGLE:
                foreach ($this->items as $item) {
                    $least = max($least, $item->least($inner));
                }
                break;
            case self::COLUMN:
                foreach ($this->items as $item) {
                    $least += \is_int($item->height) ? $item->height + $item->margin[0] + $item->margin[2] : $item->least($inner);
                }
                $least += $this->gap * max(0, \count($this->items) - 1);
                break;
            case self::ROW:
                foreach ($this->rects($cols, LayoutItem::TALL) as $i => [, , $w]) {
                    $item = $this->items[$i];
                    $least = max($least, \is_int($item->height) ? $item->height + $item->margin[0] + $item->margin[2] : $item->least($w));
                }
                break;
            default:
                foreach ($this->items as $item) {
                    $least = max($least, \is_int($item->height) ? $item->height + $item->margin[0] + $item->margin[2] : $item->least($inner));
                }
        }
        return $least + $t + $b;
    }

    /**
     * Where each item goes on a host of $cols x $rows, as [x, y, width, height].
     *
     * @return list<array{int,int,int,int}>
     */
    public function rects(int $cols, int $rows): array
    {
        [$pt, $pr, $pb, $pl] = $this->padding;
        $cols = max(0, $cols - $pl - $pr);
        $rows = max(0, $rows - $pt - $pb);
        $rects = [];
        switch ($this->kind) {
            case self::SINGLE:
                foreach ($this->items as $item) {
                    $rects[] = [0, 0, $cols, $rows];
                }
                break;
            case self::COLUMN:
            case self::ROW:
                $across = $this->kind === self::ROW;
                $total = $across ? $cols : $rows;
                $n = \count($this->items);
                $room = max(0, $total - $this->gap * max(0, $n - 1));
                $sizes = [];
                foreach ($this->items as $item) {
                    $spec = $across ? $item->width : $item->height;
                    if ($spec === 'auto' && $item->grow !== null && $item->grow > 0) {
                        // flex-grow: a share of what is left, at least what it needs when that fits
                        $natural = $item->natural($across ? $room : $cols, $across ? $rows : $room);
                        $sizes[] = ['fr', $item->grow, $natural === null ? ($across ? 0 : $item->least($cols)) : $natural[$across ? 0 : 1]];
                        continue;
                    }
                    if ($spec === 'auto') {
                        // what the content needs, as a share capped at that (see Size::distribute())
                        $natural = $item->natural($across ? $room : $cols, $across ? $rows : $room);
                        if ($natural !== null && $item->shrink === 0.0) {
                            $sizes[] = ['cells', (float) $natural[$across ? 0 : 1]];     // flex-shrink 0: never less
                            continue;
                        }
                        // (one that takes any room down a column is given at least what its parts need, when that fits)
                        $sizes[] = $natural === null ? ['fr', 1.0, $across ? 0 : $item->least($cols)] : ['auto', (float) $natural[$across ? 0 : 1]];
                        continue;
                    }
                    $sizes[] = Size::parse($spec);
                }
                $parts = Size::distribute($sizes, $room);
                // justify-content: where the items go along when they leave room
                $left = max(0, $room - array_sum(array_map(fn($v) => max(0, $v), $parts)));
                $at = match ($this->justify) {
                    'center' => intdiv($left, 2),
                    'end' => $left,
                    default => 0,
                };
                $extra = $this->justify === 'between' && $n > 1 ? intdiv($left, $n - 1) : 0;
                $spare = $this->justify === 'between' && $n > 1 ? $left - $extra * ($n - 1) : 0;
                foreach ($parts as $i => $size) {
                    $size = max(0, $size);
                    // align-items: as large as its content across, placed at the start, middle or end
                    $off = 0;
                    $cross = $across ? $rows : $cols;
                    if ($this->alignItems !== 'stretch') {
                        $natural = $this->items[$i]->natural($across ? $size : $cols, $across ? $rows : $size);
                        $want = $natural === null ? $cross : min($cross, $natural[$across ? 1 : 0]);
                        $off = match ($this->alignItems) {
                            'center' => intdiv($cross - $want, 2),
                            'end' => $cross - $want,
                            default => 0,
                        };
                        $cross = $want;
                    }
                    $rects[] = $across ? [$at, $off, $size, $cross] : [$off, $at, $cross, $size];
                    $at += $size + $this->gap + $extra + ($i < $spare ? 1 : 0);
                }
                break;
            case self::LAYERS:
                foreach ($this->items as $item) {
                    $natural = $item->width === 'auto' || $item->height === 'auto' ? $item->natural($cols, $rows) : null;
                    $w = self::measure($item->width, $cols, $natural[0] ?? $cols);
                    $h = self::measure($item->height, $rows, $natural[1] ?? $rows);
                    [$x, $y] = match ($item->align) {
                        'center' => [intdiv($cols - $w, 2), intdiv($rows - $h, 2)],
                        'right' => [$cols - $w, 0],
                        default => [0, 0],
                    };
                    $rects[] = [$x, $y, max(0, $w), max(0, $h)];
                }
                break;
        }
        if ($pt + $pl > 0) {
            foreach ($rects as $i => [$x, $y, $w, $h]) {
                $rects[$i] = [$x + $pl, $y + $pt, $w, $h];
            }
        }
        return $rects;
    }

    /** Fill the host with the background when it changed (so the windows are not repainted for nothing). */
    private function fill(Surface $host): void
    {
        self::$filled ??= new \WeakMap();
        $style = $this->fill ?? Style::plain();
        $key = spl_object_id($style) . ':' . $host->cols() . 'x' . $host->rows();
        if ((self::$filled[$host] ?? ($style === Style::plain() ? $key : null)) === $key) {
            return;     // (a host never filled is blank already when the fill is the plain one)
        }
        self::$filled[$host] = $key;
        $host->write("\e7\e[?6l" . Ansi::style($style) . "\e[2J\e[0m\e8");
    }

    private static function measure(int|string $spec, int $total, int $natural): int
    {
        if ($spec === 'auto') {
            return min($total, $natural);
        }
        [$kind, $value] = Size::parse($spec);
        return min($total, $kind === 'cells' ? (int) $value : (int) floor($total * ($kind === 'percent' ? $value : 100) / 100));
    }

    private static function place(Surface $host, LayoutItem $item, int $x, int $y, int $w, int $h): void
    {
        $w = max(0, $w);
        $h = max(0, $h);
        $view = $host->host($item->surface, $x, $y, $w, $h);    // (at its place at once: nothing is blanked)
        if ($item->border !== false) {
            $view->setBorder($item->border, $item->borderStyle, $item->borderStyle);
        } elseif ($view->hasBorder()) {
            $view->setBorder(false);
        }
        $view->setTitle($item->title, $item->titleAlign);
        $view->setShadow($item->shadow);
        $view->move($x, $y)->resize($w, $h)->setFit(true);
        $item->stale = false;
        if ($item->layout !== null) {
            $item->surface->setLayout($item->layout);
        }
        if ($item->paint !== null && $w > 0 && $h > 0) {
            // (a window with no room shows nothing: it is painted when it gets some)
            ($item->paint)($item->surface);
        }
    }
}
