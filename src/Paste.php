<?php

namespace phasync\Tui;

/** Text pasted into the terminal (bracketed paste), delivered as one event rather than as keys. */
final class Paste implements Event
{
    public function __construct(
        /** The pasted text, with line breaks as "\n". */
        public readonly string $text,
    ) {
    }
}
