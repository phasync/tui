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

it('draws Latin letters with diacritics as their base letter, Æ Ø and the Greek and Cyrillic capitals, and takes extra glyphs', function () {
    $rows = fn(string $t, array $g = []) => \phasync\Tui\Component\BigText::rows($t, false, $g);
    expect($rows('üẼå'))->toBe($rows('UEA'))                       // decomposed (ext-intl)
        ->and($rows('Ёж'))->toBe($rows('Еж'))
        ->and($rows('ÆØ'))->not->toContain(implode('', $rows('?')))
        ->and($rows('ΑΒΓΔ'))->toBe($rows('AB' . 'ΓΔ'))                // look-alikes share the Latin glyph
        ->and($rows('АСНХ'))->toBe($rows('ACHX'))
        ->and($rows('ЖЩЯ'))->not->toBe($rows('???'))
        ->and($rows('☃'))->toBe($rows('?'))                          // unknown
        ->and($rows('☃', ['☃' => '010111010111101'])[0])->toBe(' █ ');
    $app = new WidgetScreen(fn() => new Column([new BigText('Ærø ☃', glyphs: ['☃' => '010111010111101'])]));
    mountOn($app, 24, 5, function (\phasync\Tui\FakeTerminal $t) use ($rows) {
        expect($t->text())->toContain(rtrim($rows('Ærø ☃', ['☃' => '010111010111101'])[0]));
    });
})->skip(!class_exists(Normalizer::class), 'needs ext-intl');
