<?php

/*
 * Link, through FakeTerminal.
 */

use phasync\Tui\Component\Link;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;

it('draws the label with the URL attached to its cells, underlined', function () {
    $app = new WidgetScreen(fn() => new Row([new Link('https://example.com/a', 'docs'), new Link('https://x.org')]));
    mountOn($app, 30, 1, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('docshttps://x.org');
        $cell = fn(int $x) => $t->surface->cell($x, 0)->style;
        expect($cell(0)->link)->toBe('https://example.com/a')->and($cell(3)->link)->toBe('https://example.com/a');
        expect($cell(4)->link)->toBe('https://x.org');
        expect($cell(0)->has(\phasync\Tui\Style::UNDERLINE))->toBeTrue();
    });
});

it('calls onActivate with the URL for Enter and a click, not when disabled', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Row([
        $s->make('a', fn() => new Link('https://a.test', 'a', function ($url) use ($seen) { $seen[] = $url; })),
        $s->make('b', fn() => new Link('https://b.test', 'b', function ($url) use ($seen) { $seen[] = $url; }, disabled: true)),
    ]));
    mountOn($app, 10, 1, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $app->children()['a']->focus();
        $t->press('enter');
        $t->click(0, 0);
        $t->click(1, 0);                       // the disabled one
        $app->children()['b']->focus();
        $t->press('enter');
        expect($seen->getArrayCopy())->toBe(['https://a.test', 'https://a.test']);
    });
});
