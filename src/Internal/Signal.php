<?php

namespace phasync\Tui\Internal;

use fiber\Deferred;
use fiber\TimeoutException;

use function fiber\await;

/**
 * @internal A condition variable for coroutines: wait() suspends until the next notify() or the
 * timeout. Each waiter has its own Deferred that is dropped when the wait ends, so waiting in a
 * loop does not pile up listeners or racer tasks (as await_any() over a long-lived Deferred would).
 *
 * Use it as `while (!$condition) { $signal->wait(); }`; coroutines are not preempted between the
 * check and the wait, so no notification is lost.
 */
final class Signal
{
    /** @var array<int, Deferred> */
    private array $waiters = [];

    /** Wait for notify(). Returns false if the timeout expired first. */
    public function wait(float $timeout = \PHP_FLOAT_MAX): bool
    {
        $d = new Deferred();
        $id = spl_object_id($d);
        $this->waiters[$id] = $d;
        try {
            await($d, $timeout);
            return true;
        } catch (TimeoutException) {
            return false;
        } finally {
            unset($this->waiters[$id]);
        }
    }

    /** Wake every coroutine waiting right now. */
    public function notify(): void
    {
        if ($this->waiters === []) {
            return;
        }
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $d) {
            $d->settle(true, null);
        }
    }

    public function hasWaiters(): bool
    {
        return $this->waiters !== [];
    }
}
