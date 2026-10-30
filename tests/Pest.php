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
