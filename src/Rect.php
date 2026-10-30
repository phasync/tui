<?php

namespace phasync\Tui;

/**
 * A rectangle of cells: the left column, the top row (0-based), a width and a height.
 * `Surface::area()` returns one, the place a surface shows on the root.
 */
final class Rect
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /** True when it covers no cell. */
    public function isEmpty(): bool
    {
        return $this->width <= 0 || $this->height <= 0;
    }

    /** True if column $x, row $y is inside. */
    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->width && $y >= $this->y && $y < $this->y + $this->height;
    }

    /** The part of this rectangle that is also inside $other (empty when they do not overlap). */
    public function intersect(Rect $other): Rect
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);
        $right = min($this->x + $this->width, $other->x + $other->width);
        $bottom = min($this->y + $this->height, $other->y + $other->height);
        return new Rect($x, $y, max(0, $right - $x), max(0, $bottom - $y));
    }
}
