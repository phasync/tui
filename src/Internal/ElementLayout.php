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

    /**
     * @param list<LayoutItem>       $items
     * @param array{int,int,int,int} $padding top, right, bottom, left (SINGLE only)
     * @param Style|null             $fill    the host's background, written when it changed
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $items,
        public readonly int $gap = 0,
        public readonly array $padding = [0, 0, 0, 0],
        public readonly ?Style $fill = null,
    ) {
    }

    public function arrange(Surface $host): void
    {
        $cols = $host->cols();
        $rows = $host->rows();
        $this->fill($host);
        $rects = $this->rects($cols, $rows);
        // an item placed here before, the same and in the same place, stays as it is: only what
        // holds components looks again, since a component below may want another size now
        self::$placed ??= new \WeakMap();
        $before = self::$placed[$host] ?? [];
        $now = [];
        foreach ($this->items as $i => $item) {
            $id = spl_object_id($item->surface);
            [$x, $y, $w, $h] = $rects[$i];
            $view = ($before[$id] ?? null) === $item ? $host->viewport($item->surface) : null;
            if ($view !== null && $view->x() === $x && $view->y() === $y && $view->width() === $w && $view->height() === $h
                && $view->hasBorder() === ($item->border !== false) && $view->title() === $item->title) {
                $host->host($item->surface);
                if ($item->layout !== null && $item->focusable) {
                    $item->surface->setLayout($item->layout);
                }
            } else {
                self::place($host, $item, ...$rects[$i]);
            }
            $now[$id] = $item;
        }
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
     * Where each item goes on a host of $cols x $rows, as [x, y, width, height].
     *
     * @return list<array{int,int,int,int}>
     */
    private function rects(int $cols, int $rows): array
    {
        $rects = [];
        switch ($this->kind) {
            case self::SINGLE:
                [$t, $r, $b, $l] = $this->padding;
                foreach ($this->items as $item) {
                    $rects[] = [$l, $t, max(0, $cols - $l - $r), max(0, $rows - $t - $b)];
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
                    if ($spec === 'auto') {
                        // what the content needs, as a share capped at that (see Size::distribute())
                        $natural = $item->natural($across ? $room : $cols, $across ? $rows : $room);
                        $sizes[] = $natural === null ? ['fr', 1.0] : ['auto', (float) $natural[$across ? 0 : 1]];
                        continue;
                    }
                    $sizes[] = Size::parse($spec);
                }
                $at = 0;
                foreach (Size::distribute($sizes, $room) as $size) {
                    $size = max(0, $size);
                    $rects[] = $across ? [$at, 0, $size, $rows] : [0, $at, $cols, $size];
                    $at += $size + $this->gap;
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
        $view->setTitle($item->title);
        $view->move($x, $y)->resize($w, $h)->setFit(true);
        if ($item->layout !== null) {
            $item->surface->setLayout($item->layout);
        }
        if ($item->paint !== null) {
            ($item->paint)($item->surface);
        }
    }
}
