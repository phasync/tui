<?php

/*
 * Placeholder, through FakeTerminal.
 */

use phasync\Tui\Component\Placeholder;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;

it('hatches its room and names its label and size in the middle', function () {
    $app = new WidgetScreen(fn() => new Column([new Placeholder('sidebar')]));
    mountOn($app, 20, 5, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe([
            '╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱',
            '╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱',
            '╱╱╱ sidebar 20x5 ╱╱╱',
            '╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱',
            '╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱╱',
        ]);
    });
});

it('shares a row by its sizes and shows each one its own size; a tiny one is cut without error', function () {
    $app = new WidgetScreen(fn() => new Row([new Placeholder('a', width: 8), new Placeholder('b', width: 3), new Placeholder('c')]));
    mountOn($app, 20, 1, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe(' a 8x1 ╱ b ╱ c 9x1 ╱');
    });
});
