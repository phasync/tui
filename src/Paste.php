<?php

namespace phasync\Tui;

/** Text pasted into the terminal (bracketed paste), delivered as one event rather than as keys. */
final class Paste implements Event
{
    /** A paste of $text. */
    public function __construct(
        /** The pasted text, with line breaks as "\n". */
        public readonly string $text,
    ) {
    }
}
