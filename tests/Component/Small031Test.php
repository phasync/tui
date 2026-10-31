<?php

/*
 * TODO-0.0.3-beta1 item 5: the first focus and Tab by default, a ProgressBar that draws only
 * what changes, detach() with programs running, an inline region taller than the terminal.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Tui;

/** Buttons under a progress bar (which takes no keys), each logging its name. */
final class FocusDefaults extends Component
{
    /** @var list<string> */
    public array $pressed = [];

    public function __construct(private bool $editor = false)
    {
    }

    public function press(string $name): void
    {
        $this->pressed[] = $name;
    }

    protected function render(): Element
    {
        return new Column([
            new ProgressBar(0.4),
            new Text('choose:'),
            new Row([new Button('A', onPress: fn() => $this->press('A')), new Button('B', onPress: fn() => $this->press('B'))], gap: 1),
            $this->editor ? $this->child('editor', fn() => new Editor()) : null,
            new Button('C', onPress: fn() => $this->press('C')),
        ]);
    }
}

final class DetachHolder extends Component
{
    public function __construct(private \Closure $tree)
    {
    }

    protected function render(): Element
    {
        return ($this->tree)();
    }
}

it('gives the focus to the first component that takes keys when the program focused nothing', function () {
    mountOn($app = new FocusDefaults(), 30, 6, function (FakeTerminal $t, FocusDefaults $app) {
        $t->settle();
        $t->press('enter');
        expect($app->pressed)->toBe(['A']);
    });
});

it('walks Tab and Shift-Tab through the components that take keys, around, when nothing binds them', function () {
    mountOn($app = new FocusDefaults(editor: true), 30, 8, function (FakeTerminal $t, FocusDefaults $app) {
        $t->settle();
        foreach (['tab', 'enter', 'tab', 'tab', 'enter', 'tab', 'enter', 'shift+tab', 'shift+tab', 'enter'] as $key) {
            $t->press($key);
            $t->settle();
        }
        // A -> B (enter) -> the editor -> C (enter) -> A (enter) -> C -> the editor (enter types a line)
        expect($app->pressed)->toBe(['B', 'C', 'A']);
        $t->press('shift+tab', 'shift+tab');
        $t->settle();
        $t->press('enter');
        $t->settle();
        expect($app->pressed)->toBe(['B', 'C', 'A', 'A']);     // (from the editor: B, then A)
    });
});

it('leaves the focus and Tab alone where the program says otherwise: its own focus(), a Tab binding, a shell', function () {
    $app = new class () extends Component {
        public int $tabs = 0;

        protected function bindings(): array
        {
            return ['tab' => 'count'];
        }

        public function count(): void
        {
            $this->tabs++;
        }

        protected function render(): Element
        {
            return new Column([new Button('A'), $this->child('b', fn() => new Button('B'))]);
        }

        protected function mount(): void
        {
            $this->child('b', fn() => new Button('B'))->focus();
        }
    };
    mountOn($app, 20, 4, function (FakeTerminal $t, Component $app) {
        $t->settle();
        expect($app->tabs)->toBe(0);
        $t->press('tab', 'tab');
        $t->settle();
        expect($app->tabs)->toBe(2);        // bound: the binding, not the walk
    });
    // a shell gets its Tab (completion), however many buttons there are
    \fiber\run(function () {
        $t = Tui::headless(fn() => new Column([new Terminal(['sh', '-c', 'stty -echo; read line; echo "got [$line]"; sleep 5']), new Button('X')]), 30, 6);
        $t->settle();
        \fiber\sleep(0.3);
        $t->type("a\tb\n");
        expect($t->waitFor('got [a', 5))->toBeTrue();      // (a Tab walked to the button would have taken the Enter too)
        $t->detach();
    });
});

it('draws a ProgressBar again only when it looks different', function () {
    \fiber\run(function () {
        $bar = new ProgressBar(0.5);
        $t = Tui::headless(fn() => new Column([$bar]), 40, 2);     // (a closure that reads no state: $bar is shown as it is)
        $t->settle();
        $bytes = \strlen($t->output());
        $version = $bar->surface->version();
        for ($i = 0; $i < 20; $i++) {
            $bar->setValue(0.5 + $i * 0.0002);      // less than a cell, the same percentage
            \fiber\sleep(0.005);
        }
        $t->settle();
        expect($bar->surface->version())->toBe($version)->and(\strlen($t->output()))->toBe($bytes);
        $bar->setValue(0.9);
        $t->settle();
        expect($t->text())->toContain('90%');
        $t->detach();
    });
});

it('returns from detach() with programs running in every kind of place, and ends them', function () {
    $shapes = [
        'child' => fn() => new Column([new Text('x'), new Terminal('sleep 30')]),
        'hidden tab' => fn() => new Tabs(['b' => ['label' => 'B', 'content' => new Terminal('sleep 30')], 'a' => ['label' => 'A', 'content' => new Text('a')]]),
        'shown tab' => fn() => new Tabs(['b' => ['label' => 'B', 'content' => new Terminal('sleep 30')]]),
        'ignores SIGHUP' => fn() => new Column([new Terminal(['sh', '-c', 'trap "" HUP; sleep 30'])]),
    ];
    foreach ($shapes as $name => $make) {
        $started = microtime(true);
        $pids = [];
        \fiber\run(function () use ($make, &$pids, $name) {
            $app = new DetachHolder($make);
            $t = new FakeTerminal(40, 6);
            $app->start($t);
            $t->settle();
            \fiber\sleep(0.3);
            $collect = function (Component $c) use (&$collect, &$pids): void {
                if ($c instanceof Terminal && $c->pty() !== null) {
                    $pids[] = $c->pty()->pid();
                }
                foreach ($c->children() as $child) {
                    $collect($child);
                }
            };
            if ($name === 'hidden tab') {
                $t->press('alt+2');         // the shell goes on in the hidden tab
                $t->settle();
                expect($t->text())->not->toContain('sleep');
            }
            $collect($app);
            expect($pids)->not->toBeEmpty();
            $t->detach();
        });
        expect(microtime(true) - $started)->toBeLessThan(3.0, $name);
        foreach ($pids as $pid) {
            expect(file_exists("/proc/$pid") && !str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z '))->toBeFalse($name);
        }
    }
});

it('clips an inline region at the terminal\'s height and does not paint the rows that do not fit', function () {
    \fiber\run(function () {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = new Text("line $i");
        }
        $t = Tui::headless(new Column($rows), 30, 10, inline: true);
        $t->settle();
        $region = explode("\n", $t->region());
        expect(\count($region))->toBe(9)                    // the terminal's height, less the line under it
            ->and($region[0])->toBe('line 0')->and($region[8])->toBe('line 8')
            ->and($t->frozen())->toBe('')
            ->and($t->output())->not->toContain('line 9')
            ->and($t->output())->not->toContain('line 19');
        $t->detach();
    });
});
