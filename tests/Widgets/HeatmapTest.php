<?php

/*
 * Heatmap, through FakeTerminal.
 */

use phasync\Tui\Color;
use phasync\Tui\Component\Heatmap;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('colours cells from the lowest value to the highest, with labels and values', function () {
    $app = new WidgetScreen(fn() => new Column([
        new Heatmap([[0, 5, 10], [10, null, 0]], rowLabels: ['mon', 'tue'], colLabels: ['a', 'b', 'c'], values: true,
            colors: ['#000000', '#ffffff'], cellWidth: 3),
    ]));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe(['     a  b  c', 'mon  0  5 10', 'tue 10     0', '']);
        expect(Color::toRgb($t->surface->cell(4, 1)->style->bg))->toBe([0, 0, 0]);          // lowest: first colour
        expect(Color::toRgb($t->surface->cell(10, 1)->style->bg))->toBe([255, 255, 255]);   // highest: last colour
        $mid = Color::toRgb($t->surface->cell(7, 1)->style->bg);
        expect($mid[0])->toBeBetween(124, 131);                                             // halfway (quantised to 64 steps)
        expect($t->surface->cell(7, 2)->style->bg)->toBe(Color::DEFAULT);                   // null: empty
        expect(Color::toRgb($t->surface->cell(4, 2)->style->bg))->toBe([255, 255, 255]);
    });
});

it('blends along several colours, fixes the range with min and max, and leaves out what does not fit', function () {
    $app = new WidgetScreen(fn() => new Column([
        new Heatmap([[0, 50, 100, 7]], colors: ['#ff0000', '#00ff00', '#0000ff'], min: 0, max: 100, cellWidth: 1),
        new Heatmap([[1, 2, 3, 4, 5, 6]], cellWidth: 2),
    ]));
    mountOn($app, 8, 3, function (FakeTerminal $t) {
        $t->settle();
        expect(Color::toRgb($t->surface->cell(0, 0)->style->bg))->toBe([255, 0, 0]);
        expect(Color::toRgb($t->surface->cell(2, 0)->style->bg))->toBe([0, 0, 255]);
        $mid = Color::toRgb($t->surface->cell(1, 0)->style->bg);
        expect($mid[1])->toBeGreaterThan(240)->and($mid[0])->toBeLessThan(10);
        expect(Color::toRgb($t->surface->cell(7, 1)->style->bg))->not->toBeNull();       // 4 cells of width 2 fill 8 columns
        expect(fn() => new Heatmap([[1]], colors: ['#fff']))->toThrow(InvalidArgumentException::class);
    });
});

it('calls onSelect for a click on a cell with a value, not on labels, empty cells or elsewhere', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn() => new Column([
        new Heatmap([[1, 2], [3, null]], rowLabels: ['a', 'b'], colLabels: ['x', 'y'], cellWidth: 2,
            onSelect: function ($r, $c, $v) use ($seen) { $seen[] = [$r, $c, $v]; }),
    ]));
    mountOn($app, 12, 4, function (FakeTerminal $t) use ($seen) {
        $t->settle();
        $t->click(4, 1);        // row a (screen row 1), column 1 (x = 2 label + 2)
        $t->click(2, 2);        // row b, column 0
        $t->click(5, 2);        // the empty cell
        $t->click(0, 1);        // the row label
        $t->click(3, 0);        // the column label
        expect($seen->getArrayCopy())->toBe([[0, 1, 2.0], [1, 0, 3.0]]);
    });
});
