<?php

/**
 * A whole program in one file, without a class: a state, a coroutine that changes it, and a
 * closure that shows it. The closure is rendered again whenever the state it read changes.
 *
 *     php examples/working.php          # inline: a live region under the shell's output
 *     php examples/working.php --full   # the same screen, full screen
 *
 * Ctrl-Q quits. The counter is a coroutine of the program (`Tui::go()`): its `Tui::sleep()`
 * throws `Quit` once the screen has quit, which ends it, so `fiber\run()` returns at once.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Tui;

$full = in_array('--full', $argv, true);
$s = new State(['done' => 0]);
fiber\run(function () use ($s, $full) {
    Tui::go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { Tui::sleep(0.05); $s->done = $i; } });
    $screen = fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
        new Help(),
    ]);
    $full ? Tui::run($screen) : Tui::inline($screen);
});
