<?php

/*
 * LineChart and ScatterChart, through FakeTerminal (braille, as there are no images).
 */

use phasync\Tui\Component\LineChart;
use phasync\Tui\Component\ScatterChart;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

/** Whether row $y of the screen has a braille dot between columns $from and $to (exclusive). */
function dotsIn(FakeTerminal $t, int $y, int $from, int $to): bool
{
    return preg_match('/[\x{2801}-\x{28ff}]/u', mb_substr(screenRows($t)[$y] ?? '', $from, $to - $from)) === 1;
}

it('draws axes, tick labels, a legend and the data of a LineChart', function () {
    $app = new WidgetScreen(fn() => new Column([
        new LineChart(['cpu' => [0, 50, 25, 100], 'mem' => [10, 20, 30, 40]], yMin: 0, yMax: 100),
    ]));
    mountOn($app, 40, 12, function (FakeTerminal $t) {
        $t->settle();
        $rows = screenRows($t);
        expect(array_map(static fn($r) => mb_substr($r, 0, 5), array_slice($rows, 0, 12)))->toBe([
            '100 ┤', '    │', '    │', '    │', ' 50 ┤', '    │', '    │', '    │', '    │', '  0 ┤', '    └', '     ',
        ]);
        expect($rows[0])->toEndWith('━ cpu');
        expect($rows[1])->toEndWith('━ mem');
        expect($rows[10])->toBe('    └' . str_repeat('─', 35));
        expect($rows[11])->toBe('     0' . str_repeat(' ', 33) . '3');
        expect(dotsIn($t, 9, 5, 12))->toBeTrue();        // the series start near 0: low on the left
        expect(dotsIn($t, 1, 5, 20))->toBeFalse();       // and nothing high on the left
        expect(dotsIn($t, 2, 30, 39))->toBeTrue();       // cpu reaches 100 on the right
    });
});

it('fits the axes to the data, takes [x, y] pairs, leaves gaps for null and keeps small sizes quiet', function () {
    $app = new WidgetScreen(fn() => new Column([
        new LineChart(['a' => [[10, 2.5], [20, null], [30, 7.5]]], points: true, legend: false),
    ]));
    mountOn($app, 30, 10, function (FakeTerminal $t) {
        $t->settle();
        $rows = screenRows($t);
        expect(mb_substr($rows[0], 0, 5))->toBe('7.5 ┤');
        expect(mb_substr($rows[7], 0, 5))->toBe('2.5 ┤');
        expect(mb_substr($rows[9], 0, 6))->toBe('     1');       // x labels: 10 .. 30, left-aligned from the first plot column
        expect($rows[9])->toEndWith('30');
        expect($rows[9])->toContain('10');
        expect(dotsIn($t, 7, 5, 8))->toBeTrue();         // first point, bottom left
        expect(dotsIn($t, 0, 24, 30))->toBeTrue();       // last point, top right
    });
    $tiny = new WidgetScreen(fn() => new Column([new LineChart(['a' => [1, 2]]), ]));
    mountOn($tiny, 6, 3, function (FakeTerminal $t) {
        $t->settle();                                       // (too small to draw anything: no error)
        expect(true)->toBeTrue();
    });
});

it('draws a ScatterChart as points only, with a point legend', function () {
    $app = new WidgetScreen(fn() => new Column([
        new ScatterChart(['s' => [[0, 0], [5, 5]]], xMin: 0, xMax: 5, yMin: 0, yMax: 5),
    ]));
    mountOn($app, 30, 8, function (FakeTerminal $t) {
        $t->settle();
        $rows = screenRows($t);
        expect($rows[0])->toEndWith('● s');
        expect(mb_substr($rows[5], 0, 3))->toBe('0 ┤');
        expect(dotsIn($t, 5, 3, 8))->toBeTrue();         // (0, 0) near the axis
        expect(dotsIn($t, 3, 8, 28))->toBeFalse();       // no line joins the two points
        expect(dotsIn($t, 4, 8, 28))->toBeFalse();
    });
});

it('draws the next frame when the series change', function () {
    $series = new ArrayObject(['a' => [0, 0]]);
    $app = new WidgetScreen(fn() => new Column([new LineChart($series->getArrayCopy(), yMin: 0, yMax: 10)]));
    mountOn($app, 30, 8, function (FakeTerminal $t, WidgetScreen $app) use ($series) {
        $t->settle();
        expect(dotsIn($t, 0, 4, 29))->toBeFalse();
        $series['a'] = [10, 10];
        $app->requestRender();
        $t->settle();
        expect(dotsIn($t, 0, 4, 20))->toBeTrue();
    });
});
