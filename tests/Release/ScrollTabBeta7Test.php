<?php

/*
 * TODO-0.0.3-beta7 item 1: a Tab inside a ScrollView of rows cost O(rows) (2 rows 4.6 ms,
 * 200 rows 43 ms). The ScrollView rendered its whole content again for every key that passed
 * it (to follow the focus), its layout of the content read the buttons' focus as a render
 * would (so it rendered again whenever one of them gained or lost it), a scroll to where it was
 * repainted the whole window, and the footer `Text` closure growing a column ("sel 9" to
 * "sel 10") re-ran the program's render. Now the content is laid out again only where the
 * focus changed its look, the view follows without repainting, and a child whose size changes
 * has the layout around it arranged again without a render.
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Component\ScrollView;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\LayoutItem;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/** The report's program, verbatim (onFocus: left out when $onFocus is false); $renders counts its renders. */
function beta7ScrollScreen(int $n, State $s, bool $onFocus = true, int &$renders = 0): Closure
{
    $btns = [];
    return function () use ($n, $s, &$btns, $onFocus, &$renders) {
        $renders++;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $btns[$i] ??= [new Button('Pause', fn() => null), new Button('Cancel', fn() => null)];
            $rows[] = $onFocus
                ? new Row([new Text("file$i"), ...$btns[$i]], class: 'gap-1', key: "r$i", onFocus: fn() => $s->sel = $i)
                : new Row([new Text("file$i"), ...$btns[$i]], class: 'gap-1', key: "r$i");
        }
        return new Column([new ScrollView(new Column($rows)), new Text(fn() => "sel {$s->sel}")]);
    };
}

/** The median ms of $keys Tabs, each press('tab') + settle() around hrtime(), at a person's pace. */
function beta7TabMedian(FakeTerminal $t, int $keys = 20): float
{
    $times = [];
    for ($k = 0; $k < $keys; $k++) {
        $a = hrtime(true);
        $t->press('tab');
        $t->settle();
        $times[] = (hrtime(true) - $a) / 1e6;
        fiber\sleep(0.01);
    }
    sort($times);
    return $times[intdiv($keys, 2)];
}

/** @return array<int, array{Surface, int}> the leaves below $s (the surfaces that are painted), with their versions */
function beta7Leaves(Surface $s, array $out = []): array
{
    if ($s->viewports() === []) {
        $out[spl_object_id($s)] = [$s, $s->version()];
    }
    foreach ($s->viewports() as $v) {
        $out = beta7Leaves($v->source, $out);
    }
    return $out;
}

/** The screen rows above $below that the one-row surfaces in $surfaces are on, each once. */
function beta7Rows(array $surfaces, int $below): array
{
    $ys = [];
    foreach ($surfaces as $surface) {
        $area = $surface->area();
        if (!$area->isEmpty() && $area->height === 1 && $area->y < $below) {
            $ys[$area->y] = true;
        }
    }
    ksort($ys);
    return array_keys($ys);
}

it('takes a Tab in a ScrollView of 200 rows within a few ms of one of 2 rows', function () {
    $median = [];
    foreach ([2, 50, 200] as $n) {
        foreach ([true, false] as $onFocus) {
            fiber\run(function () use ($n, $onFocus, &$median) {
                $s = new State(['sel' => 0]);
                $t = Tui::headless(beta7ScrollScreen($n, $s, $onFocus), 160, 50);
                $t->settle();
                beta7TabMedian($t, 4);      // (warm)
                $median[$n][$onFocus ? 'onFocus' : 'plain'] = beta7TabMedian($t);
                $t->detach();
            });
        }
    }
    // about 3.7, 4.5 and 6.2 ms on the machine this was written on (the report: 4.6, 22.6 and 43 ms)
    foreach (['onFocus', 'plain'] as $kind) {
        expect($median[50][$kind])->toBeLessThan($median[2][$kind] + 6.0)
            ->and($median[200][$kind])->toBeLessThan($median[2][$kind] + 8.0)
            ->and($median[200][$kind])->toBeLessThan(20.0);
    }
});

it('lays out and paints the two rows the focus moves between, and renders nothing, for a Tab in the ScrollView', function () {
    fiber\run(function () {
        $renders = 0;
        $s = new State(['sel' => 0]);
        $t = Tui::headless(beta7ScrollScreen(200, $s, true, $renders), 160, 50);
        $t->settle();
        $renders = 0;
        // (Pause -> Cancel stays in a row, Cancel -> the next row's Pause leaves it; past row 9
        // the footer "sel 10" grows a column)
        for ($move = 0; $move < 24; $move++) {
            $before = beta7Leaves($t->root());
            LayoutItem::$measured = [];
            $t->press('tab');
            $t->settle();
            $measured = beta7Rows(LayoutItem::$measured, 49);
            LayoutItem::$measured = null;
            $painted = [];
            foreach (beta7Leaves($t->root()) as $id => [$surface, $version]) {
                if (($before[$id][1] ?? -1) !== $version) {
                    $painted[] = $surface;
                }
            }
            $at = $s->sel;
            $rows = $move % 2 === 0 ? [$at] : [$at - 1, $at];
            expect(beta7Rows($painted, 49))->toBe($rows)
                ->and(array_diff($measured, $rows))->toBe([]);
        }
        expect($renders)->toBe(0)->and($s->sel)->toBe(12)->and($t->text())->toContain('sel 12');
        $t->detach();
    });
});

it('still follows the focus down a ScrollView taller than the screen, and back up', function () {
    fiber\run(function () {
        $s = new State(['sel' => 0]);
        $t = Tui::headless(beta7ScrollScreen(200, $s), 60, 12);
        $t->settle();
        for ($k = 0; $k < 2 * 30; $k++) {
            $t->press('tab');
        }
        $t->settle();
        expect($s->sel)->toBe(30)->and($t->text())->toContain('file30 ')->not->toContain('file5 ');
        for ($k = 0; $k < 2 * 25; $k++) {
            $t->press('shift+tab');
        }
        $t->settle();
        expect($s->sel)->toBe(5)->and($t->text())->toContain('file5 ')->not->toContain('file30 ');
        $t->detach();
    });
});

it('arranges the layout around a Text closure that grows wider without running the render', function () {
    fiber\run(function () {
        $renders = 0;
        $s = new State(['n' => 9]);
        $t = Tui::headless(function () use ($s, &$renders) {
            $renders++;
            return new Column([new Row([new Text(fn() => "count {$s->n}"), new Text('|right')]), new Text('below')]);
        }, 40, 5);
        $t->settle();
        $renders = 0;
        expect(explode("\n", $t->text())[0])->toBe('count 9|right');
        $s->n = 10;
        $t->settle();
        expect(explode("\n", $t->text())[0])->toBe('count 10|right');      // (the row arranged again: the text after it moved)
        $s->n = 1000;
        $t->settle();
        expect(explode("\n", $t->text())[0])->toBe('count 1000|right')
            ->and($renders)->toBe(0);
        $t->detach();
    });
});

it('repaints nothing when a window is scrolled or panned to where it is', function () {
    $host = new Surface(20, 4);
    $child = new Surface(40, 10);
    for ($i = 0; $i < 10; $i++) {
        $child->write("\e[" . ($i + 1) . ";1Hline $i");
    }
    $view = $host->host($child, 0, 0, 20, 4);
    $view->scrollTo(3)->pan(2);
    $host->compose();
    $host->takeDamage();
    $areas = 0;
    $child->on('area', function () use (&$areas) {
        $areas++;
    });
    $view->scrollTo(3)->pan(2);
    $host->compose();
    expect($host->takeDamage())->toBe([])->and($areas)->toBe(0)->and($host->line(0))->toBe('ne 3');
    $view->scrollTo(4);
    $host->compose();
    expect($host->takeDamage())->not->toBe([])->and($host->line(0))->toBe('ne 4');
});
