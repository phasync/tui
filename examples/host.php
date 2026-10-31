<?php

/**
 * Run any command in a window with a status bar: a terminal inside the terminal.
 *
 *     php examples/host.php claude
 *     php examples/host.php vim README.md
 *     php examples/host.php                  # your shell
 *
 * Everything the program does goes through the library's emulator and back out to your
 * terminal: colors, wide characters, mouse, bracketed paste, the kitty keyboard protocol,
 * synchronized output, title changes. The mouse wheel scrolls back and a drag selects and copies
 * (when the program has not asked for the mouse itself). Ctrl-B q (or Ctrl-B Ctrl-Q) quits;
 * Ctrl-B Ctrl-B sends Ctrl-B to the program; the status line shows the keys. When the program
 * exits, a goodbye with its exit status shows for three seconds (a `Dialog` with `timeout:`;
 * Enter closes it at once), then the window closes.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\StatusBar;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Tty;

final class Host extends Component
{
    private bool $prefix = false;

    /** @param list<string> $command */
    public function __construct(private readonly array $command)
    {
    }

    protected function render(): Layout
    {
        $program = $this->child('program', fn() => new Terminal($this->command, onExit: fn(int $code) => $this->goodbye($code)));
        $status = $this->child('status', fn() => new StatusBar());
        $title = $program->surface->title();
        $status->set(' ' . implode(' ', $this->command) . ($title !== '' ? " — $title" : ''), $this->prefix ? '[prefix] q, Ctrl-Q: quit  Ctrl-B: send Ctrl-B ' : 'Ctrl-B q: quit ');
        return Split::vertical()->add($program->surface)->add($status->surface, 1);
    }

    /** The program ended: say so for three seconds (or until Enter), then end. */
    private function goodbye(int $code): void
    {
        $this->go(function () use ($code) {
            fiber\await($this->dialog(new Dialog('goodbye', "{$this->command[0]} exited with status $code", ['OK' => true], timeout: 3)));
            $this->dispose();
        });
    }

    protected function mount(): void
    {
        // keep the status bar's title current
        $this->surface->on('title', fn() => $this->requestRender());
    }

    protected function onKey(Key $key): bool
    {
        if (!$this->prefix) {
            if (!$key->is('ctrl+b')) {
                return false;
            }
            $this->prefix = true;
        } else {
            $this->prefix = false;
            if ($key->is('q') || $key->is('ctrl+q')) {
                $this->dispose();       // quit: the program is hung up, the terminal given back
            } elseif ($key->is('ctrl+b')) {
                $this->surface->focused()?->dispatch($key);
            }
        }
        $this->requestRender();
        return true;
    }
}

$command = array_slice($argv, 1) ?: [getenv('SHELL') ?: '/bin/sh'];
$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "host.php needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Host($command))->start($tty));
