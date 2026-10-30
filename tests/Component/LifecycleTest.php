<?php

/*
 * How a program ends: quitting, a failing root, a failing child; programs and components
 * announce their end; a child's first render is for its real size.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\Terminal;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Stack;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** A component with one program and a hook to fail in render(). */
final class WithProgram extends Component
{
    public ?Terminal $program = null;
    public bool $failNext = false;
    public bool $failNow = false;

    protected function render(): ?Layout
    {
        $this->program = $this->child('program', fn() => new Terminal('exec sleep 30'));
        if ($this->failNow || ($this->failNext && !($this->failNow = false))) {
            throw new RuntimeException('typo in render');
        }
        return Split::horizontal()->add($this->program->surface);
    }

    public function failLater(): void
    {
        $this->failNow = true;
        $this->requestRender();
    }
}

/** Records the size of every render. */
final class SizeProbe extends Component
{
    public array $sizes = [];

    protected function render(): ?Layout
    {
        $this->sizes[] = [$this->surface->cols(), $this->surface->rows()];
        return null;
    }
}

it('starts a root on a terminal device: the first render is for the terminal size', function () {
    run(function () {
        $probe = (new SizeProbe())->start($t = new FakeTerminal(33, 7));
        expect($probe->sizes)->toBe([[33, 7]])->and($t->root())->toBe($probe->surface);
        $t->detach();
    });
});

it('renders a child for the first time at the size its layout gives it', function () {
    run(function () {
        $app = new class extends Component {
            public ?SizeProbe $probe = null;

            protected function render(): ?Layout
            {
                $this->probe = $this->child('probe', fn() => new SizeProbe());
                return Split::horizontal()->add(new Surface(), 10)->add($this->probe->surface);
            }
        };
        $app->start(new FakeTerminal(30, 5));
        expect($app->probe->sizes)->toBe([[20, 5]]);
        $app->dispose();
    });
});

it('disposes the tree and hangs up its programs when the terminal is detached', function () {
    $start = microtime(true);
    run(function () use (&$app) {
        $app = (new WithProgram())->start($t = new FakeTerminal(20, 3));
        expect($app->program->isRunning())->toBeTrue();
        $t->detach();                               // what a quit key does
        expect($app->isMounted())->toBeFalse()
            ->and($app->program->isMounted())->toBeFalse()
            ->and($app->program->isRunning())->toBeFalse();
    });                                             // and run() returns: nothing is left running
    expect(microtime(true) - $start)->toBeLessThan(3.0);
});

it('detaches the terminal when the root component is disposed', function () {
    run(function () {
        $app = (new WithProgram())->start($t = new FakeTerminal(20, 3));
        $app->dispose();
        expect($t->isAttached())->toBeFalse()->and($t->surface->isAltScreen())->toBeFalse()
            ->and($app->program->isRunning())->toBeFalse();
    });
});

it('ends everything when the root fails in start(), and the exception reaches the caller', function () {
    $app = new WithProgram();
    $app->failNext = true;
    $t = new FakeTerminal(20, 3);
    $start = microtime(true);
    expect(fn() => run(fn() => $app->start($t)))->toThrow(RuntimeException::class, 'typo in render');
    expect(microtime(true) - $start)->toBeLessThan(3.0)
        ->and($app->isMounted())->toBeFalse()
        ->and($app->program->isRunning())->toBeFalse()
        ->and($app->error()?->getMessage())->toBe('typo in render')
        ->and($t->isAttached())->toBeFalse();
});

it('ends everything when the root fails in a later render, and fiber\run() rethrows it', function () {
    $app = new WithProgram();
    $t = new FakeTerminal(20, 3);
    $start = microtime(true);
    expect(fn() => run(function () use ($app, $t) {
        $app->start($t);
        $app->failLater();
        $t->wait();                                 // returns: the failure detached the terminal
    }))->toThrow(RuntimeException::class, 'typo in render');
    expect(microtime(true) - $start)->toBeLessThan(3.0)
        ->and($app->program->isRunning())->toBeFalse()
        ->and($t->isAttached())->toBeFalse();
});

it('passes a failure in a root key handler to the code that delivered the key, after ending the tree', function () {
    $app = new class extends Component {
        public ?Terminal $program = null;

        protected function render(): ?Layout
        {
            $this->program = $this->child('p', fn() => new Terminal('exec sleep 30'));
            return Split::horizontal()->add($this->program->surface);
        }

        protected function onKey(phasync\Tui\Key $key): bool
        {
            throw new LogicException('bad key handler');
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(20, 3));
        expect(fn() => $t->press('x'))->toThrow(LogicException::class, 'bad key handler');
        expect($app->program->isRunning())->toBeFalse()->and($t->isAttached())->toBeFalse();
    });
});

it('ends the children of a failing child with it, and keeps the rest running', function () {
    $app = new class extends Component {
        public ?Component $bad = null;
        public ?Terminal $sibling = null;

        protected function render(): ?Layout
        {
            $this->bad = $this->child('bad', fn() => new WithProgram());
            $this->sibling = $this->child('ok', fn() => new Terminal('exec sleep 30'));
            return Split::horizontal()->add($this->bad->surface)->add($this->sibling->surface);
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(40, 3));
        $inner = $app->bad->program;
        $app->bad->failLater();
        sleep(0.05);
        expect($t->text())->toContain('typo in render')
            ->and($app->bad->isMounted())->toBeFalse()
            ->and($inner->isRunning())->toBeFalse()
            ->and($app->sibling->isRunning())->toBeTrue()
            ->and($app->isMounted())->toBeTrue();
        $t->detach();
    });
});

it('announces the end of a Terminal\'s program as an event and an awaitable, and restarts it', function () {
    run(function () {
        $seen = [];
        $term = (new Terminal('echo run; exit 4', onExit: function (int $code, Terminal $t) use (&$seen) {
            $seen[] = $code;
        }))->start(new Surface(20, 3));
        expect(fiber\await($term->exited(), 5))->toBe(4);
        sleep(0.01);
        expect($seen)->toBe([4])->and($term->surface->line(0))->toBe('run');
        $first = $term->exited();
        $term->restart();
        expect($term->exited())->not->toBe($first)
            ->and(fiber\await($term->exited(), 5))->toBe(4);
        sleep(0.01);
        expect($seen)->toBe([4, 4]);
        // restarting a running program hangs it up without announcing it
        $long = (new Terminal('exec sleep 30', onExit: function () use (&$seen) { $seen[] = 'long'; }))->start(new Surface(20, 3));
        $long->restart();
        sleep(0.05);
        expect($seen)->toBe([4, 4])->and($long->isRunning())->toBeTrue();
        $long->dispose();
        $term->dispose();
    });
});

it('focuses a component through a plain surface between it and the root', function () {
    $app = new class extends Component {
        public array $c = [];
        private ?Surface $group = null;

        protected function render(): ?Layout
        {
            foreach (['a', 'b', 'c'] as $k) {
                $this->c[$k] = $this->child($k, fn() => new SizeProbe());
            }
            $this->group ??= new Surface();
            $this->group->setLayout(Split::vertical()->add($this->c['b']->surface)->add($this->c['c']->surface));
            return Split::horizontal()->add($this->c['a']->surface)->add($this->group);
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(20, 4));
        $app->c['c']->focus();
        expect($app->c['c']->hasFocus())->toBeTrue()->and($app->c['a']->hasFocus())->toBeFalse();
        $t->press('z');
        expect($app->c['c']->surface->input->peek())->toBe('z');
        $t->detach();
    });
});

it('focuses a component in a layout nested directly in another, across renders', function () {
    $app = new class extends Component {
        public array $c = [];

        protected function render(): ?Layout
        {
            foreach (['a', 'b', 'c'] as $k) {
                $this->c[$k] = $this->child($k, fn() => new SizeProbe());
            }
            $this->c['c']->focus();
            return Split::horizontal()->add($this->c['a']->surface)
                ->add(Split::vertical()->add($this->c['b']->surface)->add($this->c['c']->surface));
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(20, 4));
        expect($app->c['c']->hasFocus())->toBeTrue()->and($app->c['c']->sizes)->toBe([[10, 2]]);
        $app->c['b']->focus();
        $app->requestRender();      // a new layout object; the focus asked for in render() wins again
        sleep(0.01);
        expect($app->c['c']->hasFocus())->toBeTrue();
        $t->surface->dispatch(new phasync\Tui\Key('q'));
        expect($app->c['c']->surface->input->peek())->toBe('q');
        $t->detach();
    });
});
