<?php

/*
 * Defects reported from use in 0.0.2-beta2, each in the exact shape it was reported in.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\Select;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;

use function fiber\await;

it('shrinks a completion popup when typing filters its candidates, and repaints what it uncovers', function () {
    $words = ['alpha', 'alpine', 'alps', 'also'];
    $editor = new Editor(maxLines: 1, complete: fn(string $w) => array_values(array_filter($words, fn($c) => str_starts_with($c, $w))));
    $app = new class ($editor) extends Component {
        public function __construct(private Editor $editor)
        {
        }

        protected function render(): Element
        {
            return new Column([new Box($this->child('e', fn() => $this->editor), 'word'), new Text("one\ntwo\nthree\nfour\nfive")]);
        }
    };
    mountOn($app, 30, 10, function (FakeTerminal $t) use ($editor) {
        $editor->focus();
        $t->type('al');
        $t->press('tab');
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[2])->toContain('alpha')->and($rows[5])->toContain('also');
        $t->type('ph');
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[2])->toContain('alpha')
            ->and($rows[2])->toStartWith('╰')                  // the Box border under the popup is back
            ->and($rows[3])->toBe('one')->and($rows[4])->toBe('two')->and($rows[5])->toBe('three');
        $t->press('backspace', 'backspace');                 // grows again
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[5])->toContain('also')->and($rows[6])->toBe('four');
    });
});

/** A screen that opens the dialog it is given, and records the answers. */
final class DialogOpener extends Component
{
    public array $answers = [];

    public function ask(Dialog $dialog): void
    {
        $this->go(function () use ($dialog) {
            $this->answers[] = await($this->dialog($dialog));
        });
    }

    protected function render(): Element
    {
        return new Text(str_repeat('background ', 40));
    }
}

it('draws the content of a dialog given a size, focuses its first field, and types into it', function () {
    mountOn(new DialogOpener(), 50, 16, function (FakeTerminal $t, DialogOpener $app) {
        $notes = new Editor();
        $dialog = (new Dialog('Notes', new Column([new Text('Write something:'), $notes]), ['OK' => 'ok', 'Cancel' => null]))->with(width: 40, height: 12);
        $app->ask($dialog);
        $t->settle();
        expect($t->text())->toContain('Write something:')->and($t->text())->toContain('[ OK ]');
        $t->type("first\nsecond");
        $t->settle();
        expect($notes->value())->toBe("first\nsecond")->and($t->text())->toContain('first')->and($t->text())->toContain('second');
        $rows = explode("\n", $t->text());
        $top = array_values(array_filter($rows, fn($r) => str_contains($r, '╭')))[0];
        preg_match('/╭.*╮/u', $top, $m);
        expect(mb_strwidth($m[0]))->toBe(40);
    });
});

/** A dialog of a Form: a name that must be given, and a city. */
function addDialog(array &$submitted): Dialog
{
    return new Dialog('Add', new Form(
        new Column([
            new Text('Name'),
            (new Editor(maxLines: 1))->with(id: 'name'),
            new Text('City'),
            (new Editor(maxLines: 1))->with(id: 'city'),
        ]),
        onSubmit: function (array $values) use (&$submitted) {
            $submitted[] = $values;
        },
        validate: fn(array $v) => $v['name'] === '' ? ['name' => 'A name, please.'] : [],
    ), ['Add' => 'add', 'Cancel' => null]);
}

it('submits a Form in a Dialog with Enter in a field: validated, then answered with the first button', function () {
    mountOn(new DialogOpener(), 50, 16, function (FakeTerminal $t, DialogOpener $app) {
        $submitted = [];
        $app->ask(addDialog($submitted));
        $t->settle();
        $t->press('enter');                       // empty name: refused, with the message
        $t->settle();
        expect($app->answers)->toBe([])->and($t->text())->toContain('A name, please.')->and($submitted)->toBe([]);
        $t->type("Ada\n");                        // the name field has the focus again
        $t->settle();
        expect($submitted)->toBe([['name' => 'Ada', 'city' => '']])->and($app->answers)->toBe(['add']);
    });
});

it('presses the buttons of a Dialog of a Form: Add submits the form, Cancel does not', function () {
    mountOn(new DialogOpener(), 50, 16, function (FakeTerminal $t, DialogOpener $app) {
        $submitted = [];
        $app->ask(addDialog($submitted));
        $t->settle();
        $t->press('tab', 'tab');                  // past the two fields: [ Add ]
        $t->press('enter');                       // invalid: stays open, the name gets the focus
        $t->settle();
        expect($app->answers)->toBe([])->and($t->text())->toContain('A name, please.');
        $t->type('Bo');
        $t->press('tab', 'tab', 'space');         // Space presses as Enter does
        $t->settle();
        expect($app->answers)->toBe(['add'])->and($submitted)->toBe([['name' => 'Bo', 'city' => '']]);
        $app->ask(addDialog($submitted));
        $t->settle();
        $t->press('tab', 'tab', 'tab', 'enter');  // Cancel: no validation, no submit
        $t->settle();
        expect($app->answers)->toBe(['add', null])->and($submitted)->toHaveCount(1);
    });
});

it('places an open dropdown list again when its items grow or shrink', function () {
    $select = new Select(['a' => 'apple', 'b' => 'banana'], dropdown: true);
    $app = new class ($select) extends Component {
        public function __construct(private Select $select)
        {
        }

        protected function render(): Element
        {
            return new Column([$this->child('s', fn() => $this->select), new Text("one\ntwo\nthree\nfour\nfive\nsix")]);
        }
    };
    mountOn($app, 30, 10, function (FakeTerminal $t) use ($select) {
        $select->focus();
        $t->press('enter');
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[1])->toContain('apple')->and($rows[2])->toContain('banana')->and($rows[3])->toBe('three');
        $select->setItems(['a' => 'apple', 'b' => 'banana', 'c' => 'cherry', 'd' => 'date']);
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[4])->toContain('date')->and($rows[5])->toBe('five')->and($select->isOpen())->toBeTrue();
        $select->setItems(['c' => 'cherry']);
        $t->settle();
        $rows = explode("\n", $t->text());
        expect($rows[1])->toContain('cherry')->and($rows[2])->toBe('two')->and($rows[4])->toBe('four');
    });
});

it('grows a dialog when its content does: a validation message appears', function () {
    mountOn(new DialogOpener(), 50, 16, function (FakeTerminal $t, DialogOpener $app) {
        $submitted = [];
        $app->ask(addDialog($submitted));
        $t->settle();
        $height = fn() => count(array_filter(explode("\n", $t->text()), fn($l) => str_contains($l, '│')));
        $before = $height();
        $t->press('enter');
        $t->settle();
        expect($height())->toBe($before + 1)->and($t->text())->toContain('A name, please.');
    });
});
