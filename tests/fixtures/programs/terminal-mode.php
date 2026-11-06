<?php
// A program on the real terminal that writes to the file given as its argument whether its Tty
// uses PHP's Io\Terminal, and the terminal's mode (`stty -a`) before, while and after attached.
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Surface;
use phasync\Tui\Tty;

$file = $argv[1];
$stty = fn() => preg_replace('/\s+/', ' ', trim((string) shell_exec('stty -a < /dev/tty')));
$tty = new Tty();
$lines = ['native=' . ($tty->isNative() ? 'yes' : 'no'), 'before: ' . $stty()];
fiber\run(function () use ($tty, $stty, &$lines) {
    $tty->attach(new Surface());
    $lines[] = 'size: ' . implode('x', $tty->size());
    $lines[] = 'while: ' . $stty();
    $tty->detach();
});
$lines[] = 'after: ' . $stty();
file_put_contents($file, implode("\n", $lines) . "\n");
