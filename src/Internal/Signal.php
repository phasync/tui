<?php

namespace phasync\Tui\Internal;

use fiber\Deferred;

use function fiber\await;

/**
 * @internal A condition variable for coroutines: wait() suspends until the next notify() or the
 * timeout. Each waiter has its own Deferred that is dropped when the wait ends, so waiting in a
 * loop does not pile up listeners or racer tasks (as await_any() over a long-lived Deferred would).
 *
 * Use it as `while (!$condition) { $signal->wait(); }`; coroutines are not preempted between the
 * check and the wait, so no notification is lost.
 *
 * **Timeouts share one clock.** A wait with a timeout does not give the runtime a timer of its
 * own (`fiber\await($d, $timeout)` costs about four times a `fiber\sleep()` on the phasync
 * adapter): every timed wait is a deadline in one list, and one coroutine of this class sleeps
 * until the earliest (50 ms at most, then it looks again) and ends the waits that are due, those
 * due within a millisecond with them.
 * So the coroutines of fifty components that sleep in step (a 10 Hz loop each) wake in the same
 * moment and their renders are one frame, and the clock runs only while someone waits.
 */
final class Signal
{
    /** Waits due this close to the earliest end with it (seconds): coroutines in step wake together. */
    private const TOGETHER = 0.001;
    /** The longest the clock sleeps at a time (seconds): it looks again whether anyone still waits. */
    private const LONGEST = 0.05;

    /** @var array<int, Deferred> */
    private array $waiters = [];

    /** @var array<int, array{float, Deferred}> the timed waits of every Signal: deadline and what ends the wait */
    private static array $deadlines = [];
    /** When the clock's coroutine wakes next (INF: it is not running). */
    private static float $clockAt = \INF;
    /** Numbers the clock's coroutines: only the latest goes on after it woke. */
    private static int $clock = 0;

    /** Wait for notify(). Returns false if the timeout expired first. */
    public function wait(float $timeout = \PHP_FLOAT_MAX): bool
    {
        $d = new Deferred();
        $id = spl_object_id($d);
        $this->waiters[$id] = $d;
        try {
            if ($timeout >= \PHP_FLOAT_MAX) {
                await($d);
                return true;
            }
            self::at(microtime(true) + max(0.0, $timeout), $d);
            return await($d) === true;
        } finally {
            unset($this->waiters[$id], self::$deadlines[$id]);
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
            $d->resolve(true);
        }
    }

    public function hasWaiters(): bool
    {
        return $this->waiters !== [];
    }

    /** End the wait on $d (resolving it with false) at $deadline, unless it ended before. */
    private static function at(float $deadline, Deferred $d): void
    {
        self::$deadlines[spl_object_id($d)] = [$deadline, $d];
        if ($deadline < self::$clockAt - self::TOGETHER) {
            // earlier than the clock wakes: a clock for this deadline (the other one ends when it wakes)
            $at = self::$clockAt = min($deadline, microtime(true) + self::LONGEST);
            $n = ++self::$clock;
            \fiber\go(static function () use ($n, $at): void {
                self::tick($n, $at);
            });
        }
    }

    /**
     * The clock: sleep until $at (a while at most, so a clock whose waits ended early does not
     * keep the program running), end the waits that are due, and on to the next deadline.
     */
    private static function tick(int $n, float $at): void
    {
        while (true) {
            \fiber\sleep(max(0.0, $at - microtime(true)));
            $now = microtime(true);
            $next = \INF;
            foreach (self::$deadlines as $id => [$deadline, $d]) {
                if ($deadline <= $now + self::TOGETHER) {
                    unset(self::$deadlines[$id]);
                    $d->resolve(false);
                } elseif ($deadline < $next) {
                    $next = $deadline;
                }
            }
            if ($n !== self::$clock) {
                return;     // (a later clock keeps the time)
            }
            if ($next === \INF) {
                self::$clockAt = \INF;
                return;
            }
            self::$clockAt = $at = min($next, microtime(true) + self::LONGEST);
        }
    }
}
