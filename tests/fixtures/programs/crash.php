<?php
// A program on the real terminal whose own code fails, in the way given by the argument:
//   start  - the run closure throws after start() (a protected method called from outside)
//   attach - the same with a bare Surface attached to the Tty
//   run    - the root component's run() throws
//   go     - a coroutine of the root component (go()) throws
//   tui    - Tui::run() of a root whose run() throws
// It must end at once with the exception on stderr and the terminal restored.
require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Text;
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
