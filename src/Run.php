<?php

namespace phasync\Tui;

/**
 * A run of cells on one row that share a style, as returned by `Surface::runs()`.
 */
final class Run
{
    /** A run of cells. */
    public function __construct(
        /** Column of the first cell. */
        public readonly int $x,
        /** The characters (a wide character appears once but counts two columns). */
        public readonly string $text,
        /** Number of columns the run covers. */
        public readonly int $width,
        public readonly Style $style,
    ) {
    }
}
