<?php

/*
 * ProgressBar with other glyphs (a line gauge), through FakeTerminal.
 */

use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('draws a line gauge with its own fill and empty characters, also when it moves', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('g', fn() => new ProgressBar(0.5, fill: '━', empty: '─')),
        new ProgressBar(0.25, 'disk', fill: '#', empty: '-'),
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('━━━━━━━━──────── 50%');
        expect(screenRows($t)[1])->toBe('###-------- disk 25%');
        $app->children()['g']->setValue(0.75);       // only changed cells are written; the glyphs stay
        $t->settle();
        expect(screenRows($t)[0])->toBe('━━━━━━━━━━━━──── 75%');
    });
});
