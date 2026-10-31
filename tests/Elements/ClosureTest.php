<?php

/*
 * One file, no class (SPEC 9.1): a closure is a component, element content may be a closure,
 * and the runners take elements and closures.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** A component that counts its renders and shows a static tree around a dynamic part. */
final class StaticAround extends Component
{
    public int $renders = 0;

    public function __construct(private State $s)
    {
    }

    protected function render(): Element
    {
        $this->renders++;
        $s = $this->s;
        return new Column([
            new Text('header'),
            new Text(fn() => "count {$s->n}"),
            new Box(new Text('body'), fn() => "box {$s->n}"),
            new ProgressBar(fn() => $s->n / 10),
        ]);
    }
}

/** The example of SPEC 9.1, as a program writes it: the state, a coroutine, the runner. */
function spec91(int $cols, int $rows, bool $inline = false): FakeTerminal
{
    $s = new State(['done' => 0]);
    \fiber\go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { \fiber\sleep(0.002); $s->done = $i; } });
    return Tui::headless(fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
    ]), $cols, $rows, $inline);
}

it('runs the example of SPEC 9.1 headless at two sizes, full screen and inline', function (int $cols, int $rows, bool $inline) {
    \fiber\run(function () use ($cols, $rows, $inline) {
        $t = spec91($cols, $rows, $inline);
        $t->settle();
        expect($t->waitFor('Working… 100%', 5))->toBeTrue();
        $t->settle();
        $lines = screenRows($t);
        expect($lines[0])->toBe('Working… 100%')
            ->and($lines[1])->toBe(str_repeat('█', $cols - 5) . ' 100%');
        if ($inline) {
            expect($t->region())->toStartWith('Working… 100%');
        }
        $t->press('ctrl+q');
        expect($t->isAttached())->toBeFalse();
    });
})->with([
    '40x10' => [40, 10, false],
    '120x30' => [120, 30, false],
    'inline 60x8' => [60, 8, true],
]);

it('renders a closure root again when a State it read changes from a coroutine, and only then', function () {
    \fiber\run(function () {
        $s = new State(['n' => 0, 'unread' => 'x']);
        $calls = 0;
        $t = Tui::headless(function () use ($s, &$calls) {
            $calls++;
            return new Text("n={$s->n}");
        }, 20, 3);
        $t->settle();
        expect($calls)->toBe(1)->and($t->text())->toStartWith('n=0');
        \fiber\go(function () use ($s) {
            \fiber\sleep(0.01);
            $s->n = 1;
        });
        \fiber\sleep(0.1);
        $t->settle();
        expect($calls)->toBe(2)->and($t->text())->toStartWith('n=1');
        $s->n = 1;                  // the same value: no render
        $t->settle();
        \fiber\sleep(0.05);
        expect($calls)->toBe(2);
        $t->press('x', 'y');        // keys it has no use for: no render
        $t->settle();
        expect($calls)->toBe(2);
        $s->set(n: 2);
        $s->n = 3;                  // two writes in one moment: one render
        $t->settle();
        expect($calls)->toBe(3)->and($t->text())->toStartWith('n=3');
        $t->detach();
    });
});

it('updates a closure Text, a Box title and a ProgressBar value inside a static tree without rendering the parent', function () {
    \fiber\run(function () {
        $s = new State(['n' => 0]);
        $app = new StaticAround($s);
        $t = Tui::headless($app, 30, 8);
        $t->settle();
        $before = $app->renders;
        expect($t->text())->toContain('count 0')->and($t->text())->toContain('box 0');
        for ($i = 1; $i <= 5; $i++) {
            $s->n = $i;
            $t->settle();
        }
        expect($t->text())->toContain('count 5')->and($t->text())->toContain('box 5')
            ->and(screenRows($t)[5])->toBe(str_repeat('█', 13) . str_repeat('░', 13) . ' 50%')
            ->and($app->renders)->toBe($before);
        $t->detach();
    });
});

it('quits a closure root with Ctrl-Q when nothing binds it, and gives a closure that read no state its component as the root', function () {
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Text('hello'), 20, 3);
        $t->settle();
        $t->press('ctrl+q');
        expect($t->isAttached())->toBeFalse();
        $root = null;
        $t = Tui::headless(function () use (&$root) {
            return $root = new StaticAround(new State(['n' => 1]));
        }, 30, 8);
        $t->settle();
        expect($root->isMounted())->toBeTrue()->and($root->parent())->toBeNull();      // the root itself
        $root->dispose();
        expect($t->isAttached())->toBeFalse();
    });
});

it('ends the program with an exception thrown by the closure, the terminal given back', function () {
    $t = null;
    $run = function () use (&$t) {
        \fiber\run(function () use (&$t) {
            $s = new State(['n' => 0]);
            $t = Tui::headless(fn() => $s->n > 0 ? throw new \RuntimeException('closure failed') : new Text('fine'), 20, 3);
            $t->settle();
            \fiber\go(function () use ($s) {
                \fiber\sleep(0.01);
                $s->n = 1;
            });
        });
    };
    expect($run)->toThrow(\RuntimeException::class, 'closure failed');
    expect($t->isAttached())->toBeFalse();
    // and at once, when the first call throws
    expect(fn() => \fiber\run(fn() => Tui::headless(fn() => throw new \LogicException('at once'), 20, 3)))->toThrow(\LogicException::class, 'at once');
});

it('subscribes a component to any State its render reads, not only its own', function () {
    $shared = new State(['label' => 'a']);
    $app = new class ($shared) extends Component {
        public int $renders = 0;

        public function __construct(private State $other)
        {
        }

        protected function render(): Element
        {
            $this->renders++;
            return new Text($this->other->label);
        }
    };
    mountOn($app, 10, 2, function (FakeTerminal $t, Component $app) use ($shared) {
        $t->settle();
        $shared->label = 'b';
        $t->settle();
        expect($t->text())->toStartWith('b')->and($app->renders)->toBe(2);
    });
});
