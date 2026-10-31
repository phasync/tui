<?php

/*
 * Reactive rendering: state writes, handlers, coroutine steps, child events and new props
 * render a component again in the next frame, without requestRender(), once per frame.
 */

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\State;

final class CounterState extends State
{
    public int $count = 0;
    public string $label = 'n';
}

/** Shows a counter; its state may be its own or one it was given. */
final class Counter extends Component
{
    public int $renders = 0;
    public int $propChanges = 0;

    public function __construct(?State $state = null, public string $title = '')
    {
        $this->state = $state ?? new CounterState();
    }

    protected function bindings(): array
    {
        return ['+' => 'increment'];
    }

    public function increment(): void
    {
        $this->state->count++;
    }

    public function count(): int
    {
        return $this->state->count;
    }

    public function state(): State
    {
        return $this->state;
    }

    protected function propsChanged(): void
    {
        $this->propChanges++;
    }

    protected function render(): Element
    {
        $this->renders++;
        return new Text("{$this->title}{$this->state->label}={$this->state->count}");
    }
}

it('shows a counter changed in a handler without requestRender()', function () {
    $counter = new class extends Component {
        protected function onKey(Key $key): bool
        {
            $this->state->count = ($this->state->count ?? 0) + 1;    // a plain State
            return true;
        }

        protected function render(): Element
        {
            return new Text('count ' . ($this->state->count ?? 0));
        }
    };
    mountOn($counter, 10, 1, function (FakeTerminal $t) {
        $t->press('x', 'x', 'x');
        frame();
        expect($t->text())->toBe('count 3');
    });
});

it('renders after a handler that took the event, also without a state', function () {
    $app = new class extends Component {
        public int $n = 0;

        protected function onKey(Key $key): bool
        {
            $this->n++;
            return true;
        }

        protected function render(): Element
        {
            return new Text("n={$this->n}");
        }
    };
    mountOn($app, 10, 1, function (FakeTerminal $t) {
        $t->press('a');
        frame();
        expect($t->text())->toBe('n=1');
    });
});

it('checks writes against the declared types, and renders nothing for an unchanged value', function () {
    $counter = new Counter();
    mountOn($counter, 10, 1, function (FakeTerminal $t, Counter $c) {
        $renders = $c->renders;
        $c->state()->count = 0;
        frame();
        expect($c->renders)->toBe($renders);
        $c->state()->count = '5';       // coerced as a typed property would
        frame();
        expect($c->count())->toBe(5)->and($t->text())->toBe('n=5')
            ->and(fn() => $c->state()->count = 'many')->toThrow(TypeError::class);
        $c->state()->set(count: 7, label: 'k');
        frame();
        expect($t->text())->toBe('k=7')->and($c->state()->toArray())->toBe(['count' => 7, 'label' => 'k']);
    });
});

it('renders a coroutine loop that changes the state once per frame, not once per write', function () {
    $app = new class extends Component {
        public int $renders = 0;
        public int $writes = 0;
        public bool $halt = false;

        protected function run(): void
        {
            while ($this->sleep(0.005) && !$this->halt) {
                for ($i = 0; $i < 50; $i++) {
                    $this->state->ticks = ($this->state->ticks ?? 0) + 1;
                    $this->writes++;
                }
            }
        }

        protected function render(): Element
        {
            $this->renders++;
            return new Text('ticks ' . ($this->state->ticks ?? 0));
        }
    };
    mountOn($app, 20, 1, function (FakeTerminal $t, Component $app) {
        \fiber\sleep(0.15);  // (a step a frame: sleep() ends at a tick of the frame clock)
        $app->halt = true;  // (no step between the screen and the count read below)
        \fiber\sleep(0.01);
        $t->settle();       // (the render of the last step)
        expect($app->writes)->toBeGreaterThan(200)
            // the first, one per step (not per write), and the two of the halt: its last sleep and the end of run()
            ->and($app->renders)->toBeLessThanOrEqual(intdiv($app->writes, 50) + 4)
            ->and($t->text())->toBe('ticks ' . $app->writes);
    });
});

it('renders after a coroutine step that changed plain properties', function () {
    $app = new class extends Component {
        public int $seconds = 0;

        protected function run(): void
        {
            while ($this->sleep(0.01)) {
                $this->seconds++;
            }
        }

        protected function render(): Element
        {
            return new Text("up {$this->seconds}s");
        }
    };
    mountOn($app, 10, 1, function (FakeTerminal $t, Component $app) {
        \fiber\sleep(0.05);
        frame();
        expect($t->text())->toBe("up {$app->seconds}s")->and($app->seconds)->toBeGreaterThan(1);
    });
});

it('shares state between components through props: a write renders all of them', function () {
    $app = new class extends Component {
        public CounterState $shared;

        protected function mount(): void
        {
            $this->shared = new CounterState();
        }

        protected function render(): Element
        {
            return new Column([
                $this->child('a', fn() => new Counter($this->shared, 'a:')),
                $this->child('b', fn() => new Counter($this->shared, 'b:')),
            ]);
        }

        public function counter(string $key): Counter
        {
            return $this->child($key);
        }
    };
    mountOn($app, 10, 2, function (FakeTerminal $t, Component $app) {
        $app->counter('a')->focus();
        $t->press('+', '+');
        frame();
        expect($t->text())->toBe("a:n=2\nb:n=2");
        $app->counter('b')->focus();
        $t->press('+');
        frame();
        expect($t->text())->toBe("a:n=3\nb:n=3");
    });
});

it('passes new props to the component at the same place, which keeps its state', function () {
    $app = new class extends Component {
        public string $title = 'one ';
        public ?Counter $placed = null;

        protected function render(): Element
        {
            return new Column([new Counter(title: $this->title), new Text('x')]);
        }
    };
    mountOn($app, 12, 2, function (FakeTerminal $t, Component $app) {
        $t->press('+', '+');
        frame();
        expect($t->text())->toBe("one n=2\nx");
        $app->title = 'two ';
        $app->requestRender();
        frame();
        expect($t->text())->toBe("two n=2\nx");
        $app->requestRender();      // same props: nothing for the child to do
        frame();
        expect($t->text())->toBe("two n=2\nx");
    });
});

/** Emits `bump` when told to. */
final class Bumper extends Component
{
    public function fire(): void
    {
        $this->emit('bump');
    }
}

it('renders a component after an event of a child it listens to', function () {
    $app = new class extends Component {
        public int $seen = 0;

        protected function render(): Element
        {
            $b = $this->child('b', function () {
                $b = new Bumper();
                $b->on('bump', function () {
                    $this->seen++;      // a closure of this component: it renders again
                });
                return $b;
            });
            return new Column([$b, new Text("seen {$this->seen}")]);
        }

        public function bumper(): Bumper
        {
            return $this->child('b');
        }
    };
    mountOn($app, 12, 2, function (FakeTerminal $t, Component $app) {
        $app->bumper()->fire();
        frame();
        expect($t->text())->toBe("\nseen 1");
    });
});

it('does not take the focus back when a render that focuses runs again after a key moved it', function () {
    $app = new class extends Component {
        protected function onKey(Key $key): bool
        {
            if ($key->is('alt+right')) {
                $this->child('b')->focus();       // handled: a render follows
                return true;
            }
            return $key->is('f1');                 // handled too: another render
        }

        protected function render(): Element
        {
            $a = $this->child('a', fn() => new Counter());
            $b = $this->child('b', fn() => new Counter());
            $a->focus();                           // the field the screen starts in, asked on every render
            return new Column([$a, $b]);
        }

        public function counter(string $key): Counter
        {
            return $this->child($key);
        }
    };
    mountOn($app, 10, 2, function (FakeTerminal $t, Component $app) {
        expect($app->counter('a')->hasFocus())->toBeTrue();
        $t->press('alt+right');
        frame();
        $t->press('f1');
        frame();
        $t->press('+');
        expect($app->counter('b')->hasFocus())->toBeTrue()->and($app->counter('b')->count())->toBe(1);
        $app->requestRender();                    // asked for: the render's focus() takes effect again
        frame();
        expect($app->counter('a')->hasFocus())->toBeTrue();
    });
});

it('ends the sleep of a component at a tick of the frame clock, so loops begun at different moments wake together', function () {
    $woke = new ArrayObject();
    $make = fn(string $name, float $after) => new class ($name, $after, $woke) extends Component {
        public function __construct(private string $name, private float $after, private ArrayObject $woke)
        {
        }

        protected function run(): void
        {
            \fiber\sleep($this->after);         // (begins its loop a little later than the other)
            $steps = 0;
            while ($this->sleep(0.1) && ++$steps <= 3) {
                $this->woke[] = [$this->name, microtime(true)];
            }
        }

        protected function render(): Element
        {
            return new Text($this->name);
        }
    };
    $a = $make('a', 0.0);
    $b = $make('b', 0.006);
    $app = new class ($a, $b) extends Component {
        public function __construct(private Component $a, private Component $b)
        {
        }

        protected function render(): Element
        {
            return new Column([$this->a, $this->b]);
        }
    };
    mountOn($app, 10, 2, function (FakeTerminal $t) use ($woke) {
        $start = microtime(true);
        \fiber\sleep(0.45);
        $at = [];
        foreach ($woke as [$name, $time]) {
            $at[$name][] = $time;
        }
        expect($at['a'])->toHaveCount(3)->and($at['b'])->toHaveCount(3);
        foreach ([...$at['a'], ...$at['b']] as $time) {
            // on a tick (a sixtieth of a second), however their loops were offset
            expect(fmod($time, 1 / 60))->toBeLessThan(0.006);
        }
        // still about ten steps a second: a sleep(0.1) ends at most 2 ms early, one tick late
        expect($at['a'][2] - $at['a'][0])->toBeGreaterThan(0.19)->toBeLessThan(0.24);
    });
});
