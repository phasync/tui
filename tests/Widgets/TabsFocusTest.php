<?php

/*
 * TODO-0.0.2-beta5 item 2: the focus follows into a tab whose content is an element tree
 * holding components (Row([$select, Box($tree)])), by key, mouse and select(); a content with
 * nothing to focus gives the focus to the bar. Item 4: a component made with child() and given
 * to Tabs stays its owner's.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\Tree;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;

/** A Tabs with a tab of an element tree of components ($shape 'element') or the Select itself ('component'), and a shell. */
final class FocusTabs extends Component
{
    public Tabs $tabs;
    public Select $select;
    public Tree $tree;

    public function __construct(private string $shape)
    {
    }

    protected function mount(): void
    {
        $this->select = new Select(['a' => 'Alpha', 'b' => 'Beta']);
        $this->tree = new Tree([['label' => 'root', 'children' => [['label' => 'leaf', 'leaf' => true]]]]);
        $content = $this->shape === 'element' ? new Row([$this->select, new Box($this->tree)]) : $this->select;
        $this->tabs = $this->child('tabs', fn() => new Tabs([
            'a' => ['label' => 'Files', 'content' => $content],
            'b' => ['label' => 'Shell', 'content' => new Terminal(['sh', '-c', 'echo ready; cat'], env: ['PS1' => '$ '])],
            'c' => ['label' => 'Notes', 'content' => new Text('nothing to focus here')],
        ], current: 'b'));
    }

    protected function render(): Element
    {
        return $this->tabs;
    }
}

dataset('content shapes', ['element', 'component']);

it('follows the focus into the first component of a tab, whatever wraps it, and back', function (string $shape) {
    $app = new FocusTabs($shape);
    mountOn($app, 60, 12, function (FakeTerminal $t, FocusTabs $app) {
        $t->settle();
        $t->press('enter');                                  // into the shell (tab B)
        $t->settle();
        $shell = $app->tabs->content('b');
        expect($app->tabs->inContent())->toBeTrue();
        expect($t->waitFor('ready', 5))->toBeTrue();

        $t->press('ctrl+tab');                               // B -> C: nothing to focus there, the bar takes it
        $t->settle();
        expect($app->tabs->current())->toBe('c')
            ->and($app->tabs->hasFocus())->toBeTrue()
            ->and($app->tabs->inContent())->toBeFalse();
        $t->press('ctrl+shift+tab', 'enter');                // back to B and into it
        $t->settle();
        expect($app->tabs->inContent())->toBeTrue();

        $t->press('ctrl+tab', 'ctrl+tab');                   // B -> C -> A: from the bar, the bar keeps it
        $t->settle();
        expect($app->tabs->current())->toBe('a');
        $t->press('ctrl+shift+tab', 'ctrl+shift+tab', 'enter');   // A -> C -> B, into the shell again
        $t->settle();
        $t->press('ctrl+shift+tab');                         // B -> A from inside the shell
        $t->settle();
        expect($app->tabs->current())->toBe('a')
            ->and($app->tabs->hasFocus())->toBeTrue()
            ->and($app->tabs->inContent())->toBeTrue()
            ->and($app->select->hasFocus())->toBeTrue()
            ->and($shell->hasFocus())->toBeFalse();
        $t->press('down');                                   // the Select takes the keys
        $t->settle();
        expect($app->select->selected())->toBe('b');

        $t->press('ctrl+tab');                               // A -> B: into the shell, which types
        $t->settle();
        expect($app->tabs->current())->toBe('b')->and($shell->hasFocus())->toBeTrue();
        $t->type("hello\n");
        expect($t->waitFor('hello', 5))->toBeTrue();
        $t->press('alt+1');                                  // Alt-1 to A from the shell too
        $t->settle();
        expect($app->select->hasFocus())->toBeTrue();
        $t->press('escape');                                 // Escape to the bar, Right/Left work there
        $t->settle();
        expect($app->tabs->inContent())->toBeFalse();
        $t->press('right');
        $t->settle();
        expect($app->tabs->current())->toBe('b');
    });
})->with('content shapes');

it('follows the focus into an element tab by select(), and a click focuses the bar', function (string $shape) {
    $app = new FocusTabs($shape);
    mountOn($app, 60, 12, function (FakeTerminal $t, FocusTabs $app) {
        $t->settle();
        $t->press('enter');
        $t->settle();
        $app->tabs->select('a');                             // from inside the shell
        $t->settle();
        expect($app->select->hasFocus())->toBeTrue();
        $app->tabs->select('b');
        $t->settle();
        expect($app->tabs->content('b')->hasFocus())->toBeTrue();
        $t->click(2, 0);                                     // a click on tab A focuses the bar
        $t->settle();
        expect($app->tabs->current())->toBe('a')->and($app->tabs->inContent())->toBeFalse()->and($app->tabs->hasFocus())->toBeTrue();
        $t->press('enter');                                  // and Enter goes into the content
        $t->settle();
        expect($app->select->hasFocus())->toBeTrue();
    });
})->with('content shapes');

it('keeps a component made with child() and given to a tab its owner\'s, while the tab is hidden', function () {
    $app = new class () extends Component {
        public ?Tabs $tabs = null;

        protected function render(): Element
        {
            return $this->tabs ??= $this->child('tabs', fn() => new Tabs([
                'log' => ['label' => 'Log', 'content' => $this->child('log', fn() => new \phasync\Tui\Component\Log())],
                'other' => ['label' => 'Other', 'content' => new Text('other')],
            ]));
        }

        public function log(): ?Component
        {
            return $this->child('log');
        }
    };
    mountOn($app, 40, 6, function (FakeTerminal $t, Component $app) {
        $t->settle();
        $log = $app->log();
        expect($log)->not->toBeNull()->and($app->tabs->content('log'))->toBe($log);
        $app->tabs->select('other');
        $t->settle();
        $app->requestRender();
        $t->settle();
        expect($app->log())->toBe($log)->and($log->isMounted())->toBeTrue();
        $log->append("still here\n");
        $app->tabs->select('log');
        $t->settle();
        expect($t->text())->toContain('still here');
        $app->tabs->setTabs(['other' => ['label' => 'Other', 'content' => new Text('other')]]);
        $t->settle();
        expect($log->isMounted())->toBeFalse()->and($app->log())->toBeNull();    // its tab went: stopped
    });
});
