<?php

namespace phasync\Tui;

/**
 * Focus changed: the terminal window gained or lost focus (focus reporting, mode 1004), or, when
 * delivered to a component, the component became or stopped being the focus owner.
 */
final class Focus implements Event
{
    /** A focus change. */
    public function __construct(
        /** True when focus was gained, false when it was lost. */
        public readonly bool $focused,
    ) {
    }
}
