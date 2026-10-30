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
 *     Ctrl-B Ctrl-B   send Ctrl-B to the shell (to reach a split-shells running inside it)
 *     Ctrl-B q        quit
 *
 * The mouse wheel scrolls a shell's scrollback, a drag selects and copies text, a pasted text
 * goes to the focused shell. Everything follows the terminal's size.
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
use phasync\Tui\Surface;
use phasync\Tui\Tty;

final class SplitShells extends Component
{
    private Surface $panes;
    private bool $prefix = false;
    private int $running = 2;

    public function __construct(private readonly Tty $tty)
    {
    }

    protected function mount(): void
    {
        $this->panes = new Surface();
        $this->panes->setFocusOnClick(true);
    }

    protected function render(): Layout
    {
        $shell = getenv('SHELL') ?: '/bin/sh';
        $boxes = [];
        foreach (['left', 'right'] as $i => $key) {
            $boxes[] = $this->child($key, fn() => new Box(
                new Terminal($shell, onExit: fn() => --$this->running === 0 && $this->tty->detach()),
                'shell ' . ($i + 1),
                focusStyle: Style::of(Color::CYAN, attrs: Style::BOLD),
            ));
        }
        $this->panes->setLayout(Split::horizontal()->add($boxes[0]->surface)->add($boxes[1]->surface));
        $status = $this->child('status', fn() => new StatusBar());
        $status->set(
            $this->prefix ? ' [prefix] Tab: switch  Ctrl-B: send Ctrl-B  q: quit' : ' split-shells',
            'Ctrl-B Tab: switch  Ctrl-B q: quit ',
        );
        return Split::vertical()->add($this->panes)->add($status->surface, 1);
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
                $key->is('tab') => $this->panes->focusNext(),
                $key->is('shift+tab') => $this->panes->focusNext(backwards: true),
                $key->is('ctrl+b') => $this->panes->dispatch($key),
                $key->is('q') => $this->tty->detach(),
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
fiber\run(function () use ($tty) {
    $app = (new SplitShells($tty))->start();
    $tty->attach($app->surface);
    $tty->wait();
    $app->dispose();
});
