<?php

namespace phasync\Tui;

/**
 * A mouse event: a button pressed or released, the mouse dragged or moved, the wheel turned.
 *
 * Coordinates are 0-based cells: on the screen when the Screen receives it, relative to the
 * component's area when a component does (`at()` makes the local copy).
 */
final class Mouse implements Event
{
    public const PRESS = 'press';
    public const RELEASE = 'release';
    /** The mouse moved with a button held. */
    public const DRAG = 'drag';
    /** The mouse moved with no button held (only reported in any-motion mode 1003). */
    public const MOVE = 'move';
    public const WHEEL_UP = 'wheel-up';
    public const WHEEL_DOWN = 'wheel-down';
    public const WHEEL_LEFT = 'wheel-left';
    public const WHEEL_RIGHT = 'wheel-right';

    public const LEFT = 0;
    public const MIDDLE = 1;
    public const RIGHT = 2;

    /** A mouse event; build them to test mouse handling or to send them with `Surface::dispatch()`. */
    public function __construct(
        /** One of the action constants: `Mouse::PRESS`, `Mouse::DRAG`, `Mouse::WHEEL_UP`... */
        public readonly string $action,
        /** Column, 0-based. */
        public readonly int $x,
        /** Row, 0-based. */
        public readonly int $y,
        /** `Mouse::LEFT`, `MIDDLE` or `RIGHT` for presses, releases and drags; null otherwise or when unknown. */
        public readonly ?int $button = null,
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
    ) {
    }

    /** The same event at another position (for coordinates relative to a window). */
    public function at(int $x, int $y): Mouse
    {
        return new Mouse($this->action, $x, $y, $this->button, $this->ctrl, $this->alt, $this->shift);
    }

    /** True for the wheel actions. */
    public function isWheel(): bool
    {
        return str_starts_with($this->action, 'wheel');
    }
}
