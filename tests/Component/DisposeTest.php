<?php

/*
 * dispose() signals and returns; a component ends (unmount) when its coroutines have returned
 * and its children have ended, at most $timeout later. No look at the call stack.
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
        $app->dispose();
        expect($log)->toBe([]);                 // nothing waited for in dispose()
    });
    expect($log)->toBe(['b returned', 'b unmounted', 'a returned', 'a unmounted', 'app unmounted']);
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
        sleep(0.05);
        expect($log)->toBe([]);
        sleep(0.1);
        expect($log)->toBe(['deaf unmounted'])->and(microtime(true) - $start)->toBeLessThan(0.3);
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
