<?php

/*
 * dispose() signals and waits for the end: a component ends (unmount) when its coroutines have
 * returned and its children have ended, at most $timeout later. Called from one of the
 * component's own coroutines it cannot wait for that one: it signals and returns, and the end
 * follows. Which coroutine is running comes from fiber\current(); no look at the call stack.
 */

use phasync\Tui\Component;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** Records the order of ends in a shared log. */
final class Ender extends Component
{
    /** @param list<string> $log */
    public function __construct(public string $name, public array &$log, public float $lingers = 0.0, public bool $deaf = false)
    {
    }

    protected function run(): void
    {
        if ($this->deaf) {
            fiber\sleep(0.4);               // does not listen: the end comes after the timeout
            return;
        }
        while ($this->sleep(10)) {
        }
        fiber\sleep($this->lingers);        // takes a moment to wind down
        $this->log[] = "$this->name returned";
    }

    protected function unmount(): void
    {
        $this->log[] = "$this->name unmounted";
    }
}

it('ends a parent after its children, each after its coroutines', function () {
    $log = [];
    $app = new class ($log) extends Component {
        public function __construct(public array &$log)
        {
        }

        protected function render(): ?Layout
        {
            return Split::horizontal()
                ->add($this->child('a', fn() => new Ender('a', $this->log, 0.05))->surface)
                ->add($this->child('b', fn() => new Ender('b', $this->log, 0.01))->surface);
        }

        protected function unmount(): void
        {
            $this->log[] = 'app unmounted';
        }
    };
    run(function () use ($app, &$log) {
        $app->start(new Surface(20, 2));
        sleep(0.01);
        $app->dispose();                        // waits for the end of the whole tree
        expect($log)->toBe(['b returned', 'b unmounted', 'a returned', 'a unmounted', 'app unmounted']);
    });
});

it('quits from a child\'s coroutine without waiting for that coroutine', function () {
    $log = [];
    $app = new class ($log) extends Component {
        public function __construct(public array &$log)
        {
        }

        protected function render(): ?Layout
        {
            $quitter = $this->child('quit', fn() => new class ($this->log) extends Component {
                public function __construct(public array &$log)
                {
                }

                protected function run(): void
                {
                    $this->sleep(0.01);
                    $this->parent()->dispose();         // the whole program, from in here
                    $this->log[] = 'quitter goes on: ' . ($this->isMounted() ? 'mounted' : 'disposed');
                }

                protected function unmount(): void
                {
                    $this->log[] = 'quitter unmounted';
                }
            });
            return Split::horizontal()->add($quitter->surface);
        }

        protected function unmount(): void
        {
            $this->log[] = 'app unmounted';
        }
    };
    $start = microtime(true);
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(20, 2));
    });
    expect($log)->toBe(['quitter goes on: disposed', 'quitter unmounted', 'app unmounted'])
        ->and(microtime(true) - $start)->toBeLessThan(0.5);
});

it('unmounts after the timeout when a coroutine does not listen', function () {
    $log = [];
    $deaf = new Ender('deaf', $log, deaf: true);
    run(function () use ($deaf, &$log) {
        $deaf->start(new Surface(10, 1));
        sleep(0.01);
        $start = microtime(true);
        $deaf->dispose(0.1);
        expect($log)->toBe(['deaf unmounted'])
            ->and(microtime(true) - $start)->toBeGreaterThan(0.09)->toBeLessThan(0.3);
    });
});

it('has unmounted when dispose() returns, called from a key handler or another coroutine', function () {
    $log = [];
    $app = new class ($log) extends Component {
        public function __construct(public array &$log)
        {
        }

        protected function render(): ?Layout
        {
            $layout = Split::horizontal()->add($this->child('main', fn() => new Ender('main', $this->log))->surface);
            if ($this->child('dialog') !== null) {
                $layout->add($this->child('dialog')->surface);
            }
            return $layout;
        }

        public function open(string $name, float $lingers): Component
        {
            $dialog = $this->child('dialog', fn() => new Ender($name, $this->log, $lingers));
            $this->requestRender();
            return $dialog;
        }

        protected function onKey(phasync\Tui\Key $key): bool
        {
            if ($key->is('escape')) {
                $this->child('dialog')?->dispose();
                $this->log[] = 'dispose returned';
                return true;
            }
            return false;
        }
    };
    run(function () use ($app, &$log) {
        $app->start($t = new FakeTerminal(30, 3));
        try {
            $app->open('dialog', 0.05);
            sleep(0.01);
            $t->press('escape');                    // a key handler closes the dialog
            expect($log)->toBe(['dialog returned', 'dialog unmounted', 'dispose returned']);
            $log = [];
            $dialog = $app->open('second', 0.02);
            sleep(0.01);
            $other = fiber\go(function () use ($dialog, &$log) {
                $dialog->dispose();                 // from a coroutine that is not the dialog's
                $log[] = 'dispose returned';
            });
            fiber\await($other);
            expect($log)->toBe(['second returned', 'second unmounted', 'dispose returned']);
        } finally {
            $t->detach();
        }
    });
});

it('does not wait for the coroutine it is called from, also before that coroutine first waited', function () {
    $log = [];
    $self = new class ($log) extends Component {
        public function __construct(public array &$log)
        {
        }

        protected function run(): void
        {
            $this->dispose();                       // right away, before any suspension
            $this->log[] = 'run goes on: ' . ($this->isMounted() ? 'mounted' : 'disposed');
        }

        protected function unmount(): void
        {
            $this->log[] = 'unmounted';
        }
    };
    $start = microtime(true);
    run(function () use ($self) {
        $self->start(new Surface(10, 1));
    });
    expect($log)->toBe(['run goes on: disposed', 'unmounted'])
        ->and(microtime(true) - $start)->toBeLessThan(0.5);   // not the 1 s timeout
});

it('forgets a child that was disposed: child($key) finds nothing, and a factory makes a new one', function () {
    $app = new class extends Component {
        public function find(string $key): ?Component
        {
            return $this->child($key);
        }

        public function make(string $key): Component
        {
            return $this->child($key, fn() => new class extends Component {
                protected function run(): void
                {
                    while ($this->sleep(10)) {
                    }
                }
            });
        }
    };
    run(function () use ($app) {
        $app->start(new Surface(10, 1));
        $first = $app->make('dialog');
        $first->dispose();
        expect($app->find('dialog'))->toBeNull()     // not the dead object until the next render
            ->and($app->make('dialog'))->not->toBe($first)
            ->and($app->find('dialog')->isMounted())->toBeTrue();
        $app->dispose();
    });
});

it('drops a child a render no longer asks for without waiting, and still ends the parent after it', function () {
    $log = [];
    $app = new class ($log) extends Component {
        public bool $show = true;

        public function __construct(public array &$log)
        {
        }

        protected function render(): ?Layout
        {
            $layout = Split::horizontal()->add(new Surface());
            if ($this->show) {
                $layout->add($this->child('slow', fn() => new Ender('slow', $this->log, 0.1))->surface);
            }
            return $layout;
        }

        protected function unmount(): void
        {
            $this->log[] = 'app unmounted';
        }
    };
    run(function () use ($app, &$log) {
        $app->start(new Surface(20, 2));
        sleep(0.01);
        $app->show = false;
        $start = microtime(true);
        $app->requestRender();
        sleep(0.001);
        expect(microtime(true) - $start)->toBeLessThan(0.05)    // the render did not wait
            ->and($log)->toBe([]);
        $app->dispose();                                         // waits for the dropped child too
        expect($log)->toBe(['slow returned', 'slow unmounted', 'app unmounted']);
    });
});

it('finds an existing child by key without a factory, and null when there is none', function () {
    $app = new class extends Component {
        public function find(string $key): ?Component
        {
            return $this->child($key);
        }

        public function make(string $key): Component
        {
            return $this->child($key, fn() => new class extends Component {
            });
        }
    };
    run(function () use ($app) {
        $app->start(new Surface(10, 1));
        expect($app->find('dialog'))->toBeNull();
        $made = $app->make('dialog');
        expect($app->find('dialog'))->toBe($made)->and($app->find('other'))->toBeNull();
        $app->dispose();
    });
});
