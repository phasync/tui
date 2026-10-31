<?php

use phasync\Tui\Surface;

/** A surface of the given size after writing $bytes into it. */
function vt(string $bytes, int $cols = 20, int $rows = 5): Surface
{
    $s = new Surface($cols, $rows);
    $s->write($bytes);
    return $s;
}

/** The visible text of a surface with every row padded to its width, joined with "|". */
function rows(Surface $s): string
{
    $out = [];
    for ($y = 0; $y < $s->rows(); $y++) {
        $line = $s->line($y);
        $out[] = $line . str_repeat(' ', max(0, $s->cols() - mb_strwidth($line)));
    }
    return implode('|', $out);
}

/** [x, y] of the cursor. */
function cursorAt(Surface $s): array
{
    return [$s->cursor()->x, $s->cursor()->y];
}

/**
 * Start $component on a FakeTerminal of $cols x $rows, run $test($terminal, $component) in
 * fiber\run(), detach afterwards (also when the test fails, so run() returns).
 */
function mountOn(\phasync\Tui\Component $component, int $cols, int $rows, Closure $test): void
{
    \fiber\run(function () use ($component, $cols, $rows, $test) {
        $t = new \phasync\Tui\FakeTerminal($cols, $rows);
        $component->start($t);
        $t->run(fn() => $test($t, $component));
    });
}

/**
 * Let the frame that follows a change render: on a terminal, renders go with its next frame
 * (at most 60 a second); elsewhere they are batched into the next tick.
 */
function frame(): void
{
    \fiber\sleep(1 / 60 + 0.005);
}

/** The rows of what a FakeTerminal shows, trailing blanks cut. */
function screenRows(\phasync\Tui\FakeTerminal $t): array
{
    return array_map('rtrim', explode("\n", $t->text()));
}

/** What $s looks like: `Surface::dump(true)` (each blank in the style it looks like). */
function seen(Surface $s): string
{
    return $s->dump(true);
}

/** What the program drew: the root's cells as `Surface::dump()` shows them, once the terminal drew what is pending. */
function drawn(\phasync\Tui\FakeTerminal $t): string
{
    $t->refresh();
    return $t->root()->dump();
}
