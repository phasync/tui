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

it('ends the Tui::go() coroutines waiting for a program whose runner fails before it starts', function () {
    $ended = false;
    $start = microtime(true);
    try {
        fiber\run(function () use (&$ended) {
            Tui::go(function () use (&$ended) {
                try {
                    while (true) {
                        Tui::sleep(10);
                    }
                } finally {
                    $ended = true;
                }
            });
            Tui::headless(new Text('x'), 10, 1, theme: 'no such theme');
        });
    } catch (InvalidArgumentException) {
    }
    expect($ended)->toBeTrue()->and(microtime(true) - $start)->toBeLessThan(2.0);
});

/** A field with a described key, made anew by every render of the tests below. */
final class HintedField extends phasync\Tui\Component
{
    public string $label = '';
    public int $cuts = 0;

    public function __construct(string $label = '')
    {
        $this->label = $label;
    }

    protected function bindings(): array
    {
        return ['ctrl+x' => ['cut', 'cut it']];
    }

    public function cut(): void
    {
        $this->cuts++;
    }

    protected function render(): phasync\Tui\Element
    {
        return new Text("[{$this->label}]");
    }
}

it('answers hasFocus() of a new instance a render handed over with the running one\'s answer; running() gives that one', function () {
    fiber\run(function () {
        $s = new State(['n' => 0]);
        $made = [];
        $t = Tui::headless(function () use ($s, &$made) {
            $made[] = $field = new HintedField("f{$s->n}");
            return new Column([$field, new Button('ok'), new Text(fn() => 'focused: ' . ($field->hasFocus() ? 'yes' : 'no'))]);
        }, 30, 4);
        $t->settle();
        expect($t->text())->toContain('focused: yes');
        $s->n++;
        $t->settle();
        $fresh = end($made);
        $running = $fresh->running();
        expect($fresh->isMounted())->toBeFalse()
            ->and($running)->toBeInstanceOf(HintedField::class)->and($running->isMounted())->toBeTrue()
            ->and($running->label)->toBe('f1')
            ->and($fresh->hasFocus())->toBeTrue()->and($t->text())->toContain('[f1]');
        $t->press('tab');
        $t->settle();
        expect($fresh->hasFocus())->toBeFalse()->and($t->text())->toContain('focused: no')
            ->and((new HintedField())->running())->toBeNull()->and((new HintedField())->hasFocus())->toBeFalse();
        $t->detach();
    });
});

it('shows the focused component\'s keys in Help, also for a new instance matched by place, in Tabs too', function () {
    fiber\run(function () {
        $s = new State(['n' => 0]);
        $t = Tui::headless(function () use ($s) {
            $field = new HintedField("f{$s->n}");
            $content = new Column([$field, new Button('ok')]);
            return new Column([
                new phasync\Tui\Component\Tabs(['one' => ['label' => 'One', 'content' => $content], 'two' => ['label' => 'Two', 'content' => new Text('two')]]),
                new phasync\Tui\Element\Help(),
                new phasync\Tui\Element\Help(for: $field, show: 'focused'),
            ]);
        }, 80, 6);
        $t->settle();
        [, , , $help, $own] = screenRows($t);
        expect($help)->toStartWith('ctrl+x cut it')->and($own)->toBe('ctrl+x cut it');
        $s->n++;
        $t->settle();
        [, , , $help, $own] = screenRows($t);
        expect($help)->toStartWith('ctrl+x cut it')->and($own)->toBe('ctrl+x cut it');
        $t->press('ctrl+x');
        expect($t->component()->children())->not->toBeEmpty();
        $t->detach();
    });
});

/** Twelve buttons (or cards with onKey, or rows of a label and a button with onKey) between a header and a footer, in a ScrollView or not. */
function twelve(string $kind, bool $scroll): Closure
{
    return function () use ($kind, $scroll) {
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = match ($kind) {
                'cards' => new Text("card $i", onKey: ['x' => fn() => null]),
                'rows' => new phasync\Tui\Element\Row([new Text("row $i "), new Button("go $i")], onKey: ['p' => fn() => null]),
                default => new Button("button $i", key: "b$i"),
            };
        }
        $list = new Column($items);
        return new Column([new Text('header'), $scroll ? new phasync\Tui\Component\ScrollView($list) : $list, new Text('footer')]);
    };
}

it('cuts a Column of more rows than fit, and the Buttons cut off are out of the Tab walk', function () {
    fiber\run(function () {
        $t = Tui::headless(twelve('buttons', false), 30, 7);
        $t->settle();
        $shown = array_slice(screenRows($t), 1, 5);
        expect($shown[0])->toContain('button 0')->and($shown[4])->toContain('button 4')
            ->and(screenRows($t)[6])->not->toContain('button 5');
        // (the Tab walk visits buttons 0 to 4 only: what is cut off cannot take the focus)
        $focused = [];
        for ($k = 0; $k < 10; $k++) {
            $t->press('tab');
            foreach ($t->component()->children() as $child) {
                if ($child->hasFocus()) {
                    $focused[$child->label] = true;
                }
            }
        }
        ksort($focused);
        expect(array_keys($focused))->toBe(['button 0', 'button 1', 'button 2', 'button 3', 'button 4']);
        $t->detach();
    });
});

it('scrolls what takes the focus into a ScrollView\'s view: by Tab, Shift-Tab, focus() and after the user scrolled away', function (string $kind) {
    fiber\run(function () use ($kind) {
        $t = Tui::headless(twelve($kind, true), 30, 7);
        $t->settle();
        $label = ['buttons' => 'button', 'cards' => 'card', 'rows' => 'row'][$kind];
        for ($k = 0; $k < 9; $k++) {
            $t->press('tab');
        }
        $t->settle();
        expect(implode("\n", screenRows($t)))->toContain("$label 9");
        $t->press('shift+tab', 'shift+tab', 'shift+tab', 'shift+tab', 'shift+tab', 'shift+tab', 'shift+tab', 'shift+tab');
        $t->settle();
        expect(screenRows($t)[1])->toContain("$label 1");
        if ($kind === 'buttons') {
            $view = array_values($t->component()->children())[0];
            $view->children()['b11']->focus();
            $t->settle();
            expect(screenRows($t)[5])->toContain('button 11');
            $view->scrollTo(0);       // (the user scrolls away from the focused button)
            $t->settle();
            expect(screenRows($t)[1])->toContain('button 0');
            $t->press('shift+tab');    // button 10 takes the focus: into view
            $t->settle();
            expect(implode("\n", screenRows($t)))->toContain('button 10');
        }
        $t->detach();
    });
})->with(['buttons', 'cards', 'rows']);
