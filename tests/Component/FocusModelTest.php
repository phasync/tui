<?php

/*
 * One focus model: a focus request names a component, and the descendant it focused; keys go to
 * that leaf before a render shows it and after.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\TextInput;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Stack;

use function fiber\run;
use function fiber\sleep;

/** A dialog: a title line over a TextInput it focuses in mount(). */
final class NameDialog extends Component
{
    public TextInput $input;

    protected function mount(): void
    {
        $this->input = $this->child('input', fn() => new TextInput());
        $this->input->focus();
    }

    protected function render(): ?Layout
    {
        return Split::vertical()->add(new phasync\Tui\Surface(), 1)->add($this->child('input', fn() => $this->input)->surface);
    }
}

final class AppWithNameDialog extends Component
{
    public ?NameDialog $dialog = null;
    public TextInput $main;

    protected function render(): ?Layout
    {
        $this->main = $this->child('main', fn() => new TextInput());
        $layout = (new Stack())->add($this->main->surface);
        if ($this->dialog !== null) {
            $layout->add($this->child('dialog', fn() => $this->dialog)->surface, 30, 4, border: true, title: 'Name');
        }
        return $layout;
    }

    protected function onKey(Key $key): bool
    {
        if ($key->is('ctrl+o')) {
            $this->dialog = $this->child('dialog', fn() => new NameDialog());
            $this->dialog->focus();
            $this->requestRender();
            return true;
        }
        return false;
    }
}

it('keeps a focus given before a component is shown after the render shows it, down to the leaf it focused', function () {
    run(function () {
        $app = (new AppWithNameDialog())->start($t = new FakeTerminal(60, 10));
        try {
            $t->type('m');
            $t->press('ctrl+o');
            $t->type('ab');                         // before the frame: the dialog is not shown yet
            expect($app->dialog->input->value())->toBe('ab')
                ->and($app->dialog->input->hasFocus())->toBeTrue();
            frame();
            expect($t->text())->toContain('Name');
            $t->type('cd');                         // after the frame
            expect($app->dialog->input->value())->toBe('abcd')
                ->and($app->main->value())->toBe('m')
                ->and($app->dialog->input->hasFocus())->toBeTrue()
                ->and($app->main->hasFocus())->toBeFalse();
        } finally {
            $t->detach();
        }
    });
});

/** A pane (shown) that opens a Box around a TextInput nobody focused; the box is focused. */
final class PaneWithBox extends Component
{
    public ?phasync\Tui\Component\Box $box = null;
    public TextInput $field;

    protected function render(): ?Layout
    {
        $this->field = $this->child('field', fn() => new TextInput());
        $layout = (new Stack())->add($this->field->surface);
        if ($this->box !== null) {
            $layout->add($this->child('box', fn() => $this->box)->surface, 20, 3);
        }
        return $layout;
    }

    protected function onKey(Key $key): bool
    {
        if ($key->is('ctrl+b')) {
            $this->box = $this->child('box', fn() => new phasync\Tui\Component\Box(new TextInput(), 'Find'));
            $this->box->focus();
            $this->requestRender();
            return true;
        }
        return false;
    }
}

it('passes keys to the content of a box focused before it is shown, below a pane, and after', function () {
    $app = new class extends Component {
        public PaneWithBox $left;
        public TextInput $right;

        protected function render(): ?Layout
        {
            $this->left = $this->child('left', fn() => new PaneWithBox());
            $this->right = $this->child('right', fn() => new TextInput());
            return Split::horizontal()->add($this->left->surface)->add($this->right->surface);
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(60, 8));
        try {
            $app->left->focus();
            $t->press('ctrl+b');
            $t->type('x');
            $box = $app->left->box;
            expect($box->content()->value())->toBe('x')
                ->and($box->hasFocus())->toBeTrue()
                ->and($box->content()->hasFocus())->toBeTrue()
                ->and($app->left->field->hasFocus())->toBeFalse()
                ->and($app->right->hasFocus())->toBeFalse();
            frame();
            expect($t->text())->toContain('Find');
            $t->type('y');
            expect($box->content()->value())->toBe('xy')
                ->and($box->content()->hasFocus())->toBeTrue()
                ->and($app->left->field->value())->toBe('');
            // a later focus elsewhere wins, shown or not
            $app->right->focus();
            $t->type('r');
            expect($app->right->value())->toBe('r')->and($box->content()->hasFocus())->toBeFalse();
        } finally {
            $t->detach();
        }
    });
});

it('gives the keys to the latest focus among components not shown yet, and nothing to one never focused', function () {
    $app = new class extends Component {
        public bool $show = false;

        protected function render(): ?Layout
        {
            $main = $this->child('main', fn() => new TextInput());
            $layout = (new Stack())->add($main->surface);
            if ($this->show) {
                $layout->add($this->child('a', fn() => new TextInput())->surface, 10, 1, 'top-left')
                    ->add($this->child('b', fn() => new TextInput())->surface, 10, 1, 'bottom-left');
            }
            return $layout;
        }

        public function input(string $key): TextInput
        {
            return $this->child($key, fn() => new TextInput());
        }
    };
    run(function () use ($app) {
        $app->start($t = new FakeTerminal(30, 5));
        try {
            $a = $app->input('a');
            $b = $app->input('b');
            $t->type('1');                          // a and b exist, unshown, never focused
            expect($app->input('main')->value())->toBe('1')
                ->and($a->hasFocus())->toBeFalse()->and($b->hasFocus())->toBeFalse();
            $a->focus();
            $b->focus();
            $t->type('2');
            $a->focus();
            $t->type('3');
            expect($b->value())->toBe('2')->and($a->value())->toBe('3')
                ->and($a->hasFocus())->toBeTrue()->and($b->hasFocus())->toBeFalse();
            $app->show = true;
            $app->requestRender();
            frame();
            $t->type('4');
            expect($a->value())->toBe('34')->and($a->hasFocus())->toBeTrue()->and($b->hasFocus())->toBeFalse();
        } finally {
            $t->detach();
        }
    });
});
