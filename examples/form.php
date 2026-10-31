<?php

/**
 * A sign-up form: fields, a dropdown, a pick list, a switch, buttons, validation, a confirmation
 * dialog, and a progress bar while it "saves".
 *
 *     php examples/form.php
 *
 * Tab and Shift-Tab move between the fields; the notes field takes several lines (Enter starts
 * one) and grows up to four. The role is a dropdown (Enter or Down opens it). In the languages,
 * Space marks, typing filters (Enter ends the filter, so Space marks again). Enter in a
 * one-line field, the Save button or Ctrl-S submits; without a name the form says so.
 * Ctrl-Q quits, also while the dialog is open.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Spinner;
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
            'ctrl+s' => ['submit', 'save'],
            'ctrl+q' => ['quit', 'quit', 'always' => true],
        ];
    }

    private function form(): Form
    {
        return $this->child('form', fn() => new Form(
            new Row([
                new Column([
                    new Box((new Editor(placeholder: 'your name', maxLines: 1))->with(id: 'name'), 'Name'),
                    new Box((new Editor(placeholder: 'anything else?', maxLines: 4))->with(id: 'notes'), 'Notes'),
                    (new Select(['dev' => 'Developer', 'ops' => 'Operations', 'pm' => 'Product'], dropdown: true))->with(id: 'role'),
                    (new Checkbox('Newsletter', toggle: true))->with(id: 'news'),
                    new Row([new Button('Save'), new Button('Clear', onPress: fn() => $this->clear())], gap: 2, height: 1),
                ]),
                new Box((new Select(['php' => 'PHP', 'go' => 'Go', 'rust' => 'Rust', 'py' => 'Python'], multiple: true))->with(id: 'langs'), 'Languages', width: 20),
            ]),
            validate: fn(array $v) => trim($v['name']) === '' ? ['name' => 'A name, please.'] : [],
            onSubmit: fn(array $v) => $this->save($v),
        ));
    }

    protected function render(): Element
    {
        $saving = $this->state->progress ?? null;
        return new Column([
            new Text('Sign up', class: 'title'),
            $this->form(),
            $saving === null
                ? new Text($this->state->saved ?? '', class: 'muted')
                : new Row([(new Spinner('saving'))->with(width: 10), new ProgressBar($saving)], height: 1),
            new Help(show: 'application'),
        ]);
    }

    public function submit(): void
    {
        $this->form()->submit();
    }

    private function save(array $v): void
    {
        $this->go(function () use ($v) {
            if (!await($this->dialog(new Confirm('Save this sign-up?')))) {
                return;
            }
            for ($i = 0; $i <= 10 && $this->sleep(0.05); $i++) {
                $this->state->progress = $i / 10;
            }
            $this->state->progress = null;
            $langs = implode(', ', $v['langs']);
            $this->state->saved = "Saved: {$v['name']}, {$v['role']} ($langs)" . ($v['news'] ? ', newsletter' : '');
        });
    }

    private function clear(): void
    {
        foreach ($this->form()->fields() as $field) {
            if ($field instanceof Editor) {
                $field->setValue('');
            }
        }
        $this->state->saved = '';
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
