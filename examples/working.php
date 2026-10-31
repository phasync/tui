<?php

/**
 * A whole program in one file, without a class: a state, a coroutine that changes it, and a
 * closure that shows it. The closure is rendered again whenever the state it read changes.
 *
 *     php examples/working.php          # inline: a live region under the shell's output
 *     php examples/working.php --full   # the same screen, full screen
 *
 * Ctrl-Q quits. `fiber\run()` returns when its coroutines have ended too, so the counter stops
 * when the screen has quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Tui;

$full = in_array('--full', $argv, true);
$s = new State(['done' => 0]);
$quit = false;
fiber\run(function () use ($s, $full, &$quit) {
    Tui::go(function () use ($s, &$quit) { for ($i = 1; $i <= 100 && !$quit; $i++) { fiber\sleep(0.05); $s->done = $i; } });
    $screen = fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
        new Text('<dim>ctrl+q quit</>'),
    ]);
    $full ? Tui::run($screen) : Tui::inline($screen);
    $quit = true;
});
