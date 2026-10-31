<?php

/*
 * TODO-0.0.3-beta4 item 3, small things from use: Tui::quit(), Select's initial value, Editor
 * completion that finds nothing, Alt-number anywhere in Tabs, Tui::go() failures, println()
 * markup, State arrays by index, press() then text(), exceptions in headless test code.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Select;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

it('ends a closure program with Tui::quit() from a handler and from a coroutine', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Button('Quit', fn() => Tui::quit()), 30, 3);
        $t->settle();
        expect($t->component()?->isMounted())->toBeTrue();
        $app = $t->component();
        $t->press('enter');
        expect($app->isMounted())->toBeFalse();

        $t = Tui::headless(new Text('working'), 30, 3);
        $app = $t->component();
        fiber\go(function () {
            fiber\sleep(0.05);
            Tui::quit();
        });
        fiber\sleep(0.2);
        expect($app->isMounted())->toBeFalse();
        Tui::quit();                       // nothing runs: nothing happens
    });
});

it('ends the program with the key action quit, listed by Help', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Column([new Text('hello'), new \phasync\Tui\Element\Help()]), 40, 3, keys: ['q' => ['quit', 'leave']]);
        $t->settle();
        $app = $t->component();
        expect($t->text())->toContain('q leave');
        $t->type('q');
        expect($app->isMounted())->toBeFalse();
    });
    // a component's own quit() method is its own
    $app = new class () extends Component {
        public int $asked = 0;
        protected function bindings(): array { return ['q' => 'quit']; }
        public function quit(): void { $this->asked++; }
        protected function render(): Element { return new Text('x'); }
    };
    mountOn($app, 20, 3, function (FakeTerminal $t, Component $app) {
        $t->type('q');
        expect($app->asked)->toBe(1)->and($app->isMounted())->toBeTrue();
    });
});

it('starts a Select at the value given, one or several, and scrolled to it', function () {
    $items = [];
    foreach (range(1, 40) as $i) {
        $items["k$i"] = "item $i";
    }
    $one = new Select($items, value: 'k30');
    $many = new Select(['a' => 'A', 'b' => 'B', 'c' => 'C'], multiple: true, value: ['c', 'a', 'nope']);
    $none = new Select(['a' => 'A', 'b' => 'B'], value: 'nope');
    $drop = new Select(['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'], value: 'm', dropdown: true);
    expect($one->selected())->toBe('k30')->and($one->value())->toBe('k30')
        ->and($many->selection())->toBe(['a', 'c'])->and($none->selected())->toBe('a')->and($drop->value())->toBe('m');
    $app = new class ($one, $drop) extends Component {
        public function __construct(private Select $one, private Select $drop) {}
        protected function render(): Element { return new Column([$this->drop, $this->one->with(height: 5)]); }
    };
    mountOn($app, 30, 8, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('item 30')->toContain('Medium')->not->toContain('item 1 ');
    });
});

it('lets Tab walk the focus when an Editor\'s completion has no candidates', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Column([
            new Editor('', maxLines: 1, complete: fn(string $word) => $word === 'ap' ? ['apple', 'apricot'] : []),
            new Button('OK'),
        ]), 40, 8);
        try {
            $t->settle();
            $first = $t->component();
            $editor = fn() => focusedKindSmall($t);
            expect($editor())->toBe('Editor');
            $t->type('zz');
            $t->press('tab');                  // nothing completes zz: the focus moves on
            $t->settle();
            expect($editor())->toBe('Button');
            $t->press('shift+tab');
            $t->settle();
            expect($editor())->toBe('Editor');
            $t->press('ctrl+a', 'ctrl+k');
            $t->type('ap');
            $t->press('tab');                  // candidates: the completion takes Tab
            $t->settle();
            expect($editor())->toBe('Editor')->and($t->text())->toContain('apple');
        } finally {
            $t->detach();
        }
    });
});

/** The class name (short) of the component the keys reach. */
function focusedKindSmall(FakeTerminal $t): string
{
    $end = null;
    for ($s = $t->component()->surface->root(); $s !== null; $s = $s->focused()) {
        $end = Component::of($s) ?? $end;
    }
    return $end === null ? 'nothing' : (new ReflectionClass($end))->getShortName();
}

it('switches tabs with Alt-number wherever the focus is inside the Tabs content', function (string $where) {
    $app = new class ($where) extends Component {
        public ?\phasync\Tui\Component\Tabs $tabs = null;
        public ?\phasync\Tui\Component\Tabs $inner = null;
        public function __construct(private string $where) {}
        protected function render(): Element
        {
            $this->tabs = $this->child('tabs', fn() => new \phasync\Tui\Component\Tabs());
            $content = match ($this->where) {
                'editor' => new \phasync\Tui\Element\Box(new Editor('text', maxLines: 3)),
                'multi-line editor' => new Editor("a\nb"),
                'select' => new \phasync\Tui\Element\Row([new Text('pick'), new Select(['a' => 'A', 'b' => 'B'])]),
                'dropdown' => new Select(['a' => 'A', 'b' => 'B'], dropdown: true),
                'shell' => $this->child('sh', fn() => new \phasync\Tui\Component\Terminal(['sh', '-c', 'echo ready; cat'])),
                'nested tabs' => $this->inner = $this->child('inner', fn() => new \phasync\Tui\Component\Tabs(['x' => ['label' => 'X', 'content' => new Editor('inner')]])),
            };
            $this->tabs->setTabs([
                'one' => ['label' => 'One', 'content' => $content],
                'two' => ['label' => 'Two', 'content' => new Text('second tab', onKey: fn() => false)],
                'three' => ['label' => 'Three', 'content' => new Editor('third')],
            ]);
            return $this->tabs;
        }
    };
    mountOn($app, 50, 10, function (FakeTerminal $t, Component $app) use ($where) {
        $t->settle();
        $app->tabs->focusContent();        // (into the content, when the first focus was not there yet)
        $t->settle();
        if ($where === 'nested tabs') {
            $app->inner->focusContent();   // and into the inner tab's editor
        }
        if ($where === 'dropdown') {
            $t->press('enter');            // its list open
        }
        $t->settle();
        expect($app->tabs->inContent())->toBeTrue();
        $t->press('alt+3');
        $t->settle();
        expect($app->tabs->current())->toBe('three')->and($t->text())->toContain('third');
        $t->press('alt+2');
        $t->settle();
        expect($app->tabs->current())->toBe('two');
        $t->press('alt+1');
        $t->settle();
        expect($app->tabs->current())->toBe('one');
    });
})->with(['editor', 'multi-line editor', 'select', 'dropdown', 'shell', 'nested tabs']);

it('takes markup in println(), and prints Text::raw() as written', function () {
    fiber\run(function () {
        $t = FakeTerminal::inline(40, 6);
        $root = new phasync\Tui\Surface(40, 1);
        $t->attach($root);
        $t->println('<b>built</> <fg=green>ok</>');
        $t->println(Text::raw('<b>as is</b> a \< b'));
        $t->println(new Text(['plain ', new phasync\Tui\Element\Span('span', style: ['bold' => true])]));
        frame();
        $frozen = $t->frozen();
        expect($frozen)->toContain('built ok')->not->toContain('<fg=green>')
            ->and($frozen)->toContain('<b>as is</b> a \< b')->and($frozen)->toContain('plain span');
        // the replay of what was sent shows the bold where the markup said
        $replay = new phasync\Tui\Surface(40, 6, 100);
        $replay->write($t->output());
        $found = null;
        for ($y = -$replay->scrollbackSize(); $y < 6; $y++) {
            if (str_starts_with($replay->line($y), 'built ok')) {
                $found = $y;
            }
        }
        expect($found)->not->toBeNull()->and($replay->cell(0, $found)->style->has(phasync\Tui\Style::BOLD))->toBeTrue()
            ->and($replay->cell(6, $found)->style->has(phasync\Tui\Style::BOLD))->toBeFalse();
        $t->detach();
    });
});
