<?php
// A program on the real terminal whose own code fails, in the way given by the argument:
//   start  - the run closure throws after start() (a protected method called from outside)
//   attach - the same with a bare Surface attached to the Tty
//   run    - the root component's run() throws
//   go     - a coroutine of the root component (go()) throws
//   tui    - Tui::run() of a root whose run() throws
//   closure - Tui::run() of a closure that throws when the state it reads changes
//   tuigo  - a coroutine started with Tui::go() before Tui::run() throws
//   keys   - a key handler given with Tui::run(keys:) throws (the program presses it itself)
// It must end at once with the exception on stderr and the terminal restored.
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Surface;
use phasync\Tui\Tty;
use phasync\Tui\Tui;

final class CrashApp extends Component
{
    public function __construct(private string $mode)
    {
    }

    protected function render(): Element
    {
        return new Text('crash app ' . $this->mode);
    }

    protected function run(): void
    {
        if ($this->mode === 'go') {
            $this->go(function () {
                $this->sleep(0.05);
                throw new RuntimeException('failed in go()');
            });
        }
        while ($this->sleep(0.05)) {
            if ($this->mode === 'run' || $this->mode === 'tui') {
                throw new RuntimeException('failed in run()');
            }
        }
    }

    protected function secret(): void
    {
    }
}

$mode = $argv[1] ?? 'start';
if ($mode === 'closure') {
    fiber\run(function () {
        $s = new State(['n' => 0]);
        fiber\go(function () use ($s) {
            fiber\sleep(0.05);
            $s->n = 1;
        });
        Tui::run(fn() => $s->n > 0 ? throw new RuntimeException('failed in the closure') : new Text("n {$s->n}"));
    });
    exit(0);
}
if ($mode === 'tuigo') {
    fiber\run(function () {
        $running = true;
        Tui::go(function () use (&$running) {
            fiber\sleep(0.1);
            throw new RuntimeException('failed in Tui::go()');
        });
        try {
            Tui::run(new Text('a screen'));
        } finally {
            $running = false;
        }
    });
    exit(0);
}
if ($mode === 'keys') {
    fiber\run(function () {
        fiber\go(function () {
            fiber\sleep(0.1);
            Component::runningRoot()?->surface->dispatch(new phasync\Tui\Key('x'));
        });
        Tui::run(new Text('a screen'), keys: ['x' => fn() => throw new RuntimeException('failed in a key handler')]);
    });
    exit(0);
}
if ($mode === 'tui') {
    Tui::run(new CrashApp($mode));
    exit(0);
}
fiber\run(function () use ($mode) {
    $tty = new Tty();
    if ($mode === 'attach') {
        $root = new Surface();
        $tty->attach($root);
        $root->write('bare surface');
        fiber\sleep(0.05);
        throw new RuntimeException('failed after attach()');
    }
    $app = (new CrashApp($mode))->start($tty);
    if ($mode === 'start') {
        fiber\sleep(0.05);
        $app->secret();
    }
});
exit(0);
