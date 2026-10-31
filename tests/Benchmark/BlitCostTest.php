<?php

/*
 * TODO-0.0.3-beta8 item 1 (SPEC 3, "Compositing is blitting", "One diff, at the end"): a frame
 * of a tree ten levels deep at 160x50 costs about a millisecond to compose, also when every
 * surface in it changed, and a one-cell change deep in it is a few dozen bytes to the terminal.
 * The numbers are in docs/surface.md, "Performance"; the budgets here leave room for a slow
 * machine.
 */

use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

/**
 * A root of 160x50 and a chain of ten nested surfaces, each a window of its host one cell in
 * from its edges with a border, and beside each a few leaves of coloured text.
 *
 * @return array{Surface, list<Surface>, list<Surface>} the root, the chain (deepest last), every surface
 */
function deepTree(): array
{
    $root = new Surface(160, 50);
    $root->write("\e[44m\e[2J\e[0m");
    $chain = [];
    $all = [$root];
    $host = $root;
    for ($level = 0; $level < 10; $level++) {
        $cols = $host->cols() - 4;
        $rows = $host->rows() - 3;
        $child = new Surface($cols, $rows, 0);
        $host->host($child, 2, 1, $cols + 2, $rows + 2)->setBorder('round')->setTitle("level $level");
        for ($i = 0; $i < 3; $i++) {
            $leaf = new Surface(12, 2, 0);
            $leaf->write("\e[3" . ($i + 1) . "mleaf $level.$i\r\n\e[1m中文 👍");
            $host->host($leaf, 3 + 14 * $i, $host->rows() - 2, 12, 1);
            $all[] = $leaf;
        }
        $child->write(str_repeat("\e[32mlevel $level text \e[0m", 4));
        $chain[] = $child;
        $all[] = $child;
        $host = $child;
    }
    return [$root, $chain, $all];
}

/** The median ms of $n composes of $root, $change() before each. */
function composeMedian(Surface $root, Closure $change, int $n = 30): float
{
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $change($i);
        $a = hrtime(true);
        $root->compose();
        $times[] = (hrtime(true) - $a) / 1e6;
    }
    sort($times);
    return $times[intdiv($n, 2)];
}

it('composes a frame of a tree ten levels deep at 160x50 in about a millisecond, whatever changed', function () {
    [$root, $chain, $all] = deepTree();
    $root->compose();
    expect($root->text())->toContain('level 9 text')->toContain('中文 👍')->toContain('level 9 ─');
    $deepest = end($chain);
    // one cell deep down, every surface, the root's own cells (all windows drawn as before)
    $one = composeMedian($root, fn(int $i) => $deepest->write("\e[H" . ($i % 10)));
    $every = composeMedian($root, function (int $i) use ($all) {
        foreach ($all as $s) {
            $s->write("\e[H\e[3" . ($i % 8) . 'm' . ($i % 10));
        }
    });
    $own = composeMedian($root, fn(int $i) => $root->write("\e[50;1H" . ($i % 10)));
    // (on the machine this was written on: about 1.5, 1.5 and 0.5 ms)
    expect($one)->toBeLessThan(4.0)->and($every)->toBeLessThan(6.0)->and($own)->toBeLessThan(3.0);
});

it('sends a few dozen bytes for a one-cell change ten levels down', function () {
    fiber\run(function () {
        [$root, $chain] = deepTree();
        $t = new FakeTerminal(160, 50);
        $t->run(function (FakeTerminal $t) use ($root, $chain) {
            $t->attach($root);
            $t->refresh();
            $t->takeOutput();
            end($chain)->write("\e[1;3Hx");
            $t->refresh();
            $bytes = $t->takeOutput();
            expect($bytes)->toContain('x')->and(\strlen($bytes))->toBeLessThan(60)
                ->and($t->dump())->toBe($root->dump(true));
        });
    });
});
