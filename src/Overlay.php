<?php

namespace phasync\Tui;

use fiber\Deferred;

/**
 * A window floating over everything, opened with `Component::overlay()` (a dropdown's list, a
 * completion popup, a menu, a tooltip, a dialog): the handle to close it and to wait for it.
 *
 *     $list = $this->overlay(new Select($items, onActivate: fn($key) => $list->close($key)));
 *     $this->go(function () use ($list) {
 *         $picked = fiber\await($list->closed());     // the result, or null when cancelled
 *     });
 *
 * It closes with a result by `close()`; it is **cancelled** (closed with its cancel value:
 * null, or a `Dialog`'s `$cancel`) when its content component ends, when the component that
 * opened it is disposed or no longer shown, and, for one that is not modal, by Escape, a click
 * outside it and the opener, or the focus moving elsewhere. Closing disposes the content and
 * gives the focus back to where it was.
 */
final class Overlay
{
    private Deferred $closed;
    private bool $open = true;
    private mixed $result = null;
    /** @internal Where it shows, in the coordinates of the root component's surface. */
    public ?Rect $rect = null;
    /** @internal The focus path had reached the opener or the overlay since it opened. */
    public bool $wasFocused = false;

    /**
     * @internal Made by `Component::overlay()`.
     *
     * @param \Closure(Overlay, mixed): void $onClose the opener's part of closing it
     */
    public function __construct(
        /** The component shown: the element given to `overlay()` when it is a component. */
        public readonly Component $content,
        /** The anchor, in the opener's coordinates; null: the opener's whole rectangle. */
        public readonly ?Rect $anchor,
        /** Where it goes: `'below'`, `'above'`, `'center'` or a `Rect` in the opener's coordinates. */
        public readonly string|Rect $place,
        /** True if it takes all input until it is closed. */
        public readonly bool $modal,
        /** The result when it is cancelled. */
        public readonly mixed $cancel,
        /** The surface that had the keys before it opened. */
        public readonly ?Surface $focusBefore,
        private readonly \Closure $onClose,
    ) {
        $this->closed = new Deferred();
    }

    /** Close it with $result: `closed()` resolves with it, the content is disposed, the focus goes back. */
    public function close(mixed $result = null): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->result = $result;
        $this->closed->resolve($result);
        ($this->onClose)($this, $result);
    }

    /** @internal Close it with the cancel value. */
    public function cancel(): void
    {
        $this->close($this->cancel);
    }

    /** An awaitable that resolves with the result when it closes (`fiber\await($overlay->closed())`). */
    public function closed(): object
    {
        return $this->closed;
    }

    /** True until it is closed. */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /** The result it closed with (null while open). */
    public function result(): mixed
    {
        return $this->result;
    }
}
