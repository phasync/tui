<?php

/* TODO-0.0.3-beta8 item 3: small things from use. */

use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
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

it('draws a bar that jumps anywhere as the bar drawn whole at its value', function () {
    fiber\run(function () {
        // (the cells a move changes are found from the edges of the fill before and now)
        mt_srand(8);
        $s = new State(['v' => 0.0]);
        $t = Tui::headless(fn() => new Column([new ProgressBar(fn() => $s->v, '')]), 40, 1);
        $t->settle();
        for ($i = 0; $i < 40; $i++) {
            $v = [0.0, 1.0, mt_rand(0, 100) / 100, $s->v + 0.025][mt_rand(0, 3)];
            $s->v = min(1.0, $v);
            $t->settle();
            $u = Tui::headless(fn() => new Column([new ProgressBar(min(1.0, $v), '')]), 40, 1);
            $u->settle();
            expect($t->dump())->toBe($u->dump());
            $u->detach();
        }
        $t->detach();
    });
});
