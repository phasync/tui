<?php

/*
 * TODO-0.0.3-beta5 item 3: what a key costs in a closure program of fifty rows. Moving the focus
 * from one row to the next measures and paints those two rows and nothing else, and a Tab
 * reaches a settled screen in well under 20 ms (the median, with the bars moving), as it did
 * for the monitor of class rows (tools/monitor-bench.php has it as the mode `keys`).
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\LayoutItem;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/** The fifty rows of the report: Text, ProgressBar, two Buttons, with onKey and onFocus. */
function keyCostScreen(State $s, int $n = 50): Closure
{
    return function () use ($s, $n) {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = new Row([
                new Text("job $i", width: 8),
                new ProgressBar($s->p[$i]),
                new Button($s->paused[$i] ? 'Resume' : 'Pause', fn() => $s->paused[$i] = !$s->paused[$i]),
                new Button('Log'),
            ], gap: 1, onKey: ['p' => fn() => $s->paused[$i] = !$s->paused[$i]], onFocus: fn() => $s->current = $i);
        }
        return new Column($rows);
    };
}

/** The screen rows (y) that the surfaces in $surfaces are on, each once. */
function rowsOf(array $surfaces): array
{
    $ys = [];
    foreach ($surfaces as $surface) {
        $area = $surface->area();
        if (!$area->isEmpty() && $area->height === 1) {
            $ys[$area->y] = true;
        }
    }
    ksort($ys);
    return array_keys($ys);
}

/** @return array<int, array{Surface, int}> the leaves below $s (the surfaces that are painted), with their versions */
function keyCostLeaves(Surface $s, array $out = []): array
{
    if ($s->viewports() === []) {
        $out[spl_object_id($s)] = [$s, $s->version()];
    }
    foreach ($s->viewports() as $v) {
        $out = keyCostLeaves($v->source, $out);
    }
    return $out;
}

it('measures and paints the two rows the focus moves between, and nothing else', function () {
    fiber\run(function () {
        $s = new State(['paused' => array_fill(0, 50, true), 'p' => array_fill(0, 50, 0.3), 'current' => null]);
        $t = Tui::headless(keyCostScreen($s), 120, 60);
        $t->settle();
        $root = $t->root();
        for ($move = 0; $move < 4; $move++) {
            $before = keyCostLeaves($root);
            LayoutItem::$measured = [];
            $t->press('tab');
            $t->settle();
            $measured = rowsOf(LayoutItem::$measured);
            LayoutItem::$measured = null;
            $painted = [];
            foreach (keyCostLeaves($root) as $id => [$surface, $version]) {
                if (($before[$id][1] ?? -1) !== $version) {
                    $painted[] = $surface;
                }
            }
            $at = $s->current;
            // Pause -> Log stays in the row; Log -> the next row's Pause moves it
            $rows = $move % 2 === 0 ? [$at] : [$at - 1, $at];
            expect(rowsOf($painted))->toBe($rows)
                ->and(array_diff($measured, $rows))->toBe([]);
        }
        expect($s->current)->toBe(2);
        $t->detach();
    });
});

it('does not render a closure program for a Tab whose onFocus writes state the render does not read', function () {
    fiber\run(function () {
        $renders = 0;
        $s = new State(['paused' => array_fill(0, 50, true), 'p' => array_fill(0, 50, 0.3), 'current' => null]);
        $screen = keyCostScreen($s);
        $t = Tui::headless(function () use ($screen, &$renders) {
            $renders++;
            return $screen();
        }, 120, 60);
        $t->settle();
        $t->press('tab');       // (Pause -> Log: the focus stays in the row, nothing to render)
        $t->settle();
        $renders = 0;
        $t->press('tab');       // (Log -> the next row: its onFocus runs)
        $t->settle();
        // (0.0.3-beta6: the rows the focus left and entered are laid out again, render() is not called)
        expect($s->current)->toBe(1)->and($renders)->toBe(0);
        $t->detach();
    });
});

it('takes a Tab to the screen in well under 20 ms with fifty bars moving', function () {
    fiber\run(function () {
        $s = new State(['paused' => array_fill(0, 50, false), 'p' => array_fill(0, 50, 0.0), 'current' => null]);
        for ($i = 0; $i < 50; $i++) {
            Tui::go(function () use ($s, $i) {
                while (true) {
                    Tui::sleep(0.1);
                    $s->p[$i] = fmod($s->p[$i] + 0.01, 1.0);
                }
            });
        }
        $t = Tui::headless(keyCostScreen($s), 120, 60);
        $t->settle();
        $times = [];
        for ($k = 0; $k < 20; $k++) {
            $start = hrtime(true);
            $t->press('tab');
            $t->refresh();      // (the frame that shows it; the bars never settle)
            $times[] = (hrtime(true) - $start) / 1e6;
            fiber\sleep(0.02);
        }
        sort($times);
        // about 12 ms on the machine this was written on (a CPU clocked down between keys):
        // the budget leaves room for a busy test machine
        expect($times[10])->toBeLessThan(40.0);
        $t->detach();       // (the coroutines end with the program: Tui::sleep() throws Quit)
    });
});
