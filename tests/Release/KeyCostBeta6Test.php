<?php

/*
 * TODO-0.0.3-beta6 item 1: a key costs the same at 200 rows as at 2, measured as a program
 * measures it (press() and settle() around a microtime() pair). A Tab no longer renders the
 * component whose tree holds the rows (the rows the focus left and entered are laid out again
 * from the last tree, and only when their look changes), the Tab walk looks at the windows from
 * the focused one on instead of ordering the whole screen, a focus request settles only the
 * hosts above it, and the children not shown are found once per tree.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Layers;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\LayoutItem;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/** The report's screen: $n rows of a Text and two Buttons, with onFocus only. */
function beta6Rows(State $s, int $n, ?Closure $counted = null): Closure
{
    return function () use ($s, $n, $counted) {
        $counted?->__invoke();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = new Row([new Text("job $i", width: 8), new Button('Pause'), new Button('Log')], gap: 1, onFocus: fn() => $s->current = $i);
        }
        return new Column($rows);
    };
}

/** The median time in ms of press('tab') + settle(), over $keys keys. */
function beta6TabTime(FakeTerminal $t, int $keys = 30): float
{
    $times = [];
    for ($k = 0; $k < $keys; $k++) {
        $a = microtime(true);
        $t->press('tab');
        $t->settle();
        $times[] = (microtime(true) - $a) * 1000;
    }
    sort($times);
    return $times[intdiv($keys, 2)];
}

it('takes a Tab to a settled screen in about the same time at 200 rows as at 2', function () {
    $median = [];
    foreach ([2, 200] as $n) {
        fiber\run(function () use ($n, &$median) {
            $s = new State(['current' => null]);
            $t = Tui::headless(beta6Rows($s, $n), 160, 50);
            $t->settle();
            beta6TabTime($t, 6);       // (warm)
            $median[$n] = beta6TabTime($t);
            $t->detach();
        });
    }
    // about 1.2 ms and 1.8 ms on the machine this was written on (the report: 5.4 ms and 49 ms)
    expect($median[200])->toBeLessThan($median[2] + 6.0)->and($median[200])->toBeLessThan(15.0);
});

it('does not render the rows\' owner for a Tab and measures only the rows the focus moves between', function () {
    fiber\run(function () {
        $renders = 0;
        $s = new State(['current' => null]);
        $t = Tui::headless(beta6Rows($s, 200, function () use (&$renders) {
            $renders++;
        }), 160, 50);
        $t->settle();
        $renders = 0;
        for ($k = 0; $k < 6; $k++) {
            LayoutItem::$measured = [];
            $t->press('tab');
            $t->settle();
            $measured = array_unique(array_map(fn(Surface $s) => $s->area()->y, LayoutItem::$measured));
            LayoutItem::$measured = null;
            expect(count($measured))->toBeLessThanOrEqual(2);
        }
        expect($renders)->toBe(0)->and($s->current)->toBe(3);       // (onFocus still runs: rows 1, 2, 3)
        $t->detach();
    });
});

/** The focused stop of the Tab walk: the stop deepest on the focus path. */
function beta6Focused(array $order): Component|Surface|null
{
    $best = null;
    $depth = -1;
    foreach ($order as $stop) {
        $surface = $stop instanceof Component ? $stop->surface : $stop;
        if (!$surface->hasFocus()) {
            continue;
        }
        $d = 0;
        for ($p = $surface->parent(); $p !== null; $p = $p->parent()) {
            $d++;
        }
        if ($d > $depth) {
            [$best, $depth] = [$stop, $d];
        }
    }
    return $best;
}

dataset('tab screens', [
    'rows of buttons' => [fn(State $s) => beta6Rows($s, 5)()],
    'boxes, an onKey row around buttons, a disabled button, a hidden text, onKey cards' => [fn(State $s) => new Column([
        new Box(new Row([new Button('a'), new Button('b', disabled: true), new Button('c')], onKey: ['x' => fn() => true]), 'one'),
        new Box(new Column([
            new Text('card 1', onKey: ['d' => fn() => true]),
            new Text('hidden', class: 'hidden', onKey: ['d' => fn() => true]),
            new Text('card 2', onKey: ['d' => fn() => true]),
        ]), 'two'),
        new Row([new Text('label'), new Button('last')]),
        new Help(),
    ])],
    'layers whose windows are hosted in another order than they show' => [fn(State $s) => new Layers([
        new Button('right', class: 'text-right'),
        new Button('left'),
    ])],
]);

it('walks the stops with Tab and Shift-Tab in the order focusOrder() gives them', function (Closure $screen) {
    fiber\run(function () use ($screen) {
        $s = new State(['current' => null]);
        $t = Tui::headless(fn() => $screen($s), 60, 20);
        $t->settle();
        $order = Closure::bind(fn(Surface $root) => Component::focusOrder($root), null, Component::class)($t->root());
        expect(count($order))->toBeGreaterThan(1);
        foreach (['tab', 'shift+tab'] as $key) {
            $at = array_search(beta6Focused($order), $order, true);
            expect($at)->not->toBeFalse();
            for ($k = 1; $k <= count($order) + 1; $k++) {
                $t->press($key);
                $t->settle();
                $want = $key === 'tab' ? ($at + $k) % count($order) : (($at - $k) % count($order) + count($order)) % count($order);
                expect(beta6Focused($order))->toBe($order[$want]);
            }
        }
        $t->detach();
    });
})->with('tab screens');

it('shows focus: classes, the Help line and onFocus of elements after a Tab that renders nothing', function () {
    fiber\run(function () {
        $renders = 0;
        $s = new State(['current' => null]);
        $t = Tui::headless(function () use ($s, &$renders) {
            $renders++;
            $cards = [];
            for ($i = 0; $i < 50; $i++) {
                $cards[] = new Text("card $i", class: 'focus:bg-sky-800', onKey: ['d' => [fn() => true, "delete card $i"]], onFocus: fn() => $s->current = $i);
            }
            return new Column([new Column($cards, height: 10), new Help()]);
        }, 60, 12);
        $t->settle();
        $renders = 0;
        $t->press('tab');
        $t->settle();
        expect($renders)->toBe(0)->and($s->current)->toBe(1)
            ->and($t->surface->cell(0, 1)->style->bg)->not->toBe($t->surface->cell(0, 0)->style->bg)
            ->and($t->surface->cell(0, 0)->style->bg)->toBe($t->surface->cell(0, 2)->style->bg)
            ->and($t->text())->toContain('delete card 1');
        $t->detach();
    });
});
