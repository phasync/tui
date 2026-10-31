<?php

/**
 * Progress bars that do not take the terminal over: five jobs, each with a bar, and a total,
 * as a live region under the shell's output (inline mode). A job that finishes prints a line
 * above the region; when all are done the last frame stays and the prompt comes under it.
 *
 *     php examples/inline-progress.php                # inline
 *     php examples/inline-progress.php --fullscreen   # the same component, full screen
 *
 * Ctrl-C stops early.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\TerminalDevice;
use phasync\Tui\Tty;

final class Jobs extends Component
{
    private const NAMES = ['fetch', 'compile', 'test', 'package', 'upload'];

    /** @var array<string, float> how far each job is, 0 to 1 */
    private array $done = [];

    public function __construct(private readonly TerminalDevice $device)
    {
        $this->done = array_fill_keys(self::NAMES, 0.0);
    }

    protected function bindings(): array
    {
        return ['ctrl+c' => 'stop'];
    }

    public function stop(): void
    {
        $this->dispose();
    }

    protected function run(): void
    {
        foreach (self::NAMES as $i => $name) {
            $this->go(function () use ($i, $name) {
                $started = microtime(true);
                while ($this->done[$name] < 1 && $this->sleep(0.03 + 0.02 * $i)) {
                    $this->done[$name] = min(1.0, $this->done[$name] + mt_rand(1, 6) / 100);
                }
                if ($this->done[$name] >= 1) {
                    $this->device->println(sprintf("\e[32m✔\e[0m %s done in %.1fs", $name, microtime(true) - $started));
                }
            });
        }
        while ($this->sleep(0.05)) {
            if (min($this->done) >= 1) {
                $this->dispose();       // the last frame stays, at 100%
            }
        }
    }

    protected function render(): Element
    {
        $rows = [];
        foreach ($this->done as $name => $value) {
            $rows[] = new Row([new Text($name, width: 10), new ProgressBar($value)]);
        }
        $total = array_sum($this->done) / \count($this->done);
        $rows[] = new Text(sprintf('total %3d%%', round($total * 100)), class: 'title');
        return new Column($rows);
    }
}

$fullscreen = in_array('--fullscreen', $argv, true);
$tty = $fullscreen ? new Tty() : Tty::inline();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "inline-progress needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Jobs($tty))->start($tty));
