<?php

/*
 * BigText, through FakeTerminal.
 */

use phasync\Tui\Component\BigText;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('draws digits and letters from a 3x5 font, five rows high', function () {
    $app = new WidgetScreen(fn() => new Column([new BigText('12:Ab')]));
    mountOn($app, 24, 6, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe([
            ' █  ███      █  ██',
            '██    █  █  █ █ █ █',
            ' █  ███     ███ ██',
            ' █  █    █  █ █ █ █',
            '███ ███     █ █ ██',
            '',
        ]);
    });
});

it('draws a compact three-row version with half blocks, a question mark for unknown characters, and is cut at the width', function () {
    expect(BigText::rows('0', true))->toBe(['█▀█', '█ █', '▀▀▀']);
    expect(BigText::rows('1.', true))->toBe(['▄█     ', ' █     ', '▀▀▀  ▀ ']);
    expect(BigText::rows('~'))->toBe(BigText::rows('?'));
    expect(BigText::rows('a b'))->toBe(BigText::rows('A B'));
    $app = new WidgetScreen(fn() => new Column([new BigText('8888', compact: true)]));
    mountOn($app, 9, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe(['█▀█ █▀█ █', '█▀█ █▀█ █', '▀▀▀ ▀▀▀ ▀', '']);
    });
});

it('measures to the glyphs so a Column gives it exactly its size', function () {
    $app = new WidgetScreen(fn() => new Column([new BigText('00'), new \phasync\Tui\Element\Text('end')]));
    mountOn($app, 10, 8, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[5])->toBe('end');
    });
});
