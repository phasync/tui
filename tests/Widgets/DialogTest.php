<?php

/*
 * Dialog: modal over the component that opens it, an awaitable answer, Escape cancels, the
 * focus goes back; over element trees, layouts and components that draw themselves.
 */

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Component\Confirm;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Log;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Split;

use function fiber\await;

/** A screen with a field that asks before it deletes. */
final class Asker extends Component
{
    public array $answers = [];
    public int $own = 0;

    protected function bindings(): array
    {
        return ['f2' => 'delete', 'x' => 'own'];
    }

    public function delete(): void
    {
        $this->go(function () {
            $this->answers[] = await($this->dialog(new Confirm('Delete it?', title: 'Sure')));
        });
    }

    public function ask(Dialog $dialog): void
    {
        $this->go(function () use ($dialog) {
            $this->answers[] = await($this->dialog($dialog));
        });
    }

    public function own(): void
    {
        $this->own++;
    }

    protected function render(): Element
    {
        return new Column([new Text(str_repeat('background ', 20), height: 4), $this->child('field', fn() => new Editor(maxLines: 1))]);
    }

    public function field(): Editor
    {
        return $this->child('field');
    }
}

it('asks modally and gives the answer to the coroutine that awaits it', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $app->field()->focus();
        $t->type('ab');
        $t->press('f2');
        frame();
        expect($t->text())->toContain('│ Sure            │')->and($t->text())->toContain('│ [ Yes ]  [ No ] │');
        $t->type('x');                          // the dialog's now: not the field's, not the app's
        expect($app->field()->value())->toBe('ab')->and($app->own)->toBe(0);
        $t->press('right', 'enter');
        frame();
        expect($app->answers)->toBe([false])->and($t->text())->not->toContain('Sure');
        $t->type('c');                          // the focus is back on the field
        expect($app->field()->value())->toBe('abc');
    });
});

it('cancels with Escape, and Yes gives true', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $t->press('f2');
        frame();
        $t->press('escape');
        frame();
        $t->press('f2');
        frame();
        $t->press('enter');
        frame();
        expect($app->answers)->toBe([false, true]);
        $app->ask(new Dialog(content: 'Pick', buttons: ['A' => 'a', 'B' => 'b']));
        frame();
        $t->press('escape');
        frame();
        expect($app->answers)->toBe([false, true, null]);
    });
});

it('takes clicks on its buttons and ignores the mouse outside it', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $app->ask(new Dialog(content: 'Pick one', buttons: ['A' => 'a', 'B' => 'b']));
        frame();
        $lines = explode("\n", $t->text());
        $row = array_key_first(array_filter($lines, fn($l) => str_contains($l, '[ B ]')));
        $col = mb_strpos($lines[$row], '[ B ]');
        $t->click(0, 8);                        // outside: nothing
        frame();
        expect($app->answers)->toBe([]);
        $t->click($col + 1, $row);
        frame();
        expect($app->answers)->toBe(['b']);
    });
});

it('opens over a component that returns a layout and over one that draws itself', function () {
    $layouted = new class extends Component {
        public array $answers = [];

        protected function render(): ?Layout
        {
            return Split::vertical()->add($this->child('log', fn() => new Log())->surface);
        }

        public function ask(): void
        {
            $this->go(fn() => $this->answers[] = await($this->dialog(new Confirm('Go?'))));
        }
    };
    mountOn($layouted, 24, 7, function (FakeTerminal $t, Component $app) {
        $app->ask();
        frame();
        expect($t->text())->toContain('[ Yes ]  [ No ]');
        $t->press('enter');
        frame();
        expect($app->answers)->toBe([true])->and($t->text())->not->toContain('Yes');
    });
    $drawn = new class extends Component {
        public array $answers = [];

        protected function render(): null
        {
            $this->surface->write(Ansi::clearHome() . str_repeat('#', $this->surface->cols() * $this->surface->rows()));
            return null;
        }

        public function ask(): void
        {
            $this->go(fn() => $this->answers[] = await($this->dialog(new Confirm('Go?'))));
        }
    };
    mountOn($drawn, 24, 7, function (FakeTerminal $t, Component $app) {
        $app->ask();
        frame();
        expect($t->text())->toContain('[ Yes ]  [ No ]')->and($t->text())->toStartWith('####');
        $t->press('right', 'enter');
        frame();
        expect($app->answers)->toBe([false])->and($t->text())->not->toContain('Yes');
    });
});

/** A form dialog that stays open until its name field is filled. */
final class NameAsker extends Dialog
{
    public int $refused = 0;

    public function __construct()
    {
        parent::__construct('Who?', new Column([
            new Text('Your name:'),
            (new Editor(maxLines: 1))->with(id: 'name'),
            (new Editor(maxLines: 1))->with(id: 'city'),
        ]), ['Save' => 'save', 'Cancel' => null]);
    }

    public function field(string $id): Editor
    {
        foreach ($this->children()['scroll']->children() as $child) {
            if ($child->attribute('id') === $id) {
                return $child;
            }
        }
        throw new LogicException("no field $id");
    }

    protected function accept(mixed $answer): bool
    {
        if ($this->field('name')->value() === '') {
            $this->refused++;
            return false;
        }
        return true;
    }
}

it('keeps a dialog open while accept() refuses, and moves with Tab through its fields, then its buttons', function () {
    mountOn(new Asker(), 40, 14, function (FakeTerminal $t, Asker $app) {
        $dialog = new NameAsker();
        $app->ask($dialog);
        frame();
        $t->type('x');                                  // the first field has the focus
        expect($dialog->field('name')->value())->toBe('x');
        $t->press('backspace', 'tab');
        $t->type('Oslo');
        expect($dialog->field('city')->value())->toBe('Oslo');
        $t->press('tab');                               // the first button
        $t->press('enter');                             // Save: refused, the name is empty
        frame();
        expect($dialog->refused)->toBe(1)->and($dialog->isMounted())->toBeTrue()->and($app->answers)->toBe([]);
        $t->press('tab', 'tab');                        // Cancel, then around to the name
        $t->type('Ada');
        $t->press('enter');                             // Enter in a field: the first button
        frame();
        expect($app->answers)->toBe(['save'])->and($dialog->isMounted())->toBeFalse();
    });
});

it('sizes a dialog with element content to the content', function () {
    mountOn(new Asker(), 60, 20, function (FakeTerminal $t, Asker $app) {
        $app->ask(new Dialog('Hi', new Text('a short line'), ['OK' => true]));
        frame();
        $rows = explode("\n", $t->text());
        $top = array_values(array_filter($rows, fn($r) => str_contains($r, '╭')))[0];
        expect(mb_strwidth(trim($top)))->toBe(16)->and($t->text())->toContain('│ a short line │');
    });
});

it('shrinks a dialog larger than the terminal and scrolls its content; the buttons stay', function () {
    mountOn(new Asker(), 30, 9, function (FakeTerminal $t, Asker $app) {
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = "line $i";
        }
        $app->ask(new Dialog('Long', implode("\n", $lines), ['OK' => true]));
        frame();
        $screen = $t->text();
        expect($screen)->toContain('line 1')->and($screen)->not->toContain('line 20')->and($screen)->toContain('[ OK ]');
        $t->press(...array_fill(0, 10, 'pagedown'));
        frame();
        expect($t->text())->toContain('line 20')->and($t->text())->toContain('[ OK ]')->and($t->text())->not->toContain('line 1 ');
        $t->resize(30, 30);                              // room again: all of it
        frame();
        expect($t->text())->toContain('line 1')->and($t->text())->toContain('line 20');
        $t->resize(12, 5);                               // a tiny terminal: still inside, still answerable
        frame();
        expect(explode("\n", $t->text())[0])->toStartWith('╭')->and($t->text())->toContain('OK');
        $t->press('enter');
        frame();
        expect($app->answers)->toBe([true]);
    });
});

it('runs the Rename dialog of docs/widgets.md: refused while empty, with its message', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../docs/widgets.md');
    preg_match('/```php\n(final class Rename extends Dialog.*?)\n\/\/ in a coroutine/s', $doc, $m);
    eval('use phasync\Tui\Component\Dialog; use phasync\Tui\Component\Editor; use phasync\Tui\Element\Column; use phasync\Tui\Element\Text; ' . $m[1]);
    mountOn(new Asker(), 40, 12, function (FakeTerminal $t, Asker $app) {
        $rename = new Rename('');
        $app->ask($rename);
        $t->settle();
        $t->press('enter');
        $t->settle();
        expect($t->text())->toContain('A name, please.')->and($rename->isMounted())->toBeTrue();
        $t->type('notes.txt');
        $t->press('enter');
        $t->settle();
        expect($app->answers)->toBe(['ok'])->and($rename->name())->toBe('notes.txt');
    });
});

it('opens a dialog of a key once: pressing the key that opens it again focuses the open one', function () {
    $app = new class extends Component {
        public array $answers = [];
        public int $opened = 0;

        protected function bindings(): array
        {
            return ['ctrl+q' => ['quit', 'quit', 'always' => true], 'f3' => 'other'];
        }

        public function quit(): void
        {
            $this->go(function () {
                $this->opened++;
                $this->answers[] = await($this->dialog(new Confirm('Quit?'), key: 'quit'));
            });
        }

        public function other(): void
        {
            $this->go(fn() => $this->answers[] = await($this->dialog(new Dialog(content: 'Other', buttons: ['Close' => 'closed']))));
        }

        protected function render(): Element
        {
            return new Text('app');
        }
    };
    mountOn($app, 40, 12, function (FakeTerminal $t, Component $app) {
        $t->press('ctrl+q');
        $t->settle();
        $t->press('right');                    // the focus on No
        $t->press('ctrl+q');                   // again: the same dialog, focused, not a second one
        $t->settle();
        expect(substr_count($t->text(), 'Quit?'))->toBe(1)->and($app->opened)->toBe(2);
        $t->press('enter');                    // No (the focus stayed where it was in the dialog)
        $t->settle();
        expect($app->answers)->toBe([false, false])->and($t->text())->not->toContain('Quit?');
        $t->press('ctrl+q');                   // closed: a new one
        $t->settle();
        expect($t->text())->toContain('Quit?');
        $t->press('enter');
        $t->settle();
        expect($app->answers)->toBe([false, false, true]);
    });
});

it('runs the form in a dialog of docs/widgets.md: refused while the name is empty, then added', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../docs/widgets.md');
    preg_match('/```php\n(public function add\(\): void.*?)\n```/s', $doc, $m);
    $class = 'final class Contacts extends phasync\Tui\Component { protected function mount(): void { $this->state->contacts = []; } '
        . $m[1] . ' protected function render(): phasync\Tui\Element { return new Text(count($this->state->contacts) . " contacts"); } }';
    eval('use phasync\Tui\Component\Dialog; use phasync\Tui\Component\Editor; use phasync\Tui\Component\Form; use phasync\Tui\Element\Column; use phasync\Tui\Element\Text; ' . $class);
    mountOn(new Contacts(), 50, 16, function (FakeTerminal $t, Component $app) {
        $app->add();
        $t->settle();
        $t->press('enter');
        $t->settle();
        expect($t->text())->toContain('A name, please.')->toContain('0 contacts');
        $t->type("Ada\t");
        $t->type("ada@example.com\n");
        $t->settle();
        expect($t->text())->toContain('1 contacts')->and($t->text())->not->toContain('Add a contact');
    });
});
