<?php

namespace phasync\Tui;

/**
 * One cell of a Surface, as returned by `Surface::cell()`.
 *
 * A wide character (most CJK ideographs, many emoji) occupies two cells: the first holds the
 * character with `width` 2, the second is a continuation with `char` '' and `width` 0.
 * Combining marks are part of the character they follow, so `char` can hold several code points.
 */
final class Cell
{
    public function __construct(
        /** The grapheme in the cell: ' ' when blank, '' for the second half of a wide character. */
        public readonly string $char,
        /** Columns the character occupies: 1, 2 for a wide character, 0 for a continuation cell. */
        public readonly int $width,
        /** Colors and attributes. */
        public readonly Style $style,
    ) {
    }
}
