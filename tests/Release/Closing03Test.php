<?php

/* TODO-0.0.4-beta1 item 5: what closes 0.0.3. */

use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

it('closes a Dialog and a Confirm with timeout: by themselves, with the cancel answer, unless answered first', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Text('screen'), 50, 10);
        $t->settle();
        $started = microtime(true);
        $asked = Dialog::open(new Dialog('Bye', 'The program ended.', ['OK' => true], cancel: 'timed out', timeout: 0.3));
        $t->settle();
        expect($t->text())->toContain('The program ended.')
            ->and(fiber\await($asked))->toBe('timed out')
            ->and(microtime(true) - $started)->toBeGreaterThan(0.25);
        $t->settle();
        expect($t->text())->not->toContain('The program ended.');
        // answered before the time: the answer, and the timer does nothing after
        $asked = Confirm::ask('Sure', 'Go on?', timeout: 0.3);
        $t->settle();
        $t->press('y');
        expect(fiber\await($asked))->toBeTrue();
        $asked = Confirm::ask('Sure', 'Go on?', timeout: 0.2);
        expect(fiber\await($asked))->toBeFalse();
        $t->detach();
    });
});

it('emits exit from a Terminal with the status, and takes the element attributes', function () {
    fiber\run(function () {
        $s = new State(['code' => null, 'event' => null]);
        $term = new Terminal(['sh', '-c', 'exit 4'], onExit: fn(int $code) => $s->code = $code, class: 'grow', key: 'job');
        $term->on('exit', function (int $code, Terminal $t) use ($s, $term) {
            expect($t)->toBe($term);
            $s->event = $code;
        });
        $t = Tui::headless(fn() => new Column([new Text("code {$s->code} event {$s->event}"), $term]), 40, 8);
        expect($t->waitFor('code 4 event 4', 5))->toBeTrue()
            ->and($term->attribute('key'))->toBe('job')
            ->and($term->attribute('class'))->toBe(['grow']);
        $t->detach();
    });
});

it('caps the frames a second at fps: (60 by default)', function () {
    fiber\run(function () {
        $s = new State(['n' => 0]);
        $count = function (int $fps) use ($s): int {
            $t = Tui::headless(fn() => new Text("n {$s->n}"), 20, 3, fps: $fps);
            expect($t->frameRate())->toBe($fps);
            $t->settle();
            $t->takeOutput();
            $frames = 0;
            $until = microtime(true) + 0.5;
            while (microtime(true) < $until) {
                $s->n++;
                fiber\sleep(0.002);
            }
            $frames = substr_count($t->takeOutput(), "\e[?2026h");
            $t->detach();
            return $frames;
        };
        $ten = $count(10);
        $sixty = $count(60);
        expect($ten)->toBeLessThanOrEqual(7)->and($ten)->toBeGreaterThanOrEqual(3)
            ->and($sixty)->toBeGreaterThan(15)
            ->and((new FakeTerminal())->frameRate())->toBe(60);
    });
});
