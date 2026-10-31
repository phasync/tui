<?php

/*
 * Button, Checkbox, ProgressBar, Spinner and Form, through FakeTerminal: keys, mouse, styled
 * states.
 */

use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Form;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Spinner;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;

/** A screen of whatever $build returns. */
final class Screen extends Component
{
    public function __construct(private Closure $build)
    {
    }

    protected function render(): Element
    {
        return ($this->build)($this);
    }

    public function make(string $key, Closure $create): Component
    {
        return $this->child($key, $create);
    }
}

it('presses a Button with Enter, Space and a click released on it, not when disabled; shows it focused and pressed', function () {
    $pressed = new ArrayObject();
    $app = new Screen(fn(Screen $s) => new Row([
        $s->make('a', fn() => new Button('Save', onPress: fn() => $pressed[] = 'save')),
        $s->make('b', fn() => new Button('Quit', onPress: fn() => $pressed[] = 'quit', disabled: true)),
    ], gap: 1));
    mountOn($app, 30, 2, function (FakeTerminal $t, Screen $app) use ($pressed) {
        $t->settle();
        expect(explode("\n", $t->text())[0])->toBe('[ Save ] [ Quit ]');
        $save = $app->children()['a'];
        $save->focus();
        $t->settle();
        expect($t->surface->cell(0, 0)->style->attrs & phasync\Tui\Style::INVERSE)->not->toBe(0);
        $t->press('enter', 'space');
        $t->mouse(new Mouse(Mouse::PRESS, 2, 0, Mouse::LEFT));
        expect($save->hasStyleState('pressed'))->toBeTrue();
        $t->mouse(new Mouse(Mouse::DRAG, 20, 0, Mouse::LEFT));     // off it: not pressed
        expect($save->hasStyleState('pressed'))->toBeFalse();
        $t->mouse(new Mouse(Mouse::RELEASE, 20, 0, Mouse::LEFT));
        $t->click(3, 0);
        $t->click(12, 0);                                           // disabled
        $app->children()['b']->focus();
        $t->press('enter');
        expect($pressed->getArrayCopy())->toBe(['save', 'save', 'save']);
    });
});

it('flips a Checkbox with Space and a click, in a box or a switch look', function () {
    $changes = new ArrayObject();
    $app = new Screen(fn(Screen $s) => new Column([
        $s->make('a', fn() => new Checkbox('Remember me', onChange: fn(bool $on) => $changes[] = $on)),
        $s->make('b', fn() => new Checkbox('Dark mode', checked: true, toggle: true)),
    ]));
    mountOn($app, 30, 3, function (FakeTerminal $t, Screen $app) use ($changes) {
        $t->settle();
        expect($t->text())->toBe("[ ] Remember me\n━● Dark mode\n");
        $app->children()['a']->focus();
        $t->press('space');
        $t->click(1, 1);
        $t->settle();
        expect($t->text())->toBe("[x] Remember me\n●━ Dark mode\n")->and($changes->getArrayCopy())->toBe([true])
            ->and($app->children()['b']->checked())->toBeFalse()->and($app->children()['a']->hasStyleState('checked'))->toBeTrue();
    });
});

it('fills a ProgressBar to its value with a label, and moves an indeterminate one by itself', function () {
    $bar = new ProgressBar(0.5);
    $busy = new ProgressBar(null, label: 'wait');
    $app = new Screen(fn() => new Column([$bar, $busy]));
    mountOn($app, 22, 2, function (FakeTerminal $t) use ($bar, $busy) {
        $t->settle();
        expect(explode("\n", $t->text())[0])->toBe('█████████░░░░░░░░░ 50%');
        $bar->setValue(1.0, 'done');
        $t->settle();
        $before = explode("\n", $t->text())[1];
        \fiber\sleep(0.25);
        $t->settle();
        expect(explode("\n", $t->text())[0])->toBe('█████████████████ done')
            ->and(explode("\n", $t->text())[1])->not->toBe($before)->and(explode("\n", $t->text())[1])->toEndWith(' wait');
    });
});

it('spins a Spinner by itself while it is shown', function () {
    $spinner = new Spinner('loading', frames: ['a', 'b', 'c'], interval: 0.02);
    mountOn(new Screen(fn() => new Column([$spinner])), 20, 1, function (FakeTerminal $t) use ($spinner) {
        $t->settle();
        $seen = [];
        for ($i = 0; $i < 12; $i++) {
            \fiber\sleep(0.01);
            $t->settle();
            $seen[substr($t->text(), 0, 1)] = true;
        }
        expect(array_keys($seen))->toContain('a', 'b', 'c')->and($t->text())->toContain(' loading');
    });
});

it('fills in a Form: Tab order, validation messages and :invalid, submit by Enter and by a Button', function () {
    $submitted = new ArrayObject();
    $app = new Screen(fn(Screen $s) => $s->make('form', fn() => new Form(
        new Column([
            new Text('Name'),
            (new Editor(maxLines: 1))->with(id: 'name'),
            (new Checkbox('Subscribe'))->with(id: 'subscribe'),
            (new Select(['s' => 'Small', 'l' => 'Large']))->with(id: 'size', height: 2),
            new Row([new Button('Save'), new Button('Clear', onPress: fn() => null)], gap: 2, height: 1),
        ]),
        validate: fn(array $v) => $v['name'] === '' ? ['name' => 'Give a name'] : [],
        onSubmit: fn(array $v) => $submitted[] = $v,
    )));
    mountOn($app, 30, 8, function (FakeTerminal $t, Screen $app) use ($submitted) {
        $t->settle();
        $form = $app->children()['form'];
        expect(array_keys($form->fields()))->toBe(['name', 'subscribe', 'size']);
        $t->press('enter');                     // the name is empty
        $t->settle();
        expect($submitted->getArrayCopy())->toBe([])->and($t->text())->toContain('Give a name')
            ->and($form->fields()['name']->hasStyleState('invalid'))->toBeTrue();
        $t->type('Ada');
        $t->press('tab', 'space', 'tab', 'down', 'tab');      // subscribe, Large, the Save button
        $t->press('enter');
        $t->settle();
        expect($submitted->getArrayCopy())->toBe([['name' => 'Ada', 'subscribe' => true, 'size' => 'l']])
            ->and($t->text())->not->toContain('Give a name')->and($form->fields()['name']->hasStyleState('invalid'))->toBeFalse();
        $t->press('shift+tab', 'shift+tab', 'shift+tab');     // back to the name
        $t->type('!');
        $t->press('enter');
        expect($submitted[1]['name'])->toBe('Ada!');
    });
});
