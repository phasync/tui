<?php

/*
 * TODO-0.0.3-beta7 item 2: a tab switch in a realistic layout cost 25-30 ms at 120x40, growing
 * with the screen area. Every window is a level of compositing (its host parses what the
 * window writes), and the screen had more levels than it needed: a `Row` or `Column` that only
 * arranges had a window of its own (now its items are placed on its parent's host), a hidden
 * tab's pane told its components they had the focus and they drew themselves for nobody, every
 * component of a hidden or re-shown pane drew again for its area, a pane replaced by another was
 * blanked before it was painted over, and the parser and the diff did more per sequence than
 * they need to.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Markdown;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Tabs;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\State;
use phasync\Tui\Stylesheet;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

const BETA7_NOTES = "# Release notes\n\n## 0.1\n\n- **Faster** tab switches\n- `Markdown` costs what a `Text` costs\n\n```php\n\$t = Tui::headless(\$app);\n```\n\nSee [the docs](https://example.com).\n";

/** The reduced layout of the report; $content is the first tab's left pane (a Markdown, or a Text to compare with). */
function beta7Layout(Component|Element $content): Closure
{
    $list = new Select(['alpha', 'beta', 'gamma', 'delta']);
    $shell = new Terminal(['sh', '-c', 'echo ready; exec cat']);
    $tabs = new Tabs([
        'notes' => ['label' => 'Notes', 'content' => new Row([$content, new Box($list, 'Files')])],
        'shell' => ['label' => 'Shell', 'content' => new Box($shell, 'sh')],
    ], hints: true);
    $editor = new Editor('hello');
    return fn() => new Column([
        new Row([$tabs, new Box(new Text('tools'), 'Tools', width: 20)]),
        new Box($editor, 'Editor', height: 6),
        new Text(fn() => 'status: ' . date('H:i')),
    ]);
}

/** The median ms of $n switches (Alt-2, Alt-1, ...), press() then text(), at a person's pace. */
function beta7SwitchMedian(FakeTerminal $t, int $n = 10): float
{
    $times = [];
    for ($k = 0; $k < $n; $k++) {
        $a = hrtime(true);
        $t->press($k % 2 === 0 ? 'alt+2' : 'alt+1');
        $t->text();
        $times[] = (hrtime(true) - $a) / 1e6;
        fiber\sleep(0.01);
    }
    sort($times);
    return $times[intdiv($n, 2)];
}

it('lays the Markdown out once and never draws it again over ten tab switches', function (int $cols, int $rows) {
    fiber\run(function () use ($cols, $rows) {
        $md = new Markdown(BETA7_NOTES);
        $t = Tui::headless(beta7Layout($md), $cols, $rows);
        $t->settle();
        $t->press('alt+2');
        $t->settle();
        $t->press('alt+1');
        $t->settle();
        expect($t->text())->toContain('Release notes');
        $version = $md->surface->version();
        $resizes = 0;
        $md->surface->on('resize', function () use (&$resizes) {
            $resizes++;
        });
        for ($k = 0; $k < 10; $k++) {
            $t->press($k % 2 === 0 ? 'alt+2' : 'alt+1');
            $t->settle();
            expect($t->text())->toContain($k % 2 === 0 ? 'ready' : 'Release notes');
        }
        expect($md->surface->version())->toBe($version)->and($resizes)->toBe(0);
        $t->detach();
    });
})->with([[80, 24], [120, 40]]);

it('switches tabs in the realistic layout in well under the 25-30 ms of the report, a Markdown tab as fast as a Text tab', function () {
    $median = [];
    foreach (['md' => fn() => new Markdown(BETA7_NOTES), 'text' => fn() => new Text(BETA7_NOTES)] as $kind => $make) {
        foreach ([[80, 24], [120, 40]] as [$cols, $rows]) {
            fiber\run(function () use ($kind, $make, $cols, $rows, &$median) {
                $t = Tui::headless(beta7Layout($make()), $cols, $rows);
                $t->settle();
                beta7SwitchMedian($t, 4);       // (warm)
                $median[$kind][$cols] = beta7SwitchMedian($t);
                $t->detach();
            });
        }
    }
    // 120x40 about 7 ms back to back and 14 ms at a person's pace on the machine this was
    // written on (the report: 26-28 ms); 80x24 about 10 ms (the report: 18.5-19.5 ms)
    expect($median['md'][120])->toBeLessThan(22.0)
        ->and($median['md'][80])->toBeLessThan(16.0)
        ->and($median['md'][120])->toBeLessThan($median['text'][120] * 1.3 + 2.0);
});

it('places the items of a Row or Column that only arranges on its parent\'s window: one level of compositing', function () {
    fiber\run(function () {
        $t = Tui::headless(fn() => new Column([
            new Row([new Text('a'), new Column([new Text('b'), new Row([new Text('c'), new Text('d')], gap: 1)])], class: 'gap-1'),
            new Box(new Column([new Text('in a box'), new Text('two')]), 'Box'),
        ]), 30, 8);
        $t->settle();
        $root = $t->root();
        $sources = array_map(fn($v) => $v->source, $root->viewports());
        // the texts a, b, c, d and the box: all windows of the root itself; the box's column is
        // the box's: its texts are windows of the box
        expect(count($sources))->toBe(5)
            ->and(array_map(fn(Surface $s) => $s->line(0), $sources))->toBe(['a', 'b', 'c', 'd', 'in a box'])
            ->and(count($sources[4]->viewports()))->toBe(2)
            ->and(array_slice(explode("\n", $t->text()), 0, 4))->toBe(['a b', '  c d', '╭ Box ───────────────────────╮', '│in a box                    │']);
        $t->detach();
    });
});

it('keeps a window for a container with a frame, a background, keys, a focus look or a hover look', function (Closure $make) {
    fiber\run(function () use ($make) {
        $sheet = Stylesheet::default()->with(['Row.look:focused' => ['color' => 'red'], 'Row.hov:hover' => ['color' => 'blue']]);
        $t = Tui::headless(fn() => new Column([$make(), new Button('ok')]), 30, 6);
        Component::of($t->root())->setStylesheet($sheet);
        $t->settle();
        // (the container is a window of the root, its text a window of the container)
        $containers = array_values(array_filter(array_map(fn($v) => $v->source, $t->root()->viewports()), fn(Surface $s) => $s->viewports() !== []));
        expect($containers)->toHaveCount(1)->and($containers[0]->viewports()[0]->source->line(0))->toBe('x');
        $t->detach();
    });
})->with([
    'a border' => [fn() => new Row([new Text('x')], class: 'border')],
    'a background' => [fn() => new Row([new Text('x')], class: 'bg-blue')],
    'keys' => [fn() => new Row([new Text('x')], onKey: ['p' => fn() => true])],
    'onFocus' => [fn() => new Row([new Text('x')], onFocus: fn() => null)],
    'onMouse' => [fn() => new Row([new Text('x')], onMouse: fn() => null)],
    'a state' => [fn() => new Row([new Text('x')], selected: true)],
    'a :focused rule' => [fn() => new Row([new Text('x')], class: 'look')],
    'a focus: class' => [fn() => new Row([new Text('x')], class: 'focus:bold')],
    'a hover: class' => [fn() => new Row([new Text('x')], class: 'hover:bold')],
    'a :hover rule' => [fn() => new Row([new Text('x')], class: 'hov')],
]);

it('lists a container without a window of its own in Mouse::over(), between its host and its items', function () {
    fiber\run(function () {
        $over = new ArrayObject();
        $t = Tui::headless(fn() => new Column([
            new Text('top'),
            new Row([new Column([new Text('card', key: 'card', onMouse: function (Mouse $m) use ($over) {
                $over->exchangeArray(array_map(fn(Element $e) => $e->attribute('key') ?? $e::class, $m->over()));
            })], key: 'lane')], key: 'board'),
        ]), 20, 4);
        $t->settle();
        $t->click(1, 1);
        // (the lane and the board have no window: the card's is one of the root's)
        expect($over->getArrayCopy())->toBe(['card', 'lane', 'board'])
            ->and($t->root()->viewports()[1]->source->line(0))->toBe('card');
        $t->detach();
    });
});

it('moves the items of a container between its own window and its parent\'s when its look changes, focus and text intact', function () {
    fiber\run(function () {
        $s = new State(['framed' => false]);
        $a = new Button('a');
        $b = new Button('b');
        $t = Tui::headless(fn() => new Column([
            new Text('head'),
            new Row([$a, $b], class: $s->framed ? 'border' : null, gap: 1),
        ]), 30, 6, keys: ['f' => fn() => $s->framed = !$s->framed]);
        $t->settle();
        $t->press('tab');
        $t->settle();
        expect($b->hasFocus())->toBeTrue();
        foreach ([true, false, true] as $framed) {
            $t->press('f');
            $t->settle();
            expect($b->hasFocus())->toBeTrue()->and($t->text())->toContain('head')->toContain('a')->toContain('b');
            expect(str_contains($t->text(), '│[ a ] [ b ]'))->toBe($framed);
        }
        $t->press('shift+tab');
        $t->settle();
        expect($a->hasFocus())->toBeTrue();
        $t->detach();
    });
});

it('tells the components of a hidden tab nothing of the focus while it is hidden', function () {
    fiber\run(function () {
        $events = [];
        $field = new class ($events) extends Editor {
            public function __construct(private array &$log)
            {
                parent::__construct('x');
            }

            protected function onFocus(\phasync\Tui\Focus $focus): void
            {
                $this->log[] = $focus->focused ? 'in' : 'out';
            }
        };
        $tabs = new Tabs(['a' => ['label' => 'A', 'content' => $field], 'b' => ['label' => 'B', 'content' => new Editor('y')]]);
        $t = Tui::headless(fn() => $tabs, 30, 6);
        $t->settle();
        $t->press('tab');       // (into the content: the field)
        $t->settle();
        $events = [];
        $t->press('alt+2');
        $t->settle();
        $t->press('alt+1');
        $t->settle();
        expect($events)->toBe(['out', 'in']);
        $t->detach();
    });
});

it('applies an SGR that starts with a reset the same from any pen, keeping a hyperlink in force', function () {
    $s = new Surface(20, 2);
    $s->write("\e[0;1;38;2;1;2;3ma\e[3;4;48;5;9mb\e[0;1;38;2;1;2;3mc");
    $s->write("\e]8;;https://example.com\e\\\e[0;1;38;2;1;2;3md\e]8;;\e\\");
    $a = $s->cell(0, 0)->style;
    expect($s->cell(2, 0)->style)->toBe($a)
        ->and($a->has(\phasync\Tui\Style::BOLD))->toBeTrue()->and($a->has(\phasync\Tui\Style::ITALIC))->toBeFalse()
        ->and($s->cell(3, 0)->style->link)->toBe('https://example.com')
        ->and($s->cell(3, 0)->style->fg)->toBe($a->fg);
});

it('prints a run into a row cell by cell, autowrap on and off, over wide characters', function () {
    $s = new Surface(8, 3);
    $s->write("漢字漢字\e[1;2Habc");       // (over the second half of 漢 and into 字)
    expect($s->line(0))->toBe(' abc漢字');
    $s->write("\e[?7l\e[2;5Hlonger text");     // without autowrap the rest lands in the last column
    expect($s->line(1))->toBe('    lont')->and($s->line(2))->toBe('');
    $s->write("\e[?7h\e[3;7Hwrap");          // (on the last row: the screen scrolls)
    expect($s->line(1))->toBe('      wr')->and($s->line(2))->toBe('ap')->and($s->line(0))->toBe('    lont');
});
