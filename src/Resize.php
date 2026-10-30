<?php

namespace phasync\Tui;

/** A surface changed size: what `on('resize')` listeners and `Component::onResize()` receive. */
final class Resize
{
    /** The new size, in columns and rows. */
    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
    }
}
