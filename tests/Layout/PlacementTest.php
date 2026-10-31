<?php

/*
 * Placement::for(): where a floating window goes, a pure function of the anchor, the root, the
 * desired size and the preference. Never outside the root.
 */

use phasync\Tui\Placement;
use phasync\Tui\Rect;

function placed(Rect $r): array
{
    return [$r->x, $r->y, $r->width, $r->height];
}

it('places a window by the table of cases', function (array $anchor, array $root, array $desired, string|array $place, array $expected) {
    $p = \is_array($place) ? new Rect(...$place) : $place;
    $rect = Placement::for(new Rect(...$anchor), new Rect(...$root), $desired, $p);
    expect(placed($rect))->toBe($expected);
    // the hard boundary
    [$rx, $ry, $rw, $rh] = $root;
    expect($rect->x)->toBeGreaterThanOrEqual($rx)->and($rect->y)->toBeGreaterThanOrEqual($ry)
        ->and($rect->x + $rect->width)->toBeLessThanOrEqual($rx + $rw)->and($rect->y + $rect->height)->toBeLessThanOrEqual($ry + $rh);
})->with([
    'below, fits'                          => [[5, 2, 10, 1], [0, 0, 80, 24], [20, 6], 'below', [5, 3, 20, 6]],
    'above, fits'                          => [[5, 10, 10, 1], [0, 0, 80, 24], [20, 6], 'above', [5, 4, 20, 6]],
    'bottom row: flips above'              => [[5, 23, 10, 1], [0, 0, 80, 24], [20, 6], 'below', [5, 17, 20, 6]],
    'top row: flips below'                 => [[5, 0, 10, 1], [0, 0, 80, 24], [20, 6], 'above', [5, 1, 20, 6]],
    'right edge: shifted left'             => [[70, 2, 10, 1], [0, 0, 80, 24], [20, 6], 'below', [60, 3, 20, 6]],
    'wider than the root: narrowed'        => [[3, 2, 10, 1], [0, 0, 30, 24], [50, 6], 'below', [0, 3, 30, 6]],
    'no side fits: the larger one, shrunk' => [[5, 9, 10, 1], [0, 0, 80, 24], [20, 30], 'below', [5, 10, 20, 14]],
    'no side fits: above has more'         => [[5, 15, 10, 1], [0, 0, 80, 24], [20, 30], 'below', [5, 0, 20, 15]],
    'equal room: the preferred side'       => [[0, 5, 10, 2], [0, 0, 80, 12], [20, 30], 'above', [0, 0, 20, 5]],
    'no room on either side: over it'      => [[0, 0, 80, 24], [0, 0, 80, 24], [20, 6], 'below', [0, 0, 20, 6]],
    'terminal smaller than the window'     => [[2, 1, 4, 1], [0, 0, 10, 4], [40, 12], 'below', [0, 2, 10, 2]],
    'center'                               => [[0, 0, 1, 1], [0, 0, 80, 24], [40, 10], 'center', [20, 7, 40, 10]],
    'center, larger than the root'         => [[0, 0, 1, 1], [0, 0, 30, 8], [60, 12], 'center', [0, 0, 30, 8]],
    'a rect, shifted inside'               => [[0, 0, 1, 1], [0, 0, 80, 24], [1, 1], [75, 20, 10, 6], [70, 18, 10, 6]],
    'a root not at 0,0'                    => [[12, 8, 4, 1], [10, 5, 20, 5], [8, 3], 'below', [12, 5, 8, 3]],
]);

it('refuses another placement', function () {
    Placement::for(new Rect(0, 0, 1, 1), new Rect(0, 0, 10, 10), [2, 2], 'left');
})->throws(InvalidArgumentException::class);
