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

it('draws bitmap fonts: the bundled 6x13 and 5x7 as quarter blocks or Braille, a console font and a BDF by path, any script', function () {
    $rows = fn(string $t, bool $c = false, string $f = '6x13') => \phasync\Tui\Component\BigText::rows($t, $c, [], $f);
    $six = $rows('Ærø ж ա א');
    expect($six)->toHaveCount(7)                                      // 13 rows of pixels, two a cell
        ->and(mb_strlen($six[0]))->toBe(3 * 9)                        // 6 pixels wide, two a cell: three columns a character
        ->and($rows('Æ'))->not->toBe($rows('?'))->and($rows('ж'))->not->toBe($rows('?'))
        ->and($rows('ա'))->not->toBe($rows('?'))->and($rows('א'))->not->toBe($rows('?'))
        ->and($rows('a'))->not->toBe($rows('A'))                      // lower case stays
        ->and($rows("\u{1F600}"))->toBe($rows('?'))                   // neither: the font's ?
        ->and($rows('Ærø', true))->toHaveCount(4)                     // Braille: four pixel rows a cell
        ->and($rows('12', false, '5x7'))->toHaveCount(4)->and(mb_strlen($rows('12', false, '5x7')[0]))->toBe(5);
    $psf = '/usr/share/consolefonts/Uni3-TerminusBold16.psf.gz';
    if (is_file($psf)) {
        expect($rows('Жук', false, $psf))->toHaveCount(8)->and($rows('Ж', false, $psf))->not->toBe($rows('?', false, $psf));
    }
    $bdf = sys_get_temp_dir() . '/tui-test-' . getmypid() . '.bdf';
    file_put_contents($bdf, "STARTFONT 2.1\nFONTBOUNDINGBOX 3 3 0 0\nCHARS 1\nSTARTCHAR x\nENCODING 120\nBBX 3 3 0 0\nBITMAP\nA0\n40\nA0\nENDCHAR\nENDFONT\n");
    try {
        expect($rows('x', false, $bdf))->toBe(['▚▘', '▘▘'])          // the three-by-three X in quarter blocks
            ->and($rows("\u{1E8D}", false, $bdf))->toBe($rows('x', false, $bdf));   // ẍ, which the font lacks: its base letter (ext-intl)
    } finally {
        unlink($bdf);
    }
    expect(fn() => $rows('x', false, 'nope'))->toThrow(InvalidArgumentException::class);
    $app = new WidgetScreen(fn() => new Column([new BigText('Ærø', font: '6x13')]));
    mountOn($app, 12, 8, function (\phasync\Tui\FakeTerminal $t) use ($six) {
        expect($t->text())->toContain(rtrim(mb_substr($six[2], 0, 9)));
    });
})->skip(!class_exists(Normalizer::class), 'needs ext-intl');
