<?php

/*
 * TODO-0.0.3-beta6 item 4: the admin shape (an owner's render() calls setTabs() on every render
 * with new Row([$keptTable, new Box(new Tree(...))]) as a tab's content, a plain new Help() at
 * the bottom): with the fresh Tree focused, the Help line was the tab bar's ("left previous tab
 * right next tab ..."); with the Tree kept via child() it was not. A key a component does not
 * take now goes on through the components it is shown in (the Tabs and its pane) and then its
 * owner, whoever made it; and the bar's keys of a Tabs are in force only on the bar.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Table;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Tree;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Tui;

final class HelpAdmin extends Component
{
    public ?Tabs $tabs = null;

    public function __construct(private bool $keepTree)
    {
    }

    protected function bindings(): array
    {
        return ['ctrl+r' => ['reload', 'reload']];
    }

    public function reload(): void
    {
    }

    protected function render(): Element
    {
        $table = $this->child('table', fn() => new Table(columns: ['Name' => 'auto'], rows: [1 => ['ann'], 2 => ['bob']]));
        $items = [['label' => 'group', 'key' => 'g', 'children' => [['label' => 'leaf', 'key' => 'l']]]];
        $tree = $this->keepTree ? $this->child('tree', fn() => new Tree(items: $items)) : new Tree(items: $items);
        $this->tabs = $this->child('tabs', fn() => new Tabs());
        $this->tabs->setTabs([
            'users' => ['label' => 'Users', 'content' => new Row([$table, new Box($tree, 'groups')])],
            'other' => ['label' => 'Other', 'content' => new Element\Text('other')],
        ]);
        return new Column([$this->tabs->with(class: 'grow'), new Help()]);
    }

    /** The innermost component the keys reach. */
    public function keyEnd(): ?Component
    {
        $end = null;
        for ($s = $this->surface->root(); $s !== null; $s = $s->focused()) {
            $end = Component::of($s) ?? $end;
        }
        return $end;
    }
}

/** The Help line (the last row) of $t. */
function helpLine(FakeTerminal $t): string
{
    $rows = screenRows($t);
    return end($rows);
}

it('shows the same Help line for a fresh and a kept Tree with the focus: the keys in force there, not the bar\'s', function () {
    $lines = [];
    foreach (['fresh' => false, 'kept' => true] as $shape => $keep) {
        fiber\run(function () use ($keep, $shape, &$lines) {
            $app = new HelpAdmin($keep);
            $t = Tui::headless($app, 100, 10);
            $t->settle();
            $t->press('tab');       // table -> tree
            $t->settle();
            expect($app->keyEnd())->toBeInstanceOf(Tree::class)->and($app->keyEnd()->hasFocus())->toBeTrue();
            $lines[$shape] = helpLine($t);
            // Left at a closed group of the tree does not switch the tab
            $t->press('left');
            $t->settle();
            expect($app->tabs->current())->toBe('users');
            // Escape goes to the bar (from either shape), and the bar's keys show there
            $t->press('escape');
            $t->settle();
            expect($app->keyEnd())->toBeInstanceOf(Tabs::class)->and(helpLine($t))->toContain('previous tab');
            $t->detach();
        });
    }
    expect($lines['fresh'])->toBe($lines['kept'])
        ->and($lines['fresh'])->not->toContain('previous tab')
        ->and($lines['fresh'])->toContain('tab bar')->toContain('reload');
});

it('lists the keys of the components a fresh one is shown in for Help(for:) too', function () {
    fiber\run(function () {
        $tree = null;
        $app = new class ($tree) extends Component {
            public function __construct(public ?Tree &$tree)
            {
            }

            protected function render(): Element
            {
                $this->tree = new Tree(items: [['label' => 'a', 'key' => 'a']]);
                $tabs = $this->child('tabs', fn() => new Tabs());
                $tabs->setTabs(['t' => ['label' => 'T', 'content' => new Box($this->tree)]]);
                return new Column([$tabs->with(class: 'grow'), new Help(for: $this->tree)]);
            }
        };
        $t = Tui::headless($app, 80, 8);
        $t->settle();
        for ($k = 0; $k < 3 && !$app->tree->hasFocus(); $k++) {
            $t->press('tab');
            $t->settle();
        }
        expect($app->tree->hasFocus())->toBeTrue()
            ->and(helpLine($t))->toContain('tab bar')->not->toContain('previous tab');
        $t->detach();
    });
});
