<?php

/*
 * Tui::nextFrame(): a loop paced by the frames the terminal gets, one step per frame, with the
 * time between them; it ends with the program.
 */

use phasync\Tui\Element\Text;
use phasync\Tui\Quit;
use phasync\Tui\State;
use phasync\Tui\Tui;

it('returns once per frame, at the frame rate although nothing changed, with the seconds since the frame before', function () {
    $log = new ArrayObject();
    \fiber\run(function () use ($log) {
        Tui::go(function () use ($log) {
            while (true) {
                $log[] = Tui::nextFrame();      // (writes no state: the screen is still)
            }
        });
        $t = Tui::headless(fn() => new Text('still'), 20, 3);
        $t->settle();
        $before = count($log);
        \fiber\sleep(0.2);                      // (no settle(): the device's own frame loop, 60 a second)
        $frames = array_slice($log->getArrayCopy(), $before);
        expect(count($frames))->toBeGreaterThan(4)->toBeLessThan(40)
            ->and(array_sum($frames))->toBeGreaterThan(0.1)->toBeLessThan(0.4)
            ->and(min($frames))->toBeGreaterThan(0.0);
        $t->detach();                           // the wait ends: the coroutine gets Quit and ends
    });
    expect(true)->toBeTrue();                   // (fiber\run returned: the loop ended with the program)
});

it('paces a loop that changes state: one step per frame, drawn in the next', function () {
    $steps = new ArrayObject();
    \fiber\run(function () use ($steps) {
        $s = new State(['n' => 0]);
        Tui::go(function () use ($s, $steps) {
            while (true) {
                $steps[] = Tui::nextFrame();
                $s->n++;
            }
        });
        $t = Tui::headless(fn() => new Text(fn() => "frame {$s->n}"), 20, 3);
        $t->settle();
        \fiber\sleep(0.1);
        $t->settle();
        expect($s->n)->toBe(count($steps))->and($t->text())->toContain('frame ' . $s->n);
        $t->detach();
    });
});

it('throws Quit in a Tui::go() coroutine once the program has ended, and returns early elsewhere', function () {
    $seen = new ArrayObject();
    \fiber\run(function () use ($seen) {
        Tui::go(function () use ($seen) {
            try {
                while (true) {
                    Tui::nextFrame();
                }
            } catch (Quit) {
                $seen[] = 'quit';
            }
        });
        $t = Tui::headless(fn() => new Text('x'), 10, 2);
        $t->settle();
        $started = microtime(true);
        \fiber\go(function () use ($t) {
            \fiber\sleep(0.01);
            $t->detach();
        });
        Tui::nextFrame();                           // outside Tui::go(): returns when the program ends
        $seen[] = 'returned';
        expect(microtime(true) - $started)->toBeLessThan(0.5);
    });
    expect($seen->getArrayCopy())->toContain('quit')->toContain('returned');
});
