<?php

/**
 * Two shells side by side with a status bar, like a tiny tmux.
 *
 *     php examples/split-shells.php
 *
 * Keys go to the focused shell. Ctrl-B is the prefix key (so Tab still completes in the shells,
 * and so this program can run inside itself, as tmux runs inside tmux):
 *
 *     Ctrl-B Tab      switch shell (Ctrl-B Shift-Tab: the other way); clicking a shell also works
 *     Ctrl-B arrow    the shell in that direction
 *     Ctrl-B Ctrl-B   send Ctrl-B to the shell (to reach a split-shells running inside it)
 *     Ctrl-B q        quit
 *
 * The mouse wheel scrolls a shell's scrollback, a drag selects and copies text, a pasted text
 * goes to the focused shell. Everything follows the terminal's size. The program ends when you
 * quit or both shells have exited: the shells are hung up and the terminal is given back.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Box;
use phasync\Tui\Component\StatusBar;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Style;
use phasync\Tui\Tty;

final class SplitShells extends Component
{
    private bool $prefix = false;
    private int $running = 2;

    protected function mount(): void
    {
        $this->surface->setFocusOnClick(true);
    }

    protected function render(): Layout
    {
        $shell = getenv('SHELL') ?: '/bin/sh';
        $boxes = [];
        foreach (['left', 'right'] as $i => $key) {
            $boxes[] = $this->child($key, fn() => new Box(
                // both shells gone: the program ends (disposing the root gives the terminal back)
                new Terminal($shell, onExit: fn() => --$this->running === 0 && $this->dispose()),
                'shell ' . ($i + 1),
                focusStyle: Style::of(Color::CYAN, attrs: Style::BOLD),
            ));
        }
        $status = $this->child('status', fn() => new StatusBar());
        $status->set(
            $this->prefix ? ' [prefix] Tab/arrows: switch  Ctrl-B: send Ctrl-B  q: quit' : ' split-shells',
            'Ctrl-B Tab: switch  Ctrl-B q: quit ',
        );
        return Split::vertical()
            ->add(Split::horizontal()->add($boxes[0]->surface)->add($boxes[1]->surface))
            ->add($status->surface, 1);
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
            match (true) {
                $key->is('tab') => $this->surface->focusNext(),
                $key->is('shift+tab') => $this->surface->focusNext(backwards: true),
                $key->is('left'), $key->is('right'), $key->is('up'), $key->is('down') => $this->surface->focusToward($key->name),
                $key->is('ctrl+b') => $this->surface->focused()?->dispatch($key),    // on down the focus path
                $key->is('q') => $this->dispose(),      // quit: the shells are hung up
                default => null,
            };
        }
        $this->requestRender();
        return true;
    }
}

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "split-shells needs a terminal\n");
    exit(1);
}
// runs until quit: start() attaches the terminal, and run() returns once everything has ended
fiber\run(fn() => (new SplitShells())->start($tty));
