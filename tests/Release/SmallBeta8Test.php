<?php

/* TODO-0.0.3-beta8 item 3: small things from use. */

use phasync\Tui\Component\Confirm;
use phasync\Tui\Element\Text;
use phasync\Tui\Tui;

it('answers a Confirm with y and n, as with Enter and Escape', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Text('screen'), 50, 10);
        $t->settle();
        $answers = [];
        foreach (['y' => true, 'n' => false, 'enter' => true, 'escape' => false, 'Y' => true] as $key => $want) {
            $asked = Confirm::ask('Sure', 'Delete it?');
            $t->settle();
            expect($t->text())->toContain('Delete it?');
            $t->press($key === 'Y' ? 'shift+y' : $key);
            $t->settle();
            $answers[$key] = fiber\await($asked);
            expect($t->text())->not->toContain('Delete it?');
        }
        expect($answers)->toBe(['y' => true, 'n' => false, 'enter' => true, 'escape' => false, 'Y' => true]);
        // labels of their own answer with their first letters; two that start alike only with Enter
        $asked = Confirm::ask('Files', 'Keep the files?', 'Keep', 'Delete');
        $t->settle();
        $t->press('d');
        expect(fiber\await($asked))->toBeFalse();
        $asked = Confirm::ask('Files', 'Send it?', 'Send', 'Skip');
        $t->settle();
        $t->press('s');
        $t->settle();
        expect($t->text())->toContain('Send it?');
        $t->press('escape');
        expect(fiber\await($asked))->toBeFalse();
        $t->detach();
    });
});
