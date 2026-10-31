<?php

/*
 * TODO-0.0.3-beta3 item 3: a component the current render does not show is never in the focus
 * order, whoever owns it. The reported shape: Tabs whose shell tab is the owner's child() (or a
 * keep(), or a prop); Tab walks table -> tree -> bar; Alt-2 shows the shell, Alt-1 goes back;
 * Tab must walk again (it did nothing, the keys went to the hidden shell).
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Table;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\Tree;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;

final class HiddenShellTabs extends Component
{
    public Table $table;
    public Tree $tree;
    public Tabs $tabs;
    public Terminal $shell;

    public function __construct(private string $shape)
    {
    }

    protected function mount(): void
    {
        $this->table = new Table(['File' => 'auto'], [['a.txt'], ['b.txt']]);
        $this->tree = new Tree([['label' => 'root', 'children' => [['label' => 'leaf', 'leaf' => true]]]]);
        $make = fn() => new Terminal(['sh', '-c', 'echo ready; cat']);
        $this->shell = match ($this->shape) {
            'child' => $this->child('term', $make),
            'keep' => $this->keep('term', $make),
            'prop' => $make(),
        };
        $this->tabs = $this->child('tabs', fn() => new Tabs([
            'files' => ['label' => 'Files', 'content' => new Row([$this->table, new Box($this->tree)])],
            'shell' => ['label' => 'Shell', 'content' => $this->shell],
        ]));
    }

    protected function render(): Element
    {
        return $this->tabs;
    }

    public function focused(): string
    {
        return match (true) {
            $this->table->hasFocus() => 'table',
            $this->tree->hasFocus() => 'tree',
            $this->shell->hasFocus() => 'shell',
            $this->tabs->hasFocus() && !$this->tabs->inContent() => 'bar',
            default => 'nothing',
        };
    }
}

dataset('shell shapes', ['child', 'keep', 'prop']);
dataset('settling', ['settled' => true, 'at once' => false]);

it('walks Tab again after a hidden shell tab was shown and left', function (string $shape, bool $settle) {
    $app = new HiddenShellTabs($shape);
    mountOn($app, 60, 12, function (FakeTerminal $t, HiddenShellTabs $app) use ($settle) {
        $t->settle();
        $walk = [$app->focused()];
        foreach (range(1, 3) as $_) {
            $t->press('tab');
            $t->settle();
            $walk[] = $app->focused();
        }
        expect($walk)->toBe(['table', 'tree', 'bar', 'table']);

        $t->press('alt+2');                       // the shell tab, the focus follows into it
        if ($settle) {
            $t->settle();
            expect($t->waitFor('ready', 5))->toBeTrue()
                ->and($app->focused())->toBe('shell');
        }
        $t->press('alt+1');                       // back: the focus follows to the table
        $t->settle();
        expect($app->tabs->current())->toBe('files')->and($app->focused())->toBe('table');

        $walk = [];
        foreach (range(1, 4) as $_) {
            $t->press('tab');
            $t->settle();
            $walk[] = $app->focused();
        }
        expect($walk)->toBe(['tree', 'bar', 'table', 'tree']);
        $t->press('shift+tab');
        $t->settle();
        expect($app->focused())->toBe('table');

        $t->type('x');                            // nothing types into the hidden shell
        $t->press('alt+2');
        $t->settle();
        expect($t->waitFor('ready', 5))->toBeTrue();
        fiber\sleep(0.1);
        expect($t->text())->not->toContain('x');
    });
})->with('shell shapes')->with('settling');

it('does not leave the keys with a shell a render stopped showing, until it is focused again', function () {
    $s = new State(['shell' => true]);
    $app = new class ($s) extends Component {
        public ?Terminal $shell = null;

        public function __construct(private State $s)
        {
        }

        protected function render(): Element
        {
            $this->shell = $this->keep('shell', fn() => new Terminal(['sh', '-c', 'echo ready; cat']));
            return new Column([
                new Text('top'),
                $this->s->shell ? $this->shell : new Text('the shell is hidden'),
            ]);
        }
    };
    mountOn($app, 40, 8, function (FakeTerminal $t, Component $app) use ($s) {
        $t->settle();
        expect($t->waitFor('ready', 5))->toBeTrue()->and($app->shell->hasFocus())->toBeTrue();
        $s->shell = false;
        $t->settle();
        expect($app->shell->hasFocus())->toBeFalse();
        $t->type('lost');                         // goes nowhere: the shell is not shown
        $app->shell->focus();                     // asked for again: the keys go to it, also hidden
        expect($app->shell->hasFocus())->toBeTrue();
        $t->type('kept');
        $s->shell = true;
        $t->settle();
        expect($t->waitFor('kept', 5))->toBeTrue()->and($t->text())->not->toContain('lost');
    });
});
