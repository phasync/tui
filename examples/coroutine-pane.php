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
 * Built from the lower layers only (no components): surfaces, a Split in a Stack, a Pty, a Tty.
 * The bottom line shows the keys: Ctrl-B Tab switches pane (or click one), Ctrl-B q or
 * Ctrl-B Ctrl-Q quits. When the shell exits, a goodbye with its exit status floats over the
 * panes for three seconds (a window on top of the Stack), then the program ends.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Key;
use phasync\Tui\Pty;
use phasync\Tui\Split;
use phasync\Tui\Stack;
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
    $help = new Surface(80, 1);
    $panes = Split::horizontal()
        ->add($shell, title: 'shell')
        ->add($php, title: Surface::canCaptureOutput() ? 'php: echo' : 'php: $surface->write()');
    // the panes over the help line; the stack floats windows over both (the goodbye)
    $stack = (new Stack())->add(Split::vertical()->add($panes)->add($help, 1));
    $root->setLayout($stack);
    $root->setFocusOnClick(true);
    $showHelp = function (bool $prefix) use ($help): void {
        $keys = $prefix ? 'Tab switch  q quit  Ctrl-Q quit  Ctrl-B send Ctrl-B' : 'Ctrl-B Tab switch  Ctrl-B q quit  Ctrl-B Ctrl-Q quit';
        $help->write("\e[?25l\e[H\e[2K" . Ansi::styled(" $keys", Style::of(Color::BRIGHT_BLACK)));
    };
    $help->on('resize', fn() => $showHelp(false));
    $showHelp(false);

    $prefix = false;
    $root->on('key', function (Key $key) use (&$prefix, $root, $tty, $showHelp) {
        if ($prefix) {
            $prefix = false;
            $showHelp(false);
            match (true) {
                $key->is('tab') => $root->focusNext(),
                $key->is('q'), $key->is('ctrl+q') => $tty->detach(),
                $key->is('ctrl+b') => $root->focused()?->dispatch($key),
                default => null,
            };
            return true;
        }
        $prefix = $key->is('ctrl+b');
        if ($prefix) {
            $showHelp(true);
        }
        return $prefix;
    });

    $tty->attach($root);
    $shell->focus();
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

    // the shell exited (or the program quit, which hangs it up): say goodbye, then end
    fiber\go(function () use ($pty, $tty, $stack) {
        $code = fiber\await($pty->exited());
        if (!$tty->isAttached()) {
            return;
        }
        $bye = new Surface(38, 3);
        $bye->write("\e[?25l\r\n  The shell exited with status \e[1m$code\e[0m.");
        $stack->add($bye, width: 40, height: 5, title: 'goodbye');
        for ($i = 0; $i < 30 && $tty->isAttached(); $i++) {
            fiber\sleep(0.1);
        }
        $tty->detach();
    });

    $tty->wait();
    $pty->kill(1);
});
