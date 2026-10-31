<?php

namespace phasync\Tui;

/**
 * Why a coroutine of a program (`Tui::go()`) ends when the program has ended (Ctrl-Q,
 * `Tui::quit()`, the root disposed): the coroutine is cancelled (`fiber\cancel()`) with a `Quit`
 * as the reason, so the wait it is in throws it (`Tui::sleep()`, `fiber\sleep()`, an
 * `fiber\await()`, a stream wait), and so does every wait after it. Let it end the coroutine
 * (`Tui::go()` takes it as the coroutine's normal end, under every runner), or catch it to clean
 * up first, without waiting (a wait throws it again). Outside a `Tui::go()` coroutine
 * `Tui::sleep()` returns early instead, since nothing there would take the exception.
 *
 *     Tui::go(function () use ($s) {
 *         try {
 *             while (true) { Tui::sleep(0.1); $s->done = poll(); }
 *         } catch (Quit) {
 *             saveProgress($s);       // (no waiting here)
 *         }
 *     });
 *
 * On a runtime whose adapter cannot cancel (`fiber\cancel()` throws `fiber\NotSupportedException`),
 * only `Tui::sleep()` throws it.
 */
final class Quit extends \RuntimeException implements \fiber\CancelledException
{
    public function __construct(string $message = 'The program has ended')
    {
        parent::__construct($message);
    }
}
