<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Component;
use phasync\Tui\Quit;

/**
 * @internal A coroutine started with `Tui::go()`: the program (root component) it belongs to,
 * the one running when it was started or else the next one that starts, its task (what
 * `fiber\go()` returned, for `fiber\cancel()`), and the signal that wakes its `Tui::sleep()`
 * when that program ends.
 */
final class Job
{
    /** @var \WeakReference<Component>|null */
    public ?\WeakReference $program = null;
    public bool $ended = false;
    /** What fiber\go() returned for it (null for the wait of a Tui::sleep() outside a job). */
    public ?object $task = null;
    public readonly Signal $wake;

    public function __construct()
    {
        $this->wake = new Signal();
    }

    /**
     * The program ended: the job is cancelled with a `Quit` as the reason, so the wait it is in
     * (any: `Tui::sleep()`, `fiber\sleep()`, an await, a stream) and every later one throws it;
     * on a runtime without cancellation its `Tui::sleep()` does, now and from now on. With
     * $running (the job is the code that ended the program: `Tui::quit()` in it, or its
     * exception) it is not cancelled: it goes on until its next `Tui::sleep()`, which throws, so
     * the waits of the quit itself are not cut short and its own exception is not taken for a
     * cancellation.
     */
    public function end(bool $running = false): void
    {
        $first = !$this->ended;
        $this->ended = true;
        $this->wake->notify();
        if ($first && !$running) {
            $this->cancel();
        }
    }

    /** Cancel the task with a `Quit` (see end()), once it is known. */
    public function cancel(): void
    {
        if ($this->task === null || !$this->ended) {
            return;
        }
        try {
            \fiber\cancel($this->task, new Quit());
        } catch (\fiber\NotSupportedException) {
            // (an adapter without cancellation: Tui::sleep() throws the Quit)
        }
    }
}
