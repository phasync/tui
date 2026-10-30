<?php

/*
 * Key bindings as data: bindings() maps keys to public methods, bind() changes them per
 * instance or per class below a component, Help lists those in force.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\TextInput;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;

/** A pane with keys of its own. */
final class Pane extends Component
{
    public array $did = [];

    protected function bindings(): array
    {
        return ['ctrl+s' => ['save', 'Save'], 'x' => 'cut', 'down' => 'next'];
    }

    public function save(): void
    {
        $this->did[] = 'save';
    }

    public function cut(): void
    {
        $this->did[] = 'cut';
    }

    /** Only takes the key when there is a next item. */
    public function next(): bool
    {
        $this->did[] = 'next?';
        return false;
    }

    protected function render(): Element
    {
        return new Text('pane');
    }
}

/** The application around panes and a text field. */
final class BoundApp extends Component
{
    public array $did = [];

    protected function bindings(): array
    {
        return ['ctrl+q' => ['quit', 'Quit'], 'q' => 'quit', 'down' => 'down'];
    }

    public function quit(): void
    {
        $this->did[] = 'quit';
    }

    public function down(): void
    {
        $this->did[] = 'down';
    }

    protected function render(): Element
    {
        return new Column([
            $this->child('pane', fn() => new Pane()),
            $this->child('input', fn() => new TextInput()),
            new Help(),
        ]);
    }

    public function get(string $key): Component
    {
        return $this->child($key);
    }
}

it('runs the method bound to a key: the focused component first, then its parents', function () {
    mountOn(new BoundApp(), 40, 4, function (FakeTerminal $t, BoundApp $app) {
        $pane = $app->get('pane');
        $pane->focus();
        $t->press('ctrl+s', 'x', 'q', 'ctrl+q');
        expect($pane->did)->toBe(['save', 'cut'])->and($app->did)->toBe(['quit', 'quit']);
        // a method returning false did not take the key: it goes on to the parent
        $t->press('down');
        expect($pane->did)->toBe(['save', 'cut', 'next?'])->and($app->did)->toBe(['quit', 'quit', 'down']);
    });
});

it('lets a focused text field type the keys a parent binds, but not those it does not use', function () {
    mountOn(new BoundApp(), 40, 4, function (FakeTerminal $t, BoundApp $app) {
        $input = $app->get('input');
        $input->focus();
        $t->type('quiet');
        $t->press('ctrl+q');
        expect($input->value())->toBe('quiet')->and($app->did)->toBe(['quit']);
    });
});

it('changes the keys per instance and per class, nearest first, and unbinds with null', function () {
    mountOn(new BoundApp(), 40, 4, function (FakeTerminal $t, BoundApp $app) {
        $pane = $app->get('pane');
        $pane->focus();
        $app->bind(['ctrl+w' => 'save', 'x' => null], Pane::class);
        $t->press('ctrl+w', 'x');
        expect($pane->did)->toBe(['save']);
        $pane->bind(['x' => 'cut', 'ctrl+w' => null]);
        $t->press('ctrl+w', 'x');
        expect($pane->did)->toBe(['save', 'cut'])
            ->and(array_keys($pane->bindingsInForce()))->toBe(['ctrl+s', 'x', 'down']);
    });
});

it('lists the keys in force in a Help element, following the focus', function () {
    mountOn(new BoundApp(), 60, 4, function (FakeTerminal $t, BoundApp $app) {
        $app->get('pane')->focus();
        frame();
        expect($t->text())->toEndWith('ctrl+s Save  x cut  down next  ctrl+q Quit  q quit');
        $app->get('input')->focus();
        frame();
        expect($t->text())->toEndWith('ctrl+q Quit  q quit  down down');
        $app->bind(['f1' => ['quit', 'Leave']]);
        frame();
        expect($t->text())->toEndWith('ctrl+q Quit  q quit  down down  f1 Leave');
    });
});

it('refuses a key bound to a method that is not public', function () {
    $app = new class extends Component {
        protected function bindings(): array
        {
            return ['a' => 'secret'];
        }

        private function secret(): void
        {
        }
    };
    mountOn($app, 10, 1, function (FakeTerminal $t) {
        expect(fn() => $t->press('a'))->toThrow(LogicException::class, "bound to 'secret'");
    });
});
