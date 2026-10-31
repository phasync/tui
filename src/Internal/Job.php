<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Component;

/**
 * @internal A coroutine started with `Tui::go()`: the program (root component) it belongs to,
 * the one running when it was started or else the next one that starts, and the signal that
 * wakes its `Tui::sleep()` when that program ends.
 */
final class Job
{
    /** @var \WeakReference<Component>|null */
    public ?\WeakReference $program = null;
    public bool $ended = false;
    public readonly Signal $wake;

    public function __construct()
    {
        $this->wake = new Signal();
    }

    /** The program ended: the job's sleep ends with `Quit`, now and from now on. */
    public function end(): void
    {
        $this->ended = true;
        $this->wake->notify();
    }
}
