<?php

/**
 * Tabs: a shell that goes on running while hidden, a log that fills by itself, a help page, and
 * tabs you can add and close.
 *
 *     php examples/tabs.php
 *
 * Left/Right (or a click) change tab, Tab or Enter go into the shown pane, Escape comes back to
 * the bar; Ctrl-Tab and Alt-1..9 work from anywhere. Ctrl-N adds a tab, Delete or the × closes
 * one. Ctrl-Q quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Tty;

final class Workspace extends Component
{
    private Tabs $tabs;
    private Log $log;
    /** @var array<string, array{label: string, content: Element, closable?: bool}> */
    private array $all;
    private int $notes = 0;

    protected function bindings(): array
    {
        return [
            'ctrl+n' => ['add', 'new tab', 'first' => true],    // (also from the shell, whose keys are its own)
            'ctrl+q' => ['quit', 'quit', 'always' => true],
        ];
    }

    protected function mount(): void
    {
        $this->log = new Log(scrollbar: true);
        $this->all = [
            'shell' => ['label' => 'Shell', 'content' => new Terminal($_SERVER['SHELL'] ?? 'sh')],
            'log' => ['label' => 'Log', 'content' => $this->log],
            'help' => ['label' => 'Help', 'closable' => true, 'content' => new Markdown(
                "# Tabs\n\nThe shell keeps running while you look at another tab.\n\n- **Ctrl-N** adds a tab\n- **Delete** closes the current tab\n",
            )],
        ];
        $this->tabs = $this->child('tabs', fn() => new Tabs($this->all, hints: true, onClose: $this->close(...)));
        $this->go(function () {
            for ($i = 1; $this->sleep(0.5); $i++) {
                $this->log->append(date('H:i:s') . " tick $i\n");
            }
        });
    }

    protected function render(): Element
    {
        return new Column([$this->tabs, new Help(show: 'application')]);
    }

    public function add(): void
    {
        $n = ++$this->notes;
        $this->all["note$n"] = ['label' => "Note $n", 'closable' => true, 'content' => new Markdown("# Note $n\n\nClose me with Delete.")];
        $this->tabs->setTabs($this->all, "note$n");
    }

    private function close(string $key): void
    {
        unset($this->all[$key]);
        $this->tabs->setTabs($this->all);
    }

    public function quit(): void
    {
        $this->dispose();
    }
}

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "tabs needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Workspace())->start($tty));
