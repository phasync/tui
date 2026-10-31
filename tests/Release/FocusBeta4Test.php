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
