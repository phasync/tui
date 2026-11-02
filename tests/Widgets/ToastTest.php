<?php

/*
 * Toast, through FakeTerminal.
 */

use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Toast;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('shows a toast in the bottom-right corner, then removes it after its timeout', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('e', fn() => new Editor(maxLines: 1))]));
    mountOn($app, 20, 5, function (FakeTerminal $t) {
        $t->settle();
        Toast::show('Saved', 'success', timeout: 0.3);
        $t->settle();
        $rows = screenRows($t);
        expect($rows[4])->toBe('          ▌ ✔ Saved');
        expect($rows[3])->toBe('');
        \fiber\sleep(0.6);
        $t->settle();
        expect(screenRows($t)[4])->toBe('');
    });
});

it('stacks toasts, dismisses one by a click, and leaves the focus where it was', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('e', fn() => new Editor(maxLines: 1))]));
    mountOn($app, 20, 5, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $app->children()['e']->focus();
        Toast::show('one', 'info', timeout: 0);
        Toast::show('<b>two</b>', 'error', timeout: 0);
        $t->settle();
        $rows = screenRows($t);
        expect($rows[3])->toBe('            ▌ ℹ one');
        expect($rows[4])->toBe('            ▌ ✖ two');
        $t->type('hi');                     // the keys still go to the editor
        $t->settle();
        expect(screenRows($t)[0])->toBe('hi');
        $t->click(12, 3);                   // the first toast
        $t->settle();
        $rows = screenRows($t);
        expect($rows[3])->toBe('');
        expect($rows[4])->toBe('            ▌ ✖ two');
        $t->click(12, 4);
        $t->settle();
        expect(screenRows($t)[4])->toBe('');
        Toast::show('again', 'warning', timeout: 0, position: 'top-left');   // a new stack after the old ended
        $t->settle();
        expect(screenRows($t)[0])->toBe('▌ ⚠ again');
    });
});

it('puts toasts in the top-right and clears them, and refuses an unknown severity', function () {
    $app = new WidgetScreen(fn() => new Column([]));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        Toast::show('hello', position: 'top-right', timeout: 0);
        $t->settle();
        expect(screenRows($t)[0])->toBe('          ▌ ℹ hello');
        Toast::clear();
        $t->settle();
        expect(screenRows($t)[0])->toBe('');
        expect(fn() => Toast::show('x', 'loud'))->toThrow(InvalidArgumentException::class);
    });
});
