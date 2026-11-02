<?php

/*
 * Stopwatch and Timer, through FakeTerminal (real time, short spans, wide tolerances).
 */

use phasync\Tui\Component\Stopwatch;
use phasync\Tui\Component\Timer;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

it('formats times as MM:SS, with decimals, and with hours from an hour on', function () {
    expect(Stopwatch::format(0))->toBe('00:00');
    expect(Stopwatch::format(75.9))->toBe('01:15');
    expect(Stopwatch::format(75.94, 1))->toBe('01:15.9');
    expect(Stopwatch::format(5.07, 2))->toBe('00:05.07');
    expect(Stopwatch::format(3723))->toBe('1:02:03');
    expect(Stopwatch::format(-4))->toBe('00:00');
});

it('counts while running, stops with Space and a click, and resets with r', function () {
    $ticks = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('w', fn() => new Stopwatch(precision: 1, onTick: function ($e) use ($ticks) { $ticks[] = $e; })),
    ]));
    mountOn($app, 12, 2, function (FakeTerminal $t, WidgetScreen $app) use ($ticks) {
        $t->settle();
        $w = $app->children()['w'];
        expect(screenRows($t)[0])->toBe('00:00.0');
        expect(count($ticks))->toBe(0);                         // stopped: no ticks
        $w->focus();
        $t->press('space');
        expect($w->isRunning())->toBeTrue();
        \fiber\sleep(0.45);
        $t->settle();
        expect(screenRows($t)[0])->toMatch('/^00:00\.[3-6]$/');
        expect(count($ticks))->toBeGreaterThan(1);
        $t->click(2, 0);                                        // stop
        $stopped = $w->elapsed();
        expect($w->isRunning())->toBeFalse();
        expect($stopped)->toBeBetween(0.3, 0.8);
        \fiber\sleep(0.2);
        expect($w->elapsed())->toBe($stopped);                  // stopped time stays
        $t->press('space');                                     // goes on from there
        \fiber\sleep(0.15);
        expect($w->elapsed())->toBeGreaterThan($stopped + 0.1);
        $t->press('r');
        $t->settle();
        expect($w->isRunning())->toBeFalse();
        expect($w->elapsed())->toBe(0.0);
        expect(screenRows($t)[0])->toBe('00:00.0');
    });
});

it('counts down in a Timer, rounds up, calls onFinish once and starts over from a finished state', function () {
    $finished = new ArrayObject();
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
        $s->make('t', fn() => new Timer(0.35, running: true, precision: 1, onFinish: function ($timer) use ($finished) { $finished[] = $timer->remaining(); })),
    ]));
    mountOn($app, 12, 2, function (FakeTerminal $t, WidgetScreen $app) use ($finished) {
        $t->settle();
        $timer = $app->children()['t'];
        expect(screenRows($t)[0])->toMatch('/^00:00\.[1-4]$/');   // 0.4 .. 0.1 left
        \fiber\sleep(0.7);
        $t->settle();
        expect(screenRows($t)[0])->toBe('00:00.0');
        expect($timer->isFinished())->toBeTrue();
        expect($timer->isRunning())->toBeFalse();
        expect($finished->getArrayCopy())->toBe([0.0]);
        $timer->focus();
        $t->press('space');                                      // a finished timer starts over
        expect($timer->isFinished())->toBeFalse();
        expect($timer->isRunning())->toBeTrue();
        $t->press('r');
        $t->settle();
        expect(screenRows($t)[0])->toBe('00:00.4');                // 0.35 shown rounded up
        expect($timer->remaining())->toBe(0.35);
    });
});

it('ignores keys and clicks when disabled, and rejects bad arguments', function () {
    $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('w', fn() => new Stopwatch(disabled: true))]));
    mountOn($app, 12, 2, function (FakeTerminal $t, WidgetScreen $app) {
        $t->settle();
        $w = $app->children()['w'];
        $w->focus();
        $t->press('space');
        $t->click(1, 0);
        expect($w->isRunning())->toBeFalse();
        $w->play();                                             // the program still may
        expect($w->isRunning())->toBeTrue();
        expect(fn() => new Stopwatch(precision: 3))->toThrow(InvalidArgumentException::class);
        expect(fn() => new Timer(0))->toThrow(InvalidArgumentException::class);
    });
});
