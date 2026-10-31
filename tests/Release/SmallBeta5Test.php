<?php

/*
 * TODO-0.0.3-beta5 item 4, small things from use: Tui::go() coroutines stop with their program,
 * a Column that clips its rows and ScrollView following the focus, hasFocus() on an instance
 * handed over, Help with a fresh instance matched by place.
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\Quit;
use phasync\Tui\State;
use phasync\Tui\Tui;

it('stops the Tui::go() coroutines of a program when it quits: their Tui::sleep() throws Quit', function () {
    $log = [];
    $start = microtime(true);
    fiber\run(function () use (&$log) {
        $s = new State(['n' => 0]);
        // started before the program: it is the next program's
        Tui::go(function () use ($s, &$log) {
            try {
                while (true) {
                    Tui::sleep(0.05);
                    $s->n++;
                }
            } catch (Quit) {
                $log[] = 'cleaned up';
            }
        });
        $t = Tui::headless(fn() => new Text("n={$s->n}"), 20, 2);
        // started while it runs, ended by Quit without a catch of its own
        Tui::go(function () use (&$log) {
            while (true) {
                Tui::sleep(10);
            }
        });
        fiber\sleep(0.2);
        expect($s->n)->toBeGreaterThan(1);
        $t->press('ctrl+q');
        expect($t->component())->toBeNull();
    });
    // fiber\run() returned at once: the ten-second sleep was cut short
    expect(microtime(true) - $start)->toBeLessThan(2.0)->and($log)->toBe(['cleaned up']);
});

it('throws Quit from every Tui::sleep() of a coroutine whose program has ended', function () {
    fiber\run(function () {
        $t = Tui::headless(new Text('x'), 10, 1);
        $calls = [];
        Tui::go(function () use (&$calls) {
            fiber\sleep(0.1);         // (a sleep that cannot be cut short: the program ends meanwhile)
            foreach ([0.0, 5.0] as $s) {
                try {
                    Tui::sleep($s);
                    $calls[] = 'slept';
                } catch (Quit $e) {
                    $calls[] = $e->getMessage();
                }
            }
        });
        Tui::quit();
        fiber\sleep(0.2);
        expect($calls)->toBe(['The program has ended', 'The program has ended']);
    });
});

it('cuts the Tui::sleep() of a plain coroutine short when the program ends, and sleeps plainly without one', function () {
    fiber\run(function () {
        $start = microtime(true);
        Tui::sleep(0.01);                    // (no program: a plain sleep)
        $t = Tui::headless(new Text('x'), 10, 1);
        $ended = null;
        fiber\go(function () use (&$ended) {
            try {
                Tui::sleep(10);
            } catch (Quit) {
                $ended = microtime(true);
            }
        });
        fiber\sleep(0.05);
        $t->detach();
        fiber\sleep(0.05);
        expect($ended)->not->toBeNull()->and($ended - $start)->toBeLessThan(1.0);
    });
});

it('still ends the program with the exception of a Tui::go() coroutine, and Quit is not one', function () {
    $caught = null;
    try {
        fiber\run(function () {
            $t = Tui::headless(new Button('x'), 10, 1);
            Tui::go(function () {
                throw new Quit();           // (ends the coroutine, not the program)
            });
            fiber\sleep(0.05);
            expect($t->component()?->isMounted())->toBeTrue();
            Tui::go(function () {
                Tui::sleep(0.01);
                throw new RuntimeException('job failed');
            });
            fiber\sleep(0.1);
            expect($t->component())->toBeNull();
        });
    } catch (RuntimeException $e) {
        $caught = $e->getMessage();
    }
    expect($caught)->toBe('job failed');
});
