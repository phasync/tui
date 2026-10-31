<?php

namespace phasync\Tui;

/**
 * A mouse event: a button pressed or released, the mouse dragged or moved, the wheel turned.
 *
 * Coordinates are 0-based cells: on the screen when the root receives it, relative to the
 * component's area when a component does, and to the element's when an element's `onMouse`
 * does (`at()` makes the local copy).
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

    /** @var (\Closure(): list<Element>)|null finds the elements under the mouse (see over()) */
    private ?\Closure $finder = null;

    /** The same event at another position (for coordinates relative to a window). */
    public function at(int $x, int $y): Mouse
    {
        $copy = new Mouse($this->action, $x, $y, $this->button, $this->ctrl, $this->alt, $this->shift);
        $copy->finder = $this->finder;
        return $copy;
    }

    /**
     * The elements under the mouse where this event happened, innermost first (a `Text`, the
     * `Box` around it, the `Column` around that; components too), for an element's `onMouse`:
     * the release of a drag that began on a card says which column it was dropped on
     * (`$mouse->over()` holds the column's `Box`, found by its `key` or `id`). Empty for an
     * event that did not come to an element's `onMouse`.
     *
     * @return list<Element>
     */
    public function over(): array
    {
        return $this->finder === null ? [] : ($this->finder)();
    }

    /** @internal This event, with $finder for over(). */
    public function withOver(\Closure $finder): Mouse
    {
        $copy = $this->at($this->x, $this->y);
        $copy->finder = $finder;
        return $copy;
    }

    /** True for the wheel actions. */
    public function isWheel(): bool
    {
        return str_starts_with($this->action, 'wheel');
    }
}
