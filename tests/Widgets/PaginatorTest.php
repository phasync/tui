<?php

/*
 * Paginator, through FakeTerminal.
 */

use phasync\Tui\Component\Paginator;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

function paginatorApp(array $args, ArrayObject $seen): WidgetScreen
{
    return new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('p', fn() => new Paginator(...$args, onChange: function ($p) use ($seen) { $seen[] = $p; })),
    ]));
}

it('shows all page numbers when there are few, and a window with ellipses when many', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        new Paginator(5, 2),
        new Paginator(20, 1),
        new Paginator(20, 10),
        new Paginator(20, 20),
        new Paginator(9, 4),
        new Paginator(20, 3, display: 'text'),
    ]));
    mountOn($app, 30, 6, function (FakeTerminal $t) {
        $t->settle();
        expect(screenRows($t))->toBe([
            '‹ 1  2  3 4 5 ›',
            '‹  1  2 … 20 ›',
            '‹ 1 … 9  10  11 … 20 ›',
            '‹ 1 … 19  20  ›',
            '‹ 1 2 3  4  5 … 9 ›',
            '‹ 3 / 20 ›',
        ]);
    });
});

it('draws dots and moves with keys, ends passing the key on', function () {
    $seen = new ArrayObject();
    $app = paginatorApp([4, 1, 'dots'], $seen);
    mountOn($app, 20, 2, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        expect(screenRows($t)[0])->toBe('‹ ● ○ ○ ○ ›');
        $p = $app->children()['p'];
        $p->focus();
        $t->press('left');                  // on the first page: nothing happens, the key goes on
        $t->press('right', 'right', 'end', 'right');
        expect($p->page())->toBe(4);
        $t->press('home', 'l', 'pagedown', 'pageup', 'h');
        $t->settle();
        expect($p->page())->toBe(1);
        expect(screenRows($t)[0])->toBe('‹ ● ○ ○ ○ ›');
        expect($seen->getArrayCopy())->toBe([2, 3, 4, 1, 2, 3, 2, 1]);
    });
});

it('goes to a page or steps by a click, and limits the page to the pages', function () {
    $seen = new ArrayObject();
    $app = paginatorApp([10, 5], $seen);
    mountOn($app, 30, 2, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $row = screenRows($t)[0];
        expect($row)->toBe('‹ 1 … 4  5  6 … 10 ›');
        $p = $app->children()['p'];
        $t->click(mb_strpos($row, '10'), 0);
        expect($p->page())->toBe(10);
        $t->settle();
        $t->click(0, 0);                    // ‹
        expect($p->page())->toBe(9);
        $t->settle();
        $t->click(mb_strlen(screenRows($t)[0]) - 1, 0);    // ›
        expect($p->page())->toBe(10);
        $t->settle();
        $t->click(mb_strpos(screenRows($t)[0], '…'), 0);  // an ellipsis does nothing
        expect($p->page())->toBe(10);
        $p->setPage(99);
        expect($p->page())->toBe(10);
        $p->setPage(-3);
        expect($p->page())->toBe(1);
        expect($seen->getArrayCopy())->toBe([10, 9, 10, 1]);
    });
});

it('does not change a disabled Paginator', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('p', fn() => new Paginator(3, 1, disabled: true))]));
    mountOn($app, 20, 2, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $p = $app->children()['p'];
        $p->focus();
        $t->press('right');
        $t->click(4, 0);
        expect($p->page())->toBe(1);
    });
});
