<?php

/*
 * TODO-0.0.3-beta2 item 4: freeze() of a component's surface freezes the rows that component
 * shows in the frame as it is at that moment, also after rows above it left the tree.
 */

use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/**
 * Inline, a Column of $n job rows and a total; each job sets its bar to 1.0, sleeps a frame,
 * freezes its bar and leaves the tree. $together: the jobs run at once, finishing in random
 * order, their values in a State read by the bars' closures; else one after the other with
 * setValue(). Returns [the frozen lines while attached, the terminal].
 *
 * @return array{list<string>, FakeTerminal}
 */
function freezeJobs(int $n, bool $together, bool $inRows = false): array
{
    $frozen = [];
    $terminal = null;
    mt_srand(11);
    \fiber\run(function () use ($n, $together, $inRows, &$frozen, &$terminal) {
        $names = array_map(fn($i) => chr(97 + $i), range(0, $n - 1));
        $s = new State(['open' => $names, 'p' => array_fill_keys($names, 0.0), 'done' => 0]);
        $bars = [];
        foreach ($names as $name) {
            $bars[$name] = new ProgressBar($together ? fn() => $s->p[$name] : 0.0, label: "job $name");
        }
        $terminal = Tui::headless(fn() => new Column([
            ...array_map(fn($name) => $inRows ? new Row([new Text("$name:", width: 3), $bars[$name]]) : $bars[$name], $s->open),
            new Text("total {$s->done} done"),
        ]), 40, 12, inline: true);
        $job = function (string $name) use ($s, $bars, $together) {
            if ($together) {
                for ($v = 0.0; $v < 1.0; \fiber\sleep(mt_rand(1, 4) / 100)) {
                    $v = min(1.0, $v + mt_rand(1, 20) / 100);
                    $s->p = [...$s->p, $name => $v];
                }
            } else {
                $bars[$name]->setValue(0.5);
                \fiber\sleep(0.02);
                $bars[$name]->setValue(1.0);
            }
            \fiber\sleep(1 / 60);
            $bars[$name]->device()->freeze($bars[$name]->surface);
            $s->open = array_values(array_diff($s->open, [$name]));
            $s->done++;
        };
        $terminal->run(function (FakeTerminal $t) use ($names, $job, $together, $s, $n, &$frozen) {
            $t->settle();
            foreach ($names as $name) {
                if ($together) {
                    \fiber\go(fn() => $job($name));
                } else {
                    $job($name);
                }
            }
            $t->waitFor(fn() => $s->done === $n, 10);
            $t->settle();
            $frozen = explode("\n", $t->frozen());
            expect($t->region())->toBe("total $n done");
        });
    });
    return [$frozen, $terminal];
}

it('freezes the row of each of two jobs as it shows, after the rows above it left the tree', function () {
    foreach ([false, true] as $together) {
        [$frozen] = freezeJobs(2, $together);
        expect($frozen)->toHaveCount(2);
        foreach ($frozen as $line) {
            expect($line)->toMatch('/^█+ job [ab] 100%$/u');
        }
        expect(array_map(fn($l) => substr($l, -6, 1), $frozen))->toEqualCanonicalizing(['a', 'b']);
    }
});

it('freezes the row of each of eight jobs as it shows, in rows too, and the byte stream replays the same', function () {
    foreach ([[false, false], [true, false], [true, true]] as [$together, $inRows]) {
        [$frozen, $t] = freezeJobs(8, $together, $inRows);
        expect($frozen)->toHaveCount(8);
        $seen = [];
        foreach ($frozen as $line) {
            expect($line)->toMatch($inRows ? '/^([a-h]): █+ job \1 100%$/u' : '/^█+ job [a-h] 100%$/u');
            $seen[] = substr($line, -6, 1);
        }
        expect($seen)->toEqualCanonicalizing(range('a', 'h'));
        if (!$together) {
            expect($seen)->toBe(range('a', 'h'));       // one after the other: in their order
        }
        // the terminal's bytes, replayed: the same frozen lines above the last frame
        $replay = new Surface(40, 12);
        $replay->write($t->output());
        $lines = [];
        for ($y = -$replay->scrollbackSize(); $y < $replay->rows(); $y++) {
            $lines[] = rtrim($replay->line($y));
        }
        expect(array_slice($lines, 0, 9))->toBe([...$frozen, 'total 8 done']);
    }
});
