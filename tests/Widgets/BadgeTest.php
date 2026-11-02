<?php

/*
 * Badge (and removable tag), through FakeTerminal.
 */

use phasync\Tui\Component\Badge;
use phasync\Tui\Color;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;

it('draws a label with a space each side, in the colours of its variant', function () {
    $app = new WidgetScreen(fn() => new Row([new Badge('new'), new Badge('ok', 'success'), new Badge('bad', 'error')]));
    mountOn($app, 20, 1, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t)[0])->toBe(' new  ok  bad');
        $bg = fn(int $x) => $t->surface->cell($x, 0)->style;
        expect($bg(1)->bg)->not->toBe(Color::DEFAULT);                    // the badge has a background
        expect($bg(6)->bg)->not->toBe($bg(1)->bg);                         // success differs from neutral
        expect($bg(10)->bg)->not->toBe($bg(6)->bg);                        // and error from success
        expect(fn() => new Badge('x', 'loud'))->toThrow(InvalidArgumentException::class);
    });
});

it('closes a removable tag with the × or Delete, and takes no focus when not closable', function () {
    $closed = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Row([
        $s->make('a', fn() => new Badge('php', closable: true, onClose: function (Badge $b) use ($closed) { $closed[] = $b->label; })),
        new Badge('static'),
    ]));
    mountOn($app, 24, 1, function (FakeTerminal $t, WidgetScreen $app) use ($closed) {
        $t->settle();
        expect(screenRows($t)[0])->toBe(' php ×  static');
        $a = $app->children()['a'];
        $t->click(1, 0);                       // on the label: focuses, no close
        expect($closed->getArrayCopy())->toBe([]);
        $t->press('delete');
        $t->press('backspace');
        $t->click(5, 0);                       // the ×
        expect($closed->getArrayCopy())->toBe(['php', 'php', 'php']);
        $t->press('tab');                      // the plain badge is no stop: focus stays/wraps to the tag
        $t->press('delete');
        expect(count($closed))->toBe(4);
    });
});

it('ignores a disabled tag and a plain badge', function () {
    $closed = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Row([
        $s->make('a', fn() => new Badge('x', closable: true, disabled: true, onClose: function () use ($closed) { $closed[] = 1; })),
        $s->make('b', fn() => new Badge('y', onClose: function () use ($closed) { $closed[] = 2; })),
    ]));
    mountOn($app, 20, 1, function (FakeTerminal $t, WidgetScreen $app) use ($closed) {
        $t->settle();
        $app->children()['a']->focus();
        $t->press('delete');
        $t->click(3, 0);
        $t->click(9, 0);
        expect($closed->getArrayCopy())->toBe([]);
        expect($app->children()['b']->close())->toBeFalse();
    });
});
