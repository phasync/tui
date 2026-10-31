<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Style;
use phasync\Tui\Surface;

/**
 * @internal One window of an element layout: the surface that shows an element, how large it
 * wants to be, its frame, and how to fill it (a nested layout, or text painted into it).
 */
final class LayoutItem
{
    /** @var (\Closure(int $cols, int $rows): ?array{int,int})|null the outer size its content needs; null: any */
    public ?\Closure $measure = null;
    /** @var (\Closure(Surface): void)|null draws the element into its surface once it has its size */
    public ?\Closure $paint = null;
    public ?ElementLayout $layout = null;
    public string|false $border = false;
    public ?Style $borderStyle = null;
    /** The shadow its window casts (see Viewport::setShadow()). */
    public ?int $shadow = null;
    public string $title = '';
    public string $titleAlign = 'left';
    public string $align = 'left';
    /** @var array{int,int,int,int} blank cells around it in its parent's room: top, right, bottom, left */
    public array $margin = [0, 0, 0, 0];
    /** The share (fr) of the room left along its parent that it takes (`flex-grow`), null for none. */
    public ?float $grow = null;
    /** 0: it keeps the size its content needs along its parent (`flex-shrink: 0`). */
    public ?float $shrink = null;
    /** Something in it takes the focus (a component). */
    public bool $focusable = false;
    /** The most cells its content size may take (`max-width`, `max-height`), null for no limit. */
    public ?int $maxWidth = null;
    public ?int $maxHeight = null;
    /** @var (\Closure(int $cols): int)|null least() of the content for an item without a layout (a component's) */
    public ?\Closure $least = null;
    /** @var array<string, array{int,int}|null> natural() by room, until invalidate() */
    private array $naturals = [];
    /** Something below changed size since the item was last arranged (see invalidate()). */
    public bool $stale = false;
    /**
     * A container that only arranges (a `Column` or `Row` with no frame, background, handler or
     * look of its own that the focus or the mouse changes): its items are placed straight on the
     * host of its parent's layout, without a window of its own (see ElementLayout::arrange()).
     */
    public bool $flat = false;

    public function __construct(
        public readonly Surface $surface,
        /** The size asked for: cells, '25%', '1fr' or 'auto'. */
        public int|string $width = 'auto',
        public int|string $height = 'auto',
    ) {
    }

    /**
     * The outer size the content needs within $cols x $rows, null when it takes any; capped by
     * `max-width`/`max-height` (an item that takes any room takes up to them).
     */
    public function natural(int $cols, int $rows): ?array
    {
        [$t, $r, $b, $l] = $this->margin;
        if ($t + $r + $b + $l > 0) {
            $n = $this->naturalInside(max(0, $cols - $l - $r), max(0, $rows - $t - $b));
            return $n === null ? null : [min($cols, $n[0] + $l + $r), min($rows, $n[1] + $t + $b)];
        }
        return $this->naturalInside($cols, $rows);
    }

    /** natural() without the margin. */
    private function naturalInside(int $cols, int $rows): ?array
    {
        // (the answer for a room stays the answer until a component below may need another size: invalidate())
        $key = $cols . ',' . $rows;
        if (\array_key_exists($key, $this->naturals)) {
            return $this->naturals[$key];
        }
        if (\count($this->naturals) > 16) {
            $this->naturals = [];
        }
        return $this->naturals[$key] = $this->measureNow($cols, $rows);
    }

    /**
     * A component below this item may need another size now (it was marked for a render): its
     * measures are taken again, and the layout that has it arranges it again (see
     * ElementLayout::arrange()). Items around a component that did not change keep their
     * measures and their arrangement, so a render of a long list arranges what changed.
     */
    public function invalidate(): void
    {
        $this->naturals = [];
        $this->stale = true;
    }

    /**
     * The fewest rows the item shows its content in at $cols wide without squeezing it: the
     * height it needs, or for one that takes any room (a field that fills, a log) what its
     * parts need, one row for each that fills. A `ScrollView` makes its content this high at
     * least, so that a form taller than the view is cropped and scrolls, its boxes whole.
     */
    public function least(int $cols): int
    {
        [$t, $r, $b, $l] = $this->margin;
        $n = $this->natural($cols, self::TALL);
        if ($n !== null) {
            return $n[1];
        }
        $border = $this->border === false ? 0 : 2;
        $inner = max(0, $cols - $border - $l - $r);
        $least = $this->layout?->least($inner) ?? ($this->least !== null ? ($this->least)($inner) : 1);
        return min($least + $border, $this->maxHeight ?? \PHP_INT_MAX) + $t + $b;
    }

    /** Rows to measure in for as high as content may be. */
    public const TALL = \PHP_INT_MAX >> 8;

    /** @var list<Surface>|null for tests: when a list, the surface of every item measured (not answered from what it knew) is added */
    public static ?array $measured = null;

    private function measureNow(int $cols, int $rows): ?array
    {
        if (self::$measured !== null) {
            self::$measured[] = $this->surface;
        }
        $n = $this->measure === null ? null : ($this->measure)(min($cols, $this->maxWidth ?? $cols), min($rows, $this->maxHeight ?? $rows));
        // a size given in cells is the size it needs (a Box of height 8 in a Row makes the row 8 high)
        if (\is_int($this->width) && \is_int($this->height)) {
            $n = [min($cols, $this->width), min($rows, $this->height)];
        } elseif ($n !== null && (\is_int($this->width) || \is_int($this->height))) {
            $n = [\is_int($this->width) ? min($cols, $this->width) : $n[0], \is_int($this->height) ? min($rows, $this->height) : $n[1]];
        }
        if ($this->maxWidth === null && $this->maxHeight === null) {
            return $n;
        }
        [$w, $h] = $n ?? [$cols, $rows];
        return [min($w, $this->maxWidth ?? $w), min($h, $this->maxHeight ?? $h)];
    }
}
