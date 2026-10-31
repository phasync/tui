<?php

/*
 * focus() on a component with keys of its own takes the keys itself, also after it focused a
 * descendant; a container passes them on. A drag goes to the component that got the press, in
 * its own coordinates, wherever the pointer goes.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Mouse;

final class Board extends Component
{
    public array $moves = [];

    protected function bindings(): array
    {
        return ['left' => 'previous', 'right' => 'next'];
    }

    public function previous(): void
    {
        $this->moves[] = 'previous';
    }

    public function next(): void
    {
        $this->moves[] = 'next';
    }

    public function filter(): Editor
    {
        return $this->child('filter', fn() => new Editor(maxLines: 1));
    }

    protected function render(): Element
    {
        return new Column([$this->filter(), new Text('cards')]);
    }
}

it('lets a component with keys of its own take the focus back from the descendant it focused', function () {
    $board = new Board();
    mountOn($board, 30, 5, function (FakeTerminal $t, Board $board) {
        $board->filter()->focus();
        $t->type('ab');
        $t->press('left');
        expect($board->filter()->value())->toBe('ab')->and($board->filter()->cursor())->toBe([0, 1])->and($board->moves)->toBe([]);
        $board->focus();
        $t->press('left');
        $t->type('c');
        expect($board->moves)->toBe(['previous'])->and($board->filter()->value())->toBe('ab')
            ->and($board->hasFocus())->toBeTrue()->and($board->filter()->hasFocus())->toBeFalse();
        $board->filter()->focus();
        $t->type('x');
        expect($board->filter()->value())->toBe('axb');
    });
});

it('passes the focus of a container without keys of its own to the field it focused', function () {
    $app = new class () extends Component {
        protected function mount(): void
        {
            $this->child('b', fn() => new Editor(maxLines: 1));
            $this->child('a', fn() => new Editor(maxLines: 1));
            $this->child('b')->focus();
        }

        protected function render(): Element
        {
            return new Column([$this->child('a'), $this->child('b')]);
        }

        public function field(string $key): Editor
        {
            return $this->child($key);
        }
    };
    mountOn($app, 30, 5, function (FakeTerminal $t, Component $app) {
        frame();
        $app->field('a')->focus();
        $app->focus();          // a container: the keys go on to the field focused last
        $t->type('z');
        expect($app->field('a')->value())->toBe('z')->and($app->field('b')->value())->toBe('');
    });
});

final class DragSource extends Component
{
    /** @var list<array{int, int, int}> */
    public array $seen = [];

    protected function onMouse(Mouse $mouse): bool
    {
        $this->seen[] = [$mouse->action, $mouse->x, $mouse->y];
        return true;
    }
}

it('gives the whole drag and the release to the component that got the press, in its coordinates, outside it too', function () {
    $app = new class () extends Component {
        public DragSource $left;
        public DragSource $right;

        protected function render(): Element
        {
            $this->left = $this->child('l', fn() => new DragSource());
            $this->right = $this->child('r', fn() => new DragSource());
            return new Row([$this->left->with(width: 10), $this->right]);
        }
    };
    mountOn($app, 30, 5, function (FakeTerminal $t, Component $app) {
        frame();
        $t->mouse(new Mouse(Mouse::PRESS, 2, 1, Mouse::LEFT));
        $t->mouse(new Mouse(Mouse::DRAG, 15, 3, Mouse::LEFT));     // over the right one
        $t->mouse(new Mouse(Mouse::DRAG, 0, 0, Mouse::LEFT));
        $t->mouse(new Mouse(Mouse::RELEASE, 25, 4, Mouse::LEFT));
        expect($app->left->seen)->toBe([[Mouse::PRESS, 2, 1], [Mouse::DRAG, 15, 3], [Mouse::DRAG, 0, 0], [Mouse::RELEASE, 25, 4]])
            ->and($app->right->seen)->toBe([]);
        $t->mouse(new Mouse(Mouse::PRESS, 12, 2, Mouse::LEFT));
        $t->mouse(new Mouse(Mouse::DRAG, 1, 2, Mouse::LEFT));       // left of it: negative
        $t->mouse(new Mouse(Mouse::RELEASE, 1, 2, Mouse::LEFT));
        expect($app->right->seen)->toBe([[Mouse::PRESS, 2, 2], [Mouse::DRAG, -9, 2], [Mouse::RELEASE, -9, 2]]);
    });
});

it('selects in an Editor with a drag that goes outside it, and reports a program the edge', function () {
    $app = new class () extends Component {
        public Editor $editor;

        protected function render(): Element
        {
            $this->editor = $this->child('e', fn() => new Editor("one two\nthree"));
            return new Column([new Text('above'), $this->editor->with(height: 2), new Text('below')]);
        }
    };
    mountOn($app, 20, 6, function (FakeTerminal $t, Component $app) {
        frame();
        $t->drag(4, 1, 30, 5);         // from "two" down and right, out of the editor
        expect($app->editor->selectedText())->toBe("two\nthree");
        $t->drag(2, 2, 0, 0);          // up and out
        expect($app->editor->selectedText())->toBe("one two\nth");
    });
    // a program's report of a drag outside its window is at the window's edge
    $s = new phasync\Tui\Surface(10, 3);
    $s->write("\e[?1002h\e[?1006h");
    $s->dispatch(new Mouse(Mouse::DRAG, -4, 7, Mouse::LEFT));
    expect($s->input->read())->toBe("\e[<32;1;3M");
});
