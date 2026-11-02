<?php

/*
 * MaskedInput, through FakeTerminal.
 */

use phasync\Tui\Component\MaskedInput;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

function maskedApp(array $args): WidgetScreen
{
    return new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('m', fn() => new MaskedInput(...$args))]));
}

it('shows a mask for what is typed, edits at the cursor, and reveals with Alt-R or a click on the sign', function () {
    $seen = new ArrayObject();
    $app = maskedApp(['placeholder' => 'password', 'onChange' => function ($v) use ($seen) { $seen[] = $v; }]);
    mountOn($app, 14, 2, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('password     ◎');
        $m = $app->children()['m'];
        $m->focus();
        $t->type('s3cr t');
        $t->settle();
        expect($m->value())->toBe('s3cr t');
        expect(screenRows($t)[0])->toBe('••••••       ◎');
        $t->press('left', 'left', 'backspace', 'x', 'home', 'delete');
        expect($m->value())->toBe('3cx t');
        expect($m->cursor())->toBe(0);
        $t->press('alt+r');
        $t->settle();
        expect(screenRows($t)[0])->toBe('3cx t        ◉');
        $t->click(13, 0);
        $t->settle();
        expect($m->isRevealed())->toBeFalse();
        $t->press('end', 'ctrl+u');
        expect($m->value())->toBe('');
        $t->paste("ab\ncd");
        expect($m->value())->toBe('ab cd');
        $t->press('home', 'right', 'ctrl+k');
        expect($m->value())->toBe('a');
        expect($seen[count($seen) - 1])->toBe('a');
    });
});

it('submits with Enter only when there is an onSubmit, and honours maxLength and the mask', function () {
    $got = new ArrayObject();
    $app = maskedApp(['mask' => '*', 'maxLength' => 4, 'revealable' => false, 'onSubmit' => function ($v) use ($got) { $got[] = $v; }]);
    mountOn($app, 10, 2, function (FakeTerminal $t, WidgetScreen $app) use ($got) {
        $t->settle();
        $m = $app->children()['m'];
        $m->focus();
        $t->type('12345');
        $t->settle();
        expect($m->value())->toBe('1234');
        expect(screenRows($t)[0])->toBe('****');
        $t->press('enter');
        expect($got->getArrayCopy())->toBe(['1234']);
        $t->press('alt+r');
        expect($m->isRevealed())->toBeFalse();     // not revealable
        expect(fn() => new MaskedInput(mask: '中'))->toThrow(InvalidArgumentException::class);
    });
    $plain = maskedApp([]);
    mountOn($plain, 10, 2, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $m = $app->children()['m'];
        $m->focus();
        expect($m->submit())->toBeFalse();          // no onSubmit: Enter goes on to a form
    });
});

it('moves the cursor with a click, scrolls a long secret and ignores a disabled field', function () {
    $app = maskedApp(['revealable' => false, 'value' => 'abcdefghij']);
    mountOn($app, 6, 2, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $m = $app->children()['m'];
        expect($m->cursor())->toBe(10);
        expect(screenRows($t)[0])->toBe('•••••');        // scrolled: the cursor cell stays free
        $t->click(2, 0);
        expect($m->cursor())->toBe(7);                     // scroll offset 5
        $t->press('ctrl+a');
    });
    $off = maskedApp(['value' => 'abc', 'disabled' => true]);
    mountOn($off, 10, 2, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $m = $app->children()['m'];
        $m->focus();
        $t->type('zz');
        $t->press('backspace');
        $t->click(9, 0);
        expect($m->value())->toBe('abc');
        expect($m->isRevealed())->toBeFalse();
    });
});
