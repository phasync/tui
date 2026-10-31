<?php

namespace phasync\Tui;

/**
 * Thrown by `Tui::sleep()` in a coroutine of a program (`Tui::go()`) when the program has ended
 * (Ctrl-Q, `Tui::quit()`, the root disposed): the coroutine's cue to stop. Let it end the
 * coroutine (`Tui::go()` takes it as the coroutine's normal end), or catch it to clean up
 * first:
 *
 *     Tui::go(function () use ($s) {
 *         try {
 *             while (true) { Tui::sleep(0.1); $s->done = poll(); }
 *         } catch (Quit) {
 *             saveProgress($s);
 *         }
 *     });
 */
final class Quit extends \RuntimeException
{
    public function __construct(string $message = 'The program has ended')
    {
        parent::__construct($message);
    }
}
