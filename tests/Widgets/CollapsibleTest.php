<?php

/*
 * Collapsible and Accordion, through FakeTerminal.
 */

use phasync\Tui\Component\Accordion;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Component\Collapsible;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('shows a Collapsible header and its content, toggled by Enter, Space, arrows and a click', function () {
    $seen = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('c', fn() => new Collapsible('Details', 'the content', onToggle: function ($c) use ($seen) { $seen[] = $c; })),
        new \phasync\Tui\Element\Text('below'),
    ]));
    mountOn($app, 20, 4, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        $c = $app->children()['c'];
        expect(screenRows($t)[0])->toBe('▸ Details');
        expect(screenRows($t)[1])->toBe('below');
        $c->focus();
        $t->press('enter');
        $t->settle();
        expect(screenRows($t))->toBe(['▾ Details', 'the content', 'below', '']);
        $t->press('space');
        $t->settle();
        expect(screenRows($t)[1])->toBe('below');
        $t->press('right');
        $t->settle();
        expect($c->isCollapsed())->toBeFalse();
        $t->press('left');
        $t->settle();
        expect($c->isCollapsed())->toBeTrue();
        $t->click(3, 0);
        $t->settle();
        expect($c->isCollapsed())->toBeFalse();
        $t->click(3, 1);                    // a click on the content does not toggle
        expect($c->isCollapsed())->toBeFalse();
        expect($seen->getArrayCopy())->toBe([false, true, false, true, false]);
    });
});

it('keeps the state of components in the content and lets Left and Right go on when they cannot act', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('c', fn() => new Collapsible('Opts', new Column([new Checkbox('Verbose', key: 'v')]), collapsed: false)),
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        expect(screenRows($t)[1])->toBe('[ ] Verbose');
        $c = $app->children()['c'];
        $c->focus();
        expect($c->expand())->toBeFalse();      // open already: the key goes on
        $t->press('tab');                       // into the content
        $t->press('space');
        $t->settle();
        expect(screenRows($t)[1])->toBe('[x] Verbose');
    });
});

it('does not toggle a disabled Collapsible', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('c', fn() => new Collapsible('Off', 'hidden', disabled: true)),
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $c = $app->children()['c'];
        $c->focus();
        $t->press('enter');
        $t->click(0, 0);
        expect($c->isCollapsed())->toBeTrue();
        $c->setCollapsed(false);               // the program may still
        $t->settle();
        expect(screenRows($t)[1])->toBe('hidden');
    });
});

it('opens one Accordion section at a time, or several when not exclusive', function () {
    $seen = new ArrayObject();
    $sections = ['a' => ['title' => 'One', 'content' => 'first'], 'b' => ['title' => 'Two', 'content' => 'second']];
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('x', fn() => new Accordion($sections, open: 'a', onChange: function ($o) use ($seen) { $seen[] = $o; })),
    ]));
    mountOn($app, 20, 5, function (FakeTerminal $t, WidgetScreen $app) use ($seen) {
        $t->settle();
        expect(screenRows($t))->toBe(['▾ One', 'first', '▸ Two', '', '']);
        $x = $app->children()['x'];
        $t->click(2, 2);                        // the header of Two
        $t->settle();
        expect(screenRows($t))->toBe(['▸ One', '▾ Two', 'second', '', '']);
        expect($x->open())->toBe(['b']);
        $x->setOpen('a');
        $t->settle();
        expect(screenRows($t)[0])->toBe('▾ One');
        expect($x->open())->toBe(['a']);
        $t->press('tab');                       // focus on the first header (Tab order)
        $t->click(0, 0);                        // close the open one
        $t->settle();
        expect($x->open())->toBe([]);
        expect($seen->getArrayCopy())->toBe([['b'], ['a'], []]);
    });
});

it('lets non-exclusive Accordion sections open independently', function () {
    $sections = ['a' => ['title' => 'One', 'content' => 'first'], 'b' => ['title' => 'Two', 'content' => 'second']];
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('x', fn() => new Accordion($sections, open: ['a', 'b'], exclusive: false)),
    ]));
    mountOn($app, 20, 5, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        expect(screenRows($t))->toBe(['▾ One', 'first', '▾ Two', 'second', '']);
        $app->children()['x']->setOpen('a', false);
        $t->settle();
        expect(screenRows($t)[0])->toBe('▸ One');
        expect($app->children()['x']->open())->toBe(['b']);
    });
});
