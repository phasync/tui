<?php

/*
 * TODO-0.0.3-beta6 item 5: Alt-1..9 switch the only Tabs on the screen from anywhere (bindings
 * marked 'anywhere'); Tui::freeze($component).
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** A sidebar Button and $n Tabs of two tabs each, side by side. @return list<Tabs> */
function anywhereTabs(int $n): array
{
    $tabs = [];
    for ($i = 0; $i < $n; $i++) {
        $tabs[] = new Tabs([
            'a' => ['label' => "A$i", 'content' => new Text("first $i")],
            'b' => ['label' => "B$i", 'content' => new Button("inside $i")],
        ], class: 'grow');
    }
    return $tabs;
}

it('switches the only Tabs on the screen with Alt-number from outside it, and not behind a modal dialog', function () {
    fiber\run(function () {
        [$tabs] = anywhereTabs(1);
        $side = new Button('side');
        $t = Tui::headless(fn() => new Row([$side->with(key: 'side'), $tabs->with(key: 'tabs')]), 60, 8);
        $t->settle();
        $side->focus();
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        expect($tabs->current())->toBe('b')->and($side->hasFocus())->toBeTrue();
        $t->press('ctrl+tab');
        $t->settle();
        expect($tabs->current())->toBe('a');
        Tui::go(fn() => \fiber\await(Confirm::ask('Sure', 'Sure?')));
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        expect($tabs->current())->toBe('a');      // (the dialog has the keys)
        $t->detach();
    });
});

it('switches each of two Tabs only while the focus is inside it', function () {
    fiber\run(function () {
        [$one, $two] = anywhereTabs(2);
        $side = new Button('side');
        $t = Tui::headless(fn() => new Row([$side->with(key: 'side'), $one->with(key: 'one'), $two->with(key: 'two')]), 90, 8);
        $t->settle();
        $side->focus();
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        expect($one->current())->toBe('a')->and($two->current())->toBe('a');
        $two->focus();
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        expect($one->current())->toBe('a')->and($two->current())->toBe('b');
        $t->detach();
    });
});

it('freezes a component with Tui::freeze(), also the instance a closure render made anew', function () {
    fiber\run(function () {
        $s = new State(['p' => 0.4, 'open' => true]);
        $bar = null;
        $t = Tui::headless(function () use ($s, &$bar) {
            $bar = new ProgressBar($s->p, label: 'copy');
            return new Column([...($s->open ? [$bar] : []), new Text('total')]);
        }, 40, 6, inline: true);
        $t->settle();
        $s->p = 1.0;
        $t->settle();
        Tui::freeze($bar);          // (the render's new instance: frozen as the one running)
        $s->open = false;
        $t->settle();
        expect($t->frozen())->toContain('copy')->toContain('100%')
            ->and($t->region())->not->toContain('copy');
        $t->detach();
    });
});
