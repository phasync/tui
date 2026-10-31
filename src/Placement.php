<?php

namespace phasync\Tui;

/**
 * Where a floating window goes: the decision `Component::overlay()` makes for a dropdown, a
 * completion list, a menu or a dialog, as a pure function of what it depends on, for widgets
 * that place something themselves.
 *
 *     $rect = Placement::for($field->surface->area(), new Rect(0, 0, $cols, $rows), [30, 8], 'below');
 *
 * The root's rectangle is a hard boundary: the result is always inside it. In order:
 *
 * - `'below'` / `'above'`: on that side of the anchor if the window fits there; else on the
 *   other side if it fits there; else on the side with more room, **shrunk to that room** (the
 *   content scrolls). When neither side has any room (the anchor fills the root) it covers the
 *   anchor. Horizontally it starts at the anchor's left edge, shifted left to stay inside, and is
 *   narrowed only when it is wider than the root. With `$keep` (a rectangle to leave
 *   uncovered, such as the buttons of the dialog the anchor is in), a window that fits on both
 *   sides goes to the other side when the preferred one would cover `$keep` and the other
 *   would not.
 * - `'center'`: in the middle of the root, shrunk to the root when larger.
 * - a `Rect`: that rectangle, shrunk to the root's size and shifted inside it.
 */
final class Placement
{
    private function __construct()
    {
    }

    /**
     * The rectangle to show a window of $desired size at, next to $anchor, inside $root (all in
     * the same coordinates, those of `Surface::area()`).
     *
     * @param array{int,int}     $desired [cols, rows] the window would like
     * @param 'below'|'above'|'center'|Rect $place the preferred side, the middle, or a rectangle
     * @param Rect|null $keep a rectangle to leave uncovered when the window fits on both sides
     * @throws \InvalidArgumentException for another $place
     */
    public static function for(Rect $anchor, Rect $root, array $desired, string|Rect $place = 'below', ?Rect $keep = null): Rect
    {
        $w = max(0, min($desired[0], $root->width));
        $h = max(0, min($desired[1], $root->height));
        $right = $root->x + $root->width;
        $bottom = $root->y + $root->height;
        if ($place instanceof Rect) {
            $w = min($place->width, $root->width);
            $h = min($place->height, $root->height);
            return new Rect(self::clamp($place->x, $root->x, $right - $w), self::clamp($place->y, $root->y, $bottom - $h), $w, $h);
        }
        if ($place === 'center') {
            return new Rect($root->x + intdiv($root->width - $w, 2), $root->y + intdiv($root->height - $h, 2), $w, $h);
        }
        if ($place !== 'below' && $place !== 'above') {
            throw new \InvalidArgumentException("Unknown placement '$place': use 'below', 'above', 'center' or a Rect");
        }
        // the anchor as far as it is inside the root
        $top = self::clamp($anchor->y, $root->y, $bottom);
        $end = self::clamp($anchor->y + $anchor->height, $top, $bottom);
        $room = ['below' => $bottom - $end, 'above' => $top - $root->y];
        $other = $place === 'below' ? 'above' : 'below';
        if ($h <= $room[$place] && $h <= $room[$other] && $keep !== null) {
            $x = self::clamp($anchor->x, $root->x, $right - $w);
            $at = fn(string $side) => new Rect($x, $side === 'below' ? $end : $top - $h, $w, $h);
            $side = !$at($place)->intersect($keep)->isEmpty() && $at($other)->intersect($keep)->isEmpty() ? $other : $place;
        } elseif ($h <= $room[$place]) {
            $side = $place;
        } elseif ($h <= $room[$other]) {
            $side = $other;
        } else {
            $side = $room[$other] > $room[$place] ? $other : $place;
            if ($room[$side] > 0) {
                $h = $room[$side];      // shrunk to the room; its content scrolls
            }
        }
        if ($room[$side] === 0) {
            // no room on either side: over the anchor
            $y = self::clamp($side === 'below' ? $top : $end - $h, $root->y, $bottom - $h);
        } else {
            $y = $side === 'below' ? $end : $top - $h;
        }
        return new Rect(self::clamp($anchor->x, $root->x, $right - $w), $y, $w, $h);
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }
}
