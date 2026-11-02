<?php

use phasync\Tui\Component\Button;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\Tui;

it('is pressed by a click on its label only, not on the rest of the row a column stretches it over', function () {
    $pressed = new ArrayObject();       // (shared: the root closure is an arrow function, which captures by value)
    \fiber\run(function () use ($pressed) {
        $t = Tui::headless(fn() => new Column([new Text('x'), new Button('Go', fn() => $pressed[] = 1)]), 40, 5);
        $t->settle();
        expect(rtrim(explode("\n", $t->text())[1]))->toBe('[ Go ]');
        $t->click(30, 1);                       // right of the label, in the button's surface
        $t->settle();
        expect(count($pressed))->toBe(0);
        $t->click(3, 1);
        $t->settle();
        expect(count($pressed))->toBe(1);
        $t->detach();
    });
});
