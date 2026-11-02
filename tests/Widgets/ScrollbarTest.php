<?php

/*
 * Scrollbar, through FakeTerminal.
 */

use phasync\Tui\Component\Scrollbar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;

it('draws a thumb as large as the part in view, where it is, and nothing when all shows', function () {
    $app = new WidgetScreen(fn() => new Row([
        new Scrollbar(total: 40, view: 10, position: 0),
        new Scrollbar(total: 40, view: 10, position: 30),
        new Scrollbar(total: 40, view: 10, position: 15),
        new Scrollbar(total: 10, view: 10),
    ]));
    mountOn($app, 4, 8, function (FakeTerminal $t) {
        $t->settle();
        // the thumb is 8 * 10 / 40 = 2 rows: at the top, at the end, in the middle; the last has nothing to show
        expect(screenRows($t))->toBe(['┃││', '┃││', '│││', '││┃', '││┃', '│││', '│┃│', '│┃│']);
    });
});

it('draws a horizontal scrollbar with thin glyphs', function () {
    $app = new WidgetScreen(fn() => new Column([new Scrollbar(total: 100, view: 25, position: 75, horizontal: true)]));
    mountOn($app, 12, 2, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('─────────━━━');
    });
});

it('scrolls with a click on the track, a drag of the thumb and the wheel, and reports the position', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Row([
        $s->make('b', fn() => new Scrollbar(total: 100, view: 10, onScroll: function ($p) use ($seen) { $seen[] = $p; })),
    ]));
    mountOn($app, 2, 10, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $b = $app->children()['b'];
        // 10 rows, the thumb is 1 row; a click on row 5 puts the thumb there: 5 / 9 of the 90 that can scroll
        $t->click(0, 5);
        expect($b->position())->toBe(50);
        $t->drag(0, 5, 0, 9);
        expect($b->position())->toBe(90);
        $t->scroll(0, 3, -1);                // the wheel up: three back
        expect($b->position())->toBe(87);
        $t->scroll(0, 3, 5);
        expect($b->position())->toBe(90);    // (never past the end)
        $b->setPosition(0);
        $t->settle();
        expect(screenRows($t)[0])->toBe('┃');
        expect($seen->getArrayCopy())->toBe([50, 90, 87, 90, 0]);
    });
});

it('ignores the mouse when disabled or when everything shows', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Row([
        $s->make('a', fn() => new Scrollbar(total: 50, view: 10, disabled: true)),
        $s->make('b', fn() => new Scrollbar(total: 5, view: 10)),
    ]));
    mountOn($app, 2, 10, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $t->click(0, 7);
        $t->click(1, 7);
        expect($app->children()['a']->position())->toBe(0);
        expect($app->children()['b']->position())->toBe(0);
    });
});
