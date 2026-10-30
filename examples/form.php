<?php

/**
 * A form built from elements: fields, a pick list, a confirmation dialog, the keys in a footer.
 *
 *     php examples/form.php
 *
 * Tab and Shift-Tab move between the fields (Up/Down too, from a one-line field); the notes
 * field takes several lines (Enter starts one). Space marks languages in the list, typing
 * filters it. Ctrl-S asks for confirmation and shows what was saved; Ctrl-Q quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Select;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\Tty;

use function fiber\await;

final class SignUp extends Component
{
    protected function bindings(): array
    {
        return [
            'ctrl+s' => ['save', 'save'],
            'tab' => ['next', 'next field'],
            'shift+tab' => 'previous',
            'down' => 'next',
            'up' => 'previous',
            'ctrl+q' => ['quit', 'quit'],
        ];
    }

    protected function render(): Element
    {
        $name = $this->child('name', fn() => new Editor(placeholder: 'your name', maxLines: 1));
        $notes = $this->child('notes', fn() => new Editor(placeholder: 'anything else?'));
        $langs = $this->child('langs', fn() => new Select(['php' => 'PHP', 'go' => 'Go', 'rust' => 'Rust', 'py' => 'Python'], multiple: true));
        return new Column([
            new Text('Sign up', class: 'title'),
            new Row([
                new Column([
                    new Box($name, 'Name'),
                    new Box($notes, 'Notes', height: '1fr'),
                ]),
                new Box($langs, 'Languages', width: 20),
            ]),
            new Text($this->state->saved ?? '', class: 'muted'),
            new Help(),
        ]);
    }

    public function save(): void
    {
        $this->go(function () {
            if (await($this->dialog(new Confirm('Save this sign-up?')))) {
                $name = $this->child('name')->value();
                $langs = implode(', ', $this->child('langs')->selection());
                $this->state->saved = "Saved: $name ($langs)";
            }
        });
    }

    public function next(): void
    {
        $this->surface->focusNext();
    }

    public function previous(): void
    {
        $this->surface->focusNext(backwards: true);
    }

    public function quit(): void
    {
        $this->dispose();
    }
}

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "form needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new SignUp())->start($tty));
