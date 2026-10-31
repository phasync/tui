<?php

/*
 * Tabs through FakeTerminal: every key and mouse action, overflow, runtime changes, hidden panes.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Stylesheet;

/** Hosts a Tabs as its only child. */
final class TabsHost extends Component
{
    public Tabs $tabs;

    public function __construct(private Closure $make)
    {
    }

    protected function mount(): void
    {
        $this->tabs = $this->child('tabs', $this->make);
    }

    protected function render(): Element
    {
        return $this->tabs;
    }
}

function tabPane(string $text): Element
{
    return new Text($text);
}

function tabsOf(array $labels, array $extra = []): Closure
{
    return fn() => new Tabs(array_map(fn($l) => ['label' => $l, 'content' => tabPane("pane $l")], $labels), ...$extra);
}

function barRow(FakeTerminal $t, int $row = 0): string
{
    return rtrim(explode("\n", $t->text())[$row]);
}

it('shows the bar and the current content, top and bottom', function () {
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two']));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(barRow($t))->toBe(' One  Two')->and(barRow($t, 1))->toBe('pane One');
    });
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two'], ['position' => 'bottom']));
    mountOn($app, 20, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(barRow($t, 0))->toBe('pane One')->and(barRow($t, 3))->toBe(' One  Two');
    });
});

it('styles the current tab, the focused bar, the hover and the parts', function () {
    $ed = new Editor(maxLines: 1);
    $app = new TabsHost(fn() => new Tabs([
        'a' => ['label' => 'One', 'content' => $ed],
        'b' => ['label' => 'Two', 'content' => tabPane('x')],
    ]));
    $app->setStylesheet(Stylesheet::default()->with([
        'Tabs::tab' => ['color' => 'white'],
        'Tabs::tab:current' => ['color' => 'green'],
        'Tabs::tab:hover' => ['color' => 'red'],
        'Tabs::tab:current:focused' => ['color' => 'blue'],
        'Tabs::bar' => ['background' => 'black'],
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) {
        $t->settle();
        $app->tabs->focus();
        $t->settle();
        expect(drawn($t))->toContain('fg=4 bg=0] One ')->toContain('fg=7 bg=0] Two ');     // focused bar: the current tab in blue
        $t->press('tab');                                                                    // the focus in the content
        $t->settle();
        expect(drawn($t))->toContain('fg=2 bg=0] One ');
        $t->mouse(new Mouse(Mouse::MOVE, 7, 0));
        $t->settle();
        expect(drawn($t))->toContain('fg=1 bg=0] Two ');
        $t->mouse(new Mouse(Mouse::MOVE, 7, 2));
        $t->settle();
        expect(drawn($t))->toContain('fg=7 bg=0] Two ');
    });
});

it('switches with Left/Right, Home/End and does not wrap', function () {
    $changes = new ArrayObject();
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two', 'C' => 'Tri'], ['onChange' => fn($k) => $changes[] = $k]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) use ($changes) {
        $app->tabs->focus();
        $t->press('left');
        expect($app->tabs->current())->toBe('A');
        $t->press('right', 'right', 'right');
        $t->settle();
        expect($app->tabs->current())->toBe('C')->and(barRow($t, 1))->toBe('pane Tri');
        $t->press('home');
        expect($app->tabs->current())->toBe('A');
        $t->press('end');
        expect($app->tabs->current())->toBe('C')->and($changes->getArrayCopy())->toBe(['B', 'C', 'A', 'C']);
    });
});

it('switches with Ctrl-Tab around, Ctrl-Shift-Tab and Alt-1..9', function () {
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two', 'C' => 'Tri']));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) {
        $app->tabs->focus();
        $t->press('ctrl+tab');
        expect($app->tabs->current())->toBe('B');
        $t->press('ctrl+tab', 'ctrl+tab');
        expect($app->tabs->current())->toBe('A');
        $t->press('ctrl+shift+tab');
        expect($app->tabs->current())->toBe('C');
        $t->press('alt+1');
        expect($app->tabs->current())->toBe('A');
        $t->press('alt+3');
        expect($app->tabs->current())->toBe('C');
        $t->press('alt+9');             // no such tab
        expect($app->tabs->current())->toBe('C');
        $t->feed("\e[27;5;9~");          // Ctrl-Tab as a terminal sends it
        expect($app->tabs->current())->toBe('A');
    });
});

it('shows number hints and a close mark', function () {
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two'], ['hints' => true, 'closable' => true]));
    mountOn($app, 24, 3, function (FakeTerminal $t) {
        $t->settle();
        expect(barRow($t))->toBe(' 1 One ×  2 Two ×');
    });
});

it('selects with a click, closes with the × and a middle click, steps with the wheel', function () {
    $closed = new ArrayObject();
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two', 'C' => 'Tri'], ['closable' => true, 'onClose' => fn($k) => $closed[] = $k]));
    mountOn($app, 30, 3, function (FakeTerminal $t, TabsHost $app) use ($closed) {
        $t->settle();
        expect(barRow($t))->toBe(' One ×  Two ×  Tri ×');
        $t->click(9, 0);                                   // on the Two tab
        expect($app->tabs->current())->toBe('B')->and($app->tabs->hasFocus())->toBeTrue();
        $t->click(19, 0);                                  // on Tri's ×
        expect($closed->getArrayCopy())->toBe(['C'])->and($app->tabs->current())->toBe('B');
        $t->mouse(new Mouse(Mouse::PRESS, 2, 0, Mouse::MIDDLE));
        expect($closed->getArrayCopy())->toBe(['C', 'A']);
        $t->scroll(3, 0, 1);
        expect($app->tabs->current())->toBe('C');
        $t->scroll(3, 0, -1);
        expect($app->tabs->current())->toBe('B');
        $t->scroll(3, 1, 1);                               // the wheel below the bar does not change tab
        expect($app->tabs->current())->toBe('B');
    });
});

it('removes a closed tab itself without onClose and disposes its component', function () {
    $log = new Log();
    $app = new TabsHost(fn() => new Tabs([
        'a' => ['label' => 'One', 'content' => tabPane('x'), 'closable' => true],
        'l' => ['label' => 'Log', 'content' => $log, 'closable' => true],
    ], current: 'l'));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) use ($log) {
        $t->settle();
        $app->tabs->focus();
        $t->press('delete');
        $t->settle();
        expect($app->tabs->tabs())->toBe(['a'])->and($app->tabs->current())->toBe('a')->and($log->isMounted())->toBeFalse();
        $t->press('delete');
        $t->settle();
        expect($app->tabs->tabs())->toBe([])->and($app->tabs->current())->toBeNull();
    });
});

it('moves the focus into the content with Tab and Enter and back with Escape', function () {
    $ed = new Editor(maxLines: 1);
    $app = new TabsHost(fn() => new Tabs([
        'a' => ['label' => 'One', 'content' => $ed],
        'b' => ['label' => 'Two', 'content' => tabPane('plain')],
    ]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) use ($ed) {
        $t->settle();
        $tabs = $app->tabs;
        $tabs->focus();
        expect($tabs->inContent())->toBeFalse();
        $t->press('tab');
        expect($tabs->inContent())->toBeTrue()->and($ed->hasFocus())->toBeTrue();
        $t->type('hi');
        $t->press('right');                    // the editor's key, the bar does not change tab
        expect($ed->value())->toBe('hi')->and($tabs->current())->toBe('a');
        $t->press('escape');
        expect($tabs->inContent())->toBeFalse();
        $t->press('right');
        expect($tabs->current())->toBe('b');
        $t->press('left', 'enter');
        expect($tabs->inContent())->toBeTrue();
        $t->press('escape', 'right', 'tab');   // a tab with nothing to focus keeps the bar
        expect($tabs->current())->toBe('b')->and($tabs->inContent())->toBeFalse();
    });
});

it('lets the content have Escape first, and keeps the focus in content across Ctrl-Tab', function () {
    $a = new Editor('abc', maxLines: 1);
    $b = new Editor('xyz', maxLines: 1);
    $app = new TabsHost(fn() => new Tabs(['a' => ['label' => 'A', 'content' => $a], 'b' => ['label' => 'B', 'content' => $b]]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) use ($a, $b) {
        $t->settle();
        $app->tabs->focus();
        $t->press('tab');
        $t->press('shift+left');               // select a character: Escape clears the selection first
        $t->press('escape');
        expect($app->tabs->inContent())->toBeTrue();
        $t->press('escape');
        expect($app->tabs->inContent())->toBeFalse();
        $app->tabs->focusContent();
        $t->press('ctrl+tab');
        $t->settle();
        expect($app->tabs->current())->toBe('b')->and($b->hasFocus())->toBeTrue()->and($a->hasFocus())->toBeFalse();
    });
});

it('scrolls the bar when the labels exceed the width, the current tab always in view', function () {
    $labels = [];
    foreach (range(1, 8) as $i) {
        $labels["t$i"] = "Tab$i";
    }
    $app = new TabsHost(tabsOf($labels));
    mountOn($app, 22, 3, function (FakeTerminal $t, TabsHost $app) {
        $t->settle();
        $row = barRow($t);
        expect($row)->toStartWith(' ')->and($row)->toContain(' Tab1 ')->and($row)->toEndWith('›')
            ->and(mb_strlen($row))->toBeLessThanOrEqual(22);
        $app->tabs->focus();
        $t->press('end');
        $t->settle();
        $row = barRow($t);
        expect($row)->toStartWith('‹')->and($row)->toContain(' Tab8')->and($row)->not->toContain('›')->and($row)->not->toContain('Tab1');
        expect(barRow($t, 1))->toBe('pane Tab8');
        foreach (range(1, 8) as $i) {
            $t->press('alt+' . $i);
            $t->settle();
            expect(barRow($t))->toContain(" Tab$i");
        }
        $t->press('alt+4');
        $t->settle();
        $t->click(0, 0);                       // ‹ steps to the previous tab
        expect($app->tabs->current())->toBe('t3');
        $t->click(21, 0);                      // › to the next
        $t->click(21, 0);
        expect($app->tabs->current())->toBe('t5');
    });
});

it('truncates the label of a current tab wider than the bar', function () {
    $app = new TabsHost(tabsOf(['a' => 'A very long label indeed', 'b' => 'B']));
    mountOn($app, 12, 3, function (FakeTerminal $t) {
        $t->settle();
        $row = barRow($t);
        expect(mb_strlen($row))->toBeLessThanOrEqual(12)->and($row)->toContain('…')->and($row)->toEndWith('›');
    });
});

it('adds, removes and renames at runtime, keeping the current tab by key and its component state', function () {
    $changes = new ArrayObject();
    $ed = new Editor(maxLines: 1);
    $mk = fn(string $l, $c = null) => ['label' => $l, 'content' => $c ?? tabPane("pane $l")];
    $app = new TabsHost(fn() => new Tabs(['a' => $mk('One'), 'b' => $mk('Two', $ed), 'c' => $mk('Tri')], current: 'b', onChange: fn($k) => $changes[] = $k));
    mountOn($app, 30, 3, function (FakeTerminal $t, TabsHost $app) use ($mk, $ed, $changes) {
        $t->settle();
        $tabs = $app->tabs;
        $tabs->focus();
        $t->press('tab');
        $t->type('keep');
        // add one in front and rename the current: the current tab stays
        $tabs->setTabs(['z' => $mk('Zero'), 'a' => $mk('One'), 'b' => $mk('Two!', $ed), 'c' => $mk('Tri')]);
        $t->settle();
        expect($tabs->current())->toBe('b')->and(barRow($t))->toBe(' Zero  One  Two!  Tri')->and($ed->value())->toBe('keep')
            ->and($ed->hasFocus())->toBeTrue()->and($changes->getArrayCopy())->toBe([]);
        // remove the current: the one at its place takes over, with a change event
        $tabs->setTabs(['z' => $mk('Zero'), 'a' => $mk('One'), 'c' => $mk('Tri')]);
        $t->settle();
        expect($tabs->current())->toBe('c')->and($changes->getArrayCopy())->toBe(['c'])->and($ed->isMounted())->toBeFalse()
            ->and(barRow($t, 1))->toBe('pane Tri');
        // remove the last current: the new last
        $tabs->setTabs(['z' => $mk('Zero'), 'a' => $mk('One')]);
        expect($tabs->current())->toBe('a');
        // explicit current
        $tabs->setTabs(['z' => $mk('Zero'), 'a' => $mk('One')], 'z');
        expect($tabs->current())->toBe('z')->and($tabs->select('nope'))->toBeFalse();
        $tabs->setTabs([]);
        $t->settle();
        expect($tabs->current())->toBeNull()->and(barRow($t))->toBe('');
        $tabs->setTabs(['q' => $mk('Q')]);
        $t->settle();
        expect($tabs->current())->toBe('q')->and(barRow($t, 1))->toBe('pane Q');
    });
});

it('emits change and close events', function () {
    $app = new TabsHost(tabsOf(['A' => 'One', 'B' => 'Two'], ['closable' => true]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TabsHost $app) {
        $events = new ArrayObject();
        $app->tabs->on('change', fn($k) => $events[] = "change $k");
        $app->tabs->on('close', fn($k) => $events[] = "close $k");
        $app->tabs->select('B');
        $app->tabs->select('B');                // not a change
        $app->tabs->close('A');
        expect($events->getArrayCopy())->toBe(['change B', 'close A'])->and($app->tabs->tabs())->toBe(['B']);
    });
});

it('rejects tabs without a label or content', function () {
    expect(fn() => new Tabs(['a' => ['label' => 'x']]))->toThrow(InvalidArgumentException::class);
});

it('keeps a Terminal in a hidden tab running and receiving output', function () {
    $term = new Terminal(['sh', '-c', 'echo first; read x; echo got:$x; sleep 5'], env: ['PS1' => '']);
    $app = new TabsHost(fn() => new Tabs([
        'sh' => ['label' => 'Shell', 'content' => $term],
        'other' => ['label' => 'Other', 'content' => tabPane('elsewhere')],
    ]));
    mountOn($app, 40, 5, function (FakeTerminal $t, TabsHost $app) use ($term) {
        $tabs = $app->tabs;
        expect($t->waitFor(fn() => str_contains($t->text(), 'first'), 5))->toBeTrue();
        $tabs->focus();
        $t->press('alt+2');                     // hide the shell
        $t->settle();
        expect($t->text())->toContain('elsewhere')->and($t->text())->not->toContain('first');
        $term->pty()->write("hello\n");         // output arrives while it is hidden
        $deadline = microtime(true) + 5;
        while (!str_contains($term->surface->line(1) . $term->surface->line(2) . $term->surface->line(0), 'got:hello') && microtime(true) < $deadline) {
            fiber\sleep(0.02);
        }
        expect($term->isRunning())->toBeTrue();
        $t->press('alt+1');
        $t->settle();
        expect($t->text())->toContain('first')->toContain('got:hello')->and($t->text())->not->toContain('elsewhere');
    });
});

it('keeps Escape and the arrows for a Terminal in the content', function () {
    $term = new Terminal(['cat']);
    $app = new TabsHost(fn() => new Tabs(['sh' => ['label' => 'Shell', 'content' => $term]]));
    mountOn($app, 30, 4, function (FakeTerminal $t, TabsHost $app) use ($term) {
        $t->settle();
        $app->tabs->focus();
        $t->press('tab');
        expect($app->tabs->inContent())->toBeTrue();
        $t->press('escape', 'left');
        expect($app->tabs->inContent())->toBeTrue();
        $t->press('ctrl+tab');                  // around to itself: a single tab stays
        expect($app->tabs->inContent())->toBeTrue();
    });
});

it('takes new props from a parent that renders it anew', function () {
    $app = new class extends Component {
        public bool $bottom = false;

        protected function render(): Element
        {
            return new Tabs(['a' => ['label' => 'One', 'content' => tabPane('x')]], position: $this->bottom ? 'bottom' : 'top');
        }
    };
    mountOn($app, 12, 3, function (FakeTerminal $t) use ($app) {
        $t->settle();
        expect(barRow($t, 0))->toBe(' One');
        $app->bottom = true;
        $app->requestRender();
        $t->settle();
        expect(barRow($t, 2))->toBe(' One');
    });
});
