<?php
// A program on the real terminal that quits while a frame is still on its way to the terminal
// (its writer waits for the terminal queue): what it had left must reach the alternate screen
// before the restore sequence, not the main screen after it.
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Surface;
use phasync\Tui\Tty;

$tty = new Tty();
$send = new ReflectionMethod($tty, 'send');     // (what a frame does, 280 KB of it)
fiber\run(function () use ($tty, $send) {
    $root = new Surface();
    $tty->attach($root);
    fiber\go(fn() => $send->invoke($tty, str_repeat("\e[HSPAM", 40000)));
    fiber\sleep(0.02);
    $tty->detach();
});
echo "done\n";
