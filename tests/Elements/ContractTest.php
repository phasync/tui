<?php

/*
 * What widget authors need from Component (0.0.2-beta4): following the pointer on request,
 * children kept alive while hidden and stopped without waiting, keys a container takes before
 * or after its content, parts styled in the row's state, focusNext()/focusPrevious(), and a
 * Column of row components sized by their content.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;

/** A component that draws itself and records the mouse events it got. */
class PointerPane extends Component
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private bool $wants = false)
    {
    }

    protected function wantsPointer(): bool
    {
        return $this->wants;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        $this->seen[] = "$mouse->action $mouse->x,$mouse->y";
        return true;
    }

    protected function render(): null
    {
        $this->style('item');       // (a widget asking for its part's style, as it draws)
        $this->surface->write("\e[2J\e[Hpane");
        return null;
    }
}

/** A generic container for these tests: the closure renders it. */
final class Holder extends Component
{
    public function __construct(public \Closure $draw, private array $keys = [])
    {
    }

    public function get(string $key, ?\Closure $create = null): ?Component
    {
        return $this->child($key, $create);
    }

    public function hold(string $key, \Closure $create): Component
    {
        return $this->keep($key, $create);
    }

    protected function bindings(): array
    {
        return $this->keys;
    }

    /** @var list<string> */
    public array $log = [];

    public function first(): void
    {
        $this->log[] = 'first';
    }

    public function last(): void
    {
        $this->log[] = 'last';
    }

    protected function render(): Element
    {
        return ($this->draw)($this);
    }
}

/** A focused field that takes some keys of its own. */
final class KeyField extends Component
{
    /** @var list<string> */
    public array $log = [];

    protected function bindings(): array
    {
        return ['escape' => ['clear', 'clear'], 'x' => 'mine'];
    }

    public function clear(): bool
    {
        $this->log[] = 'clear';
        return \count($this->log) === 1;        // the first Escape is taken, later ones not
    }

    public function mine(): void
    {
        $this->log[] = 'x';
    }

    protected function render(): Element
    {
        return new Text('field');
    }
}

/** One row: a name and a value. */
final class JobRow extends Component
{
    public function __construct(public string $name = '', public string $value = '')
    {
    }

    protected function render(): Element
    {
        return new Row([new Text($this->name, width: 8), new Text($this->value)]);
    }
}

it('follows the pointer when a component asks with wantsPointer()', function () {
    $pane = new PointerPane(wants: true);
    $app = new Holder(fn() => new Column([$pane, new Text('below')]));
    mountOn($app, 20, 4, function (FakeTerminal $t) use ($pane) {
        $t->settle();
        expect($t->output())->toContain("\e[?1003h");      // the device asks for motion reports
        $t->feed("\e[<35;3;1M");                            // motion, no button, at 2,0
        expect($pane->seen)->toContain('move 2,0');
    });
});

it('does not follow the pointer by default', function () {
    $pane = new PointerPane();
    mountOn(new Holder(fn() => new Column([$pane])), 20, 3, function (FakeTerminal $t) use ($pane) {
        $t->settle();
        expect($t->output())->not->toContain("\e[?1003h")->and($pane->surface->mode(1003))->toBeFalse();
    });
});

it('follows the pointer for a :hover rule on one of its parts', function () {
    $pane = new PointerPane();
    $app = new Holder(fn() => new Column([$pane]));
    $app->setStylesheet(Stylesheet::default()->with(['PointerPane::item:hover' => ['color' => 'red']]));
    mountOn($app, 20, 3, function (FakeTerminal $t) use ($pane) {
        $t->settle();
        expect($pane->surface->mode(1003))->toBeTrue()->and($t->output())->toContain("\e[?1003h");
        $t->feed("\e[<35;5;1M");
        expect($pane->seen)->toContain('move 4,0');
    });
});

it('keeps a keep() child alive through renders that do not show it, and stop() ends it at once', function () {
    $app = new Holder(fn(Holder $h) => new Column([new Text('only text')]));
    mountOn($app, 20, 3, function (FakeTerminal $t, Holder $app) {
        $kept = $app->hold('pane', fn() => new KeyField());
        $plain = $app->get('plain', fn() => new KeyField());
        $app->requestRender();
        frame();
        expect($kept->isMounted())->toBeTrue()->and($plain->isMounted())->toBeFalse()
            ->and($app->get('pane'))->toBe($kept);
        $app->draw = fn(Holder $h) => new Column([$h->get('pane')]);   // shown now
        $app->requestRender();
        frame();
        expect($t->text())->toContain('field');
        $app->draw = fn() => new Text('hidden again');
        $app->requestRender();
        frame();
        expect($kept->isMounted())->toBeTrue();
        $kept->stop();          // returns at once: the child is gone from its parent
        expect($kept->isMounted())->toBeFalse()->and($app->get('pane'))->toBeNull();
        $app->requestRender();
        frame();
        expect($app->get('pane'))->toBeNull();
    });
});

it('runs a container\'s `first` bindings before the focused field and its plain ones after it', function () {
    $field = new KeyField();
    $app = new Holder(fn() => new Column([$field, new Help()]), [
        'ctrl+tab' => ['first', 'next tab', 'first' => true],
        'x' => ['first', 'first x', 'first' => true],
        'escape' => ['last', 'back'],
    ]);
    mountOn($app, 60, 3, function (FakeTerminal $t, Holder $app) use ($field) {
        $field->focus();
        frame();
        $t->press('x');                     // the container's `first` x, the field never sees it
        expect($app->log)->toBe(['first'])->and($field->log)->toBe([]);
        $t->press('escape');                // the field's Escape first (it takes it)
        expect($field->log)->toBe(['clear'])->and($app->log)->toBe(['first']);
        $t->press('escape');                // the field declines: the container's
        expect($app->log)->toBe(['first', 'last']);
        $t->press('ctrl+tab');
        expect($app->log)->toBe(['first', 'last', 'first']);
    });
});

it('lists both kinds of a container\'s keys in Help', function () {
    $field = new KeyField();
    $app = new Holder(fn() => new Column([$field, new Help()]), [
        'ctrl+tab' => ['first', 'next', 'first' => true],
        'f2' => ['last', 'menu'],
    ]);
    mountOn($app, 80, 3, function (FakeTerminal $t) use ($field) {
        $field->focus();
        $t->settle();
        expect($t->text())->toContain('ctrl+tab next')->toContain('f2 menu')->toContain('escape clear');
    });
});

it('styles the parts of a row in the row\'s state, the component\'s base under them', function () {
    $pane = new PointerPane();
    $app = new Holder(fn() => new Column([$pane]));
    $app->setStylesheet(new Stylesheet([
        'PointerPane' => ['color' => 'blue'],
        'PointerPane::node:current' => ['inverse' => true],
        'PointerPane::marker' => ['bold' => true],
        'PointerPane::hint' => ['color' => 'red'],
    ]));
    mountOn($app, 20, 3, function () use ($pane) {
        $marker = $pane->style(['node', 'marker'], 'current')->text;
        $hint = $pane->style(['node', 'hint'], 'current')->text;
        $plain = $pane->style(['node', 'marker'])->text;
        expect($marker->has(Style::INVERSE | Style::BOLD))->toBeTrue()->and($marker->fg)->toBe(4)   // the bar, bold
            ->and($hint->has(Style::INVERSE))->toBeTrue()->and($hint->fg)->toBe(1)                  // its own colour, on the bar
            ->and($plain->has(Style::INVERSE))->toBeFalse()->and($plain->fg)->toBe(4);
    });
});

it('moves the focus between its fields with focusNext() and focusPrevious()', function () {
    $app = new Holder(fn(Holder $h) => new Column([
        $h->get('a', fn() => new Editor('a', maxLines: 1)),
        $h->get('b', fn() => new Editor('b', maxLines: 1)),
        $h->get('c', fn() => new Editor('c', maxLines: 1)),
    ]));
    mountOn($app, 20, 4, function (FakeTerminal $t, Holder $app) {
        frame();
        $app->get('a')->focus();
        expect($app->focusNext())->toBeTrue()->and($app->get('b')->hasFocus())->toBeTrue()
            ->and($app->focusNext())->toBeTrue()->and($app->get('c')->hasFocus())->toBeTrue()
            ->and($app->focusNext(wrap: false))->toBeFalse()->and($app->get('c')->hasFocus())->toBeTrue()
            ->and($app->focusNext())->toBeTrue()->and($app->get('a')->hasFocus())->toBeTrue()
            ->and($app->focusPrevious())->toBeTrue()->and($app->get('c')->hasFocus())->toBeTrue();
    });
});

it('sizes a Column of row components by their content, with no height: given', function () {
    $app = new Holder(fn() => new Column([
        new JobRow('fetch', '10%'),
        new JobRow('build', '20%'),
        new JobRow('test', '30%'),
        new Text('total'),
    ]));
    mountOn($app, 30, 12, function (FakeTerminal $t) {
        $t->settle();
        $lines = explode("\n", $t->text());
        expect($lines[0])->toBe('fetch   10%')->and($lines[1])->toBe('build   20%')
            ->and($lines[2])->toBe('test    30%')->and($lines[3])->toBe('total');
    });
});

it('still gives a self-drawing component in a Column the room left', function () {
    $pane = new PointerPane();
    mountOn(new Holder(fn() => new Column([new Text('top'), $pane, new Text('bottom')])), 20, 6, function (FakeTerminal $t) use ($pane) {
        $t->settle();
        expect($pane->surface->rows())->toBe(4)->and(explode("\n", $t->text())[5])->toBe('bottom');
    });
});
