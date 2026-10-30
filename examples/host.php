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
 * (when the program has not asked for the mouse itself). Ctrl-B q quits; Ctrl-B Ctrl-B sends
 * Ctrl-B to the program. The window closes when the program exits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
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
    public function __construct(private readonly Tty $tty, private readonly array $command)
    {
    }

    protected function render(): Layout
    {
        $program = $this->child('program', fn() => new Terminal($this->command, onExit: fn() => $this->tty->detach()));
        $status = $this->child('status', fn() => new StatusBar());
        $title = $program->surface->title();
        $status->set(' ' . implode(' ', $this->command) . ($title !== '' ? " — $title" : ''), $this->prefix ? '[prefix] q: quit  Ctrl-B: send Ctrl-B ' : 'Ctrl-B q: quit ');
        return Split::vertical()->add($program->surface)->add($status->surface, 1);
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
            if ($key->is('q')) {
                $this->tty->detach();
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
fiber\run(function () use ($tty, $command) {
    $app = (new Host($tty, $command))->start();
    $tty->attach($app->surface);
    $tty->wait();
    $app->dispose();
});
