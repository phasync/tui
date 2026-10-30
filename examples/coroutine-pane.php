<?php

/**
 * A shell next to a PHP coroutine that prints: both are producers writing into a Surface.
 *
 *     php examples/coroutine-pane.php
 *     php -d extension=phasync.so examples/coroutine-pane.php     # with ext-phasync: echo is captured
 *
 * The right pane runs a closure with `Surface::run()`. With ext-phasync loaded, what it prints
 * with `echo` lands in the pane; without it, `echo` would go to the real terminal, so the closure
 * writes with `$surface->write()` instead. Either way its colors, `\r` progress bars and cursor
 * movement render as in a terminal, because the pane is one.
 *
 * Built from the lower layers only (no components): surfaces, a Split, a Pty, a Tty.
 * Ctrl-B Tab switches pane (or click one), Ctrl-B q quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Color;
use phasync\Tui\Key;
use phasync\Tui\Pty;
use phasync\Tui\Split;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Tty;

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "coroutine-pane needs a terminal\n");
    exit(1);
}

fiber\run(function () use ($tty) {
    $root = new Surface();
    $shell = new Surface();
    $php = new Surface();
    $root->setLayout(Split::horizontal()->add($shell)->add($php));
    $focus = Style::of(Color::CYAN, attrs: Style::BOLD);
    $root->host($shell)->setBorder(true, focusStyle: $focus)->setTitle('shell');
    $root->host($php)->setBorder(true, focusStyle: $focus)->setTitle(Surface::canCaptureOutput() ? 'php: echo' : 'php: $surface->write()');
    $root->setFocusOnClick(true);

    $prefix = false;
    $root->on('key', function (Key $key) use (&$prefix, $root, $tty) {
        if ($prefix) {
            $prefix = false;
            match (true) {
                $key->is('tab') => $root->focusNext(),
                $key->is('q') => $tty->detach(),
                $key->is('ctrl+b') => $root->focused()?->dispatch($key),
                default => null,
            };
            return true;
        }
        return $prefix = $key->is('ctrl+b');
    });

    $tty->attach($root);
    $pty = Pty::start(getenv('SHELL') ?: '/bin/sh', $shell);

    $php->run(function (Surface $pane) use ($tty) {
        // with ext-phasync echo is captured; without it, write to the surface
        $out = Surface::canCaptureOutput() ? fn(string $s) => print($s) : fn(string $s) => $pane->write($s);
        $out("\e[?25l");
        for ($round = 1; $tty->isAttached(); $round++) {
            $out("\e[1;36mRound $round\e[0m\r\n");
            foreach (['download' => 32, 'compile' => 33, 'test' => 35] as $task => $color) {
                for ($p = 0; $p <= 100 && $tty->isAttached(); $p += 4) {
                    $width = max(10, $pane->cols() - 22);
                    $done = intdiv($p * $width, 100);
                    $out(sprintf("\r%-9s [\e[%dm%s\e[0m%s] %3d%%", $task, $color, str_repeat('█', $done), str_repeat('·', $width - $done), $p));
                    fiber\sleep(0.03);
                }
                $out("\r\n");
            }
            $out("\e[32m✔ all done\e[0m, \e[1mbold\e[0m, \e[3mitalic\e[0m, \e[4munderline\e[0m, \e[38;2;255;128;0mtruecolor\e[0m\r\n\r\n");
            for ($i = 0; $i < 20 && $tty->isAttached(); $i++) {
                fiber\sleep(0.05);
            }
        }
    });

    $tty->wait();
    $pty->kill(1);
});
