<?php

/*
 * TODO-0.0.3-beta4 item 1: Tab is trapped after a hidden child() Terminal tab was shown and left,
 * in the exact shape from use: an owner whose render() calls setTabs() on every render, with a
 * fresh Tree instance inside a fresh Row, under Tui::headless($app, 60, 8).
 */

use phasync\Tui\Component;
use phasync\Tui\Element\Help;
use phasync\Tui\Component\Table;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\Tree;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Tui;

final class TrappedTabApp extends Component
{
    public ?Table $table = null;
    public ?Tree $tree = null;
    public ?Tabs $tabs = null;
    public ?Terminal $term = null;
    private ?Terminal $plain = null;

    public function __construct(private string $shape)
    {
    }

    protected function render(): Element
    {
        $this->table = $this->child('table', fn() => new Table(columns: ['A' => 'auto'], rows: [1 => ['x'], 2 => ['y']]));
        $this->tree = new Tree(items: [['label' => 'g', 'key' => 'g']]);
        $this->tabs = $this->child('tabs', fn() => new Tabs());
        $this->term = match ($this->shape) {
            'child' => $this->child('term', fn() => new Terminal(['sh'])),
            'keep' => $this->keep('term', fn() => new Terminal(['sh'])),
            'prop' => $this->plain ??= new Terminal(['sh']),
        };
        $this->tabs->setTabs([
            'a' => ['label' => 'Table', 'content' => new Row([$this->table, new Box($this->tree, 't')])],
            'b' => ['label' => 'Shell', 'content' => $this->term],
        ]);
        return new Column([$this->tabs->with(class: 'grow'), new Help()]);
    }

    /** What the keys reach: the innermost component on the focus path. */
    public function focused(): string
    {
        $end = null;
        for ($s = $this->surface->root(); $s !== null; $s = $s->focused()) {
            $end = Component::of($s) ?? $end;
        }
        return match (true) {
            $end instanceof Table => 'table',
            $end instanceof Tree => 'tree',
            $end instanceof Terminal => 'shell',
            $end instanceof Tabs => 'bar',
            default => 'nothing',
        };
    }

    /** The Tree the tab shows (the first render's instance, given the props of every later one). */
    public function shownTree(): ?Tree
    {
        $found = null;
        $walk = function (\phasync\Tui\Surface $s) use (&$walk, &$found): void {
            foreach ($s->viewports() as $v) {
                $c = Component::of($v->source);
                if ($c instanceof Tree) {
                    $found = $c;
                }
                $walk($v->source);
            }
        };
        $walk($this->surface);
        return $found;
    }
}

dataset('trapped shapes', ['child', 'keep', 'prop']);

it('walks Tab table -> tree -> bar after the shell tab of a setTabs()-per-render owner was shown and left', function (string $shape) {
    fiber\run(function () use ($shape) {
        $app = new TrappedTabApp($shape);
        $t = Tui::headless($app, 60, 8);
        try {
            $step = function (string $key) use ($t, $app): string {
                $t->press($key);
                fiber\sleep(0.1);
                $t->settle();
                return $app->focused();
            };
            fiber\sleep(0.1);
            $t->settle();
            expect($app->focused())->toBe('table');
            $tree = $app->shownTree();
            expect($tree)->not->toBeNull()
                ->and($step('tab'))->toBe('tree')
                ->and($step('shift+tab'))->toBe('table')
                ->and($step('alt+2'))->toBe('shell')
                ->and($step('alt+1'))->toBe('table');
            $walk = [];
            foreach (range(1, 4) as $_) {
                $walk[] = $step('tab');
            }
            expect($walk)->toBe(['tree', 'bar', 'table', 'tree']);
            $walk = [];
            foreach (range(1, 3) as $_) {
                $walk[] = $step('shift+tab');
            }
            expect($walk)->toBe(['table', 'bar', 'tree'])
                ->and($app->shownTree())->toBe($tree);      // one Tree all along, whatever render made it
        } finally {
            $t->detach();
        }
    });
})->with('trapped shapes');

/*
 * TODO-0.0.3-beta4 item 2: an element with onKey: that contains focusable components is not a
 * stop of the Tab walk; its keys apply while the focus is inside it, and its onFocus: fires when
 * the focus enters it from outside. One with no focusable content is a stop.
 */

use phasync\Tui\Component\Button;
use phasync\Tui\Element\Text;
use phasync\Tui\State;

/** Three job rows of [Text, Button Pause, Button Cancel], with or without onKey: on the Row. */
function jobRows(State $s, bool $onKey): Closure
{
    return fn() => new Column(array_map(fn(int $i) => new Row([
        new Text("job $i " . ($s->paused[$i] ? 'paused' : 'running'), class: 'w-16'),
        new Button('Pause', fn() => $s->paused = array_replace($s->paused, [$i => true]), key: "p$i"),
        new Button('Cancel', fn() => null, key: "c$i"),
    ], gap: 1, onKey: $onKey ? ['p' => fn() => $s->paused = array_replace($s->paused, [$i => true]), 'x' => fn() => $s->log = [...$s->log, "x$i"]] : null,
        onFocus: fn() => $s->entered = [...$s->entered, $i]), [1, 2, 3]));
}

/** The label of the focused Button and its row ("P2", "C3"), or what else has the keys. */
function focusedButton(FakeTerminal $t, Component $root): string
{
    $end = null;
    for ($s = $root->surface->root(); $s !== null; $s = $s->focused()) {
        $end = Component::of($s) ?? $end;
    }
    if (!$end instanceof Button) {
        return $end === null ? 'nothing' : $end::class;
    }
    $area = $end->surface->area();
    return $end->label[0] . ($area->y + 1);
}

dataset('row onKey', ['without onKey' => false, 'with onKey' => true]);

it('walks Tab through the buttons of rows the same with and without onKey: on the rows', function (bool $onKey) {
    fiber\run(function () use ($onKey) {
        $s = new State(['paused' => [1 => false, 2 => false, 3 => false], 'entered' => [], 'log' => []]);
        $t = Tui::headless(jobRows($s, $onKey), 60, 6);
        $app = $t->component();
        try {
            $t->settle();
            $walk = [focusedButton($t, $app)];
            foreach (range(1, 6) as $_) {
                $t->press('tab');
                $t->settle();
                $walk[] = focusedButton($t, $app);
            }
            expect($walk)->toBe(['P1', 'C1', 'P2', 'C2', 'P3', 'C3', 'P1']);
            foreach (range(1, 6) as $_) {
                $t->press('tab');
                $t->settle();
                $walk[] = focusedButton($t, $app);
            }
            expect(array_slice($walk, 7))->toBe(['C1', 'P2', 'C2', 'P3', 'C3', 'P1']);
            $back = [];
            foreach (range(1, 12) as $_) {
                $t->press('shift+tab');
                $t->settle();
                $back[] = focusedButton($t, $app);
            }
            expect($back)->toBe(['C3', 'P3', 'C2', 'P2', 'C1', 'P1', 'C3', 'P3', 'C2', 'P2', 'C1', 'P1']);
            // onFocus: once per entry from outside, not per button inside: 1, then 2 3 1 2 3 1, back 3 2 1 3 2 1
            expect($s->entered)->toBe([1, 2, 3, 1, 2, 3, 1, 3, 2, 1, 3, 2, 1]);
        } finally {
            $t->detach();
        }
    });
})->with('row onKey');

it('applies the keys of a row with onKey: while a button inside it has the focus', function () {
    fiber\run(function () {
        $s = new State(['paused' => [1 => false, 2 => false, 3 => false], 'entered' => [], 'log' => []]);
        $t = Tui::headless(jobRows($s, true), 60, 6);
        $app = $t->component();
        try {
            $t->settle();
            $t->press('tab', 'tab', 'tab');          // C2: inside row 2
            $t->settle();
            expect(focusedButton($t, $app))->toBe('C2');
            $t->press('p', 'x');
            $t->settle();
            expect($s->paused)->toBe([1 => false, 2 => true, 3 => false])->and($s->log)->toBe(['x2'])
                ->and($t->text())->toContain('job 2 paused');
            $t->press('shift+tab', 'shift+tab');     // C1
            $t->press('x');
            expect($s->log)->toBe(['x2', 'x1']);
        } finally {
            $t->detach();
        }
    });
});

it('keeps an element with onKey: and nothing focusable inside a stop of the Tab walk', function () {
    fiber\run(function () {
        $s = new State(['at' => []]);
        $t = Tui::headless(fn() => new Column([
            new Text('one', onKey: ['enter' => fn() => $s->at = [...$s->at, 1]]),
            new Row([new Text('two')], onKey: ['enter' => fn() => $s->at = [...$s->at, 2]]),
            new Button('three', fn() => $s->at = [...$s->at, 3]),
        ]), 40, 5);
        try {
            $t->settle();
            $t->press('enter', 'tab', 'enter', 'tab', 'enter', 'tab', 'enter');
            $t->settle();
            expect($s->at)->toBe([1, 2, 3, 1]);
        } finally {
            $t->detach();
        }
    });
});
