<?php

namespace phasync\Tui;

/**
 * The cursor of a Surface, as returned by `Surface::cursor()`: a snapshot, not a live object.
 */
final class Cursor
{
    public const BLOCK = 'block';
    public const UNDERLINE = 'underline';
    public const BAR = 'bar';

    /** A snapshot of a cursor. */
    public function __construct(
        /** Column, 0-based. */
        public readonly int $x,
        /** Row, 0-based. */
        public readonly int $y,
        /** False when the program hid the cursor (DECTCEM, `CSI ? 25 l`). */
        public readonly bool $visible,
        /** `Cursor::BLOCK`, `Cursor::UNDERLINE` or `Cursor::BAR`, as set with DECSCUSR. */
        public readonly string $shape = self::BLOCK,
        /** Whether the program asked for a blinking cursor. */
        public readonly bool $blinking = true,
    ) {
    }
}
