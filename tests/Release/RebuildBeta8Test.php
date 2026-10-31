<?php

/*
 * TODO-0.0.3-beta8 item 1, the frame where all 50 bars move: a closure screen that makes its
 * elements anew keeps the last layout when they lay out the same (Tree::unchanged()), styles
 * are kept while nothing they depend on changed (Component::style()), the parser looks at an
 * item's first byte. These are the guarantees that come with it: whatever is skipped, what
 * shows and what runs is what a full build gives.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\State;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;
use phasync\Tui\Tui;

it('runs the handlers and shows the props of the latest render when the elements lay out as before', function () {
    $got = [];
    fiber\run(function () use (&$got) {
        $s = new State(['n' => 0, 'v' => 0.1]);
        $t = Tui::headless(function () use ($s, &$got) {
            $n = $s->n;
            $item = function (string $name) use ($n, &$got) {
                return new Text($name, onKey: ['x' => function () use ($name, $n, &$got) {
                    $got[] = "$name key $n";
                    return true;
                }], onFocus: function () use ($name, $n, &$got) {
                    $got[] = "$name focus $n";
                });
            };
            return new Column([$item('a'), $item('b'), new Row([new Text('bar', width: 4), new ProgressBar($s->v)])]);
        }, 30, 3);
        $t->settle();
        $t->press('x');
        $s->n = 1;
        $s->v = 0.9;
        $t->settle();
        expect($t->text())->toContain('90%');
        $t->press('x', 'tab');
        $t->settle();       // (focus callbacks come after the frame's render)
        $t->press('x');
        $t->detach();
    });
    expect($got)->toBe(['a focus 0', 'a key 0', 'a key 1', 'b focus 1', 'b key 1']);
});

it('lays out again when a text, an attribute or a component element\'s class changes', function () {
    fiber\run(function () {
        $s = new State(['label' => 'one', 'wide' => false, 'hide' => false]);
        $t = Tui::headless(fn() => new Column([
            new Text($s->label),
            new Row([new Text('a', width: $s->wide ? 6 : 2), new Text('b')]),
            new Button('press', class: $s->hide ? 'hidden' : null),
        ]), 20, 3);
        $t->settle();
        expect(screenRows($t))->toBe(['one', 'a b', '[ press ]']);
        $s->label = 'two';
        $t->settle();
        expect(screenRows($t)[0])->toBe('two');
        $s->wide = true;
        $t->settle();
        expect(screenRows($t)[1])->toBe('a     b');
        $s->hide = true;
        $t->settle();
        expect(screenRows($t))->toBe(['two', 'a     b', '']);
        $t->detach();
    });
});

it('lays out again an element the program keeps from render to render and changes with with()', function () {
    fiber\run(function () {
        $s = new State(['v' => 0.0]);
        $kept = new Text('kept');
        // (the render changes nothing but a prop: what is laid out is the same but for $kept)
        $t = Tui::headless(fn() => new Column([$kept, new ProgressBar($s->v)]), 20, 3);
        $t->settle();
        $s->v = 0.2;
        $t->settle();
        expect(screenRows($t)[0])->toBe('kept');
        $kept->with(class: 'hidden');
        $s->v = 0.5;
        $t->settle();
        expect(screenRows($t)[0])->toContain('50%');
        $t->detach();
    });
});

it('gives a component the style of its state, attributes, focus and theme as they are now', function () {
    $c = new class extends Component {
        protected function render(): ?Element
        {
            return null;
        }
    };
    $c->with(class: 'card');
    mountOn($c, 10, 2, function () use ($c) {
        $c->setStylesheet(Stylesheet::default()->with(['.card:selected' => ['bold' => true], '.card.big' => ['italic' => true], '.card:focused' => ['underline' => true]]));
        expect($c->style()->text->has(Style::BOLD))->toBeFalse();
        $c->setStyleState('selected');
        expect($c->style()->text->has(Style::BOLD))->toBeTrue();
        $c->setStyleState('selected', false);
        expect($c->style()->text->has(Style::BOLD))->toBeFalse();
        $c->with(class: ['card', 'big']);
        expect($c->style()->text->has(Style::ITALIC))->toBeTrue();
        $c->focus();
        frame();
        expect($c->hasFocus())->toBeTrue();
        expect($c->style()->text->has(Style::UNDERLINE))->toBeTrue();
        $plain = $c->style('nothing');
        $c->setTheme('mono');
        expect($c->style('nothing'))->not->toBe($plain);
    });
});

it('draws a bar that moves cell by cell as a new bar at the same value draws it', function () {
    fiber\run(function () {
        $s = new State(['v' => 0.0]);
        $t = Tui::headless(fn() => new ProgressBar($s->v), 30, 1);
        $t->settle();
        foreach ([0.05, 0.31, 0.32, 0.77, 0.5, 1.0, 0.09, 0.1] as $v) {
            $s->v = $v;
            $t->settle();
            $fresh = Tui::headless(fn() => new ProgressBar($v), 30, 1);
            $fresh->settle();
            expect($t->text())->toBe($fresh->text(), "at $v");
            $fresh->detach();
        }
        $t->detach();
    });
});

it('parses what starts with a C1 control and what a chunk cuts off, as before the first-byte guards', function () {
    $s = vt("a\u{9b}31mred\e[0m \u{9d}2;title\u{9c}b");
    expect(rows($s))->toStartWith('ared b');
    expect($s->title())->toBe('title');
    $s = vt('');
    foreach (["x\e", '[1', ';31', 'mR', "\u{9b}", '0mN', "\e]2;t", "\e\\"] as $chunk) {
        $s->write($chunk);
    }
    expect(rows($s))->toStartWith('xRN');
    expect($s->title())->toBe('t');
});

it('shows after any sequence of changes what a new program of the same elements shows', function () {
    // (a closure screen made from a list of row specs; the specs change at random between
    // frames: texts, classes, widths, gaps, props, handlers, rows added and taken away)
    $make = function (array $spec): Column {
        $rows = [];
        foreach ($spec['rows'] as $i => $r) {
            $label = new Text($r['text'], class: $r['class'], width: $r['width']);
            $rows[] = new Row([
                $label,
                new ProgressBar($r['value']),
                new Button($r['button']),
                new Text(fn() => "c$i"),
            ], gap: $r['gap'], onKey: ['x' => fn() => true]);
        }
        return new Column([new Box(new Text($spec['title']), 'Title'), ...$rows]);
    };
    for ($seed = 1; $seed <= 12; $seed++) {
        mt_srand($seed);
        $row = fn() => ['text' => 'r' . mt_rand(0, 3), 'class' => [null, 'bold', 'hidden', 'dim'][mt_rand(0, 3)], 'width' => [null, 3, 6][mt_rand(0, 2)],
            'value' => mt_rand(0, 10) / 10, 'button' => ['ok', 'go', 'stop'][mt_rand(0, 2)], 'gap' => mt_rand(0, 1)];
        $spec = ['title' => 't', 'rows' => [$row(), $row(), $row()]];
        // (the program goes through the changes first; new programs show each state after:
        // one made in between would change what the running one's frames depend on)
        $seen = [];
        $specs = [];
        fiber\run(function () use ($make, $spec, $row, &$seen, &$specs) {
            $s = new State(['spec' => $spec]);
            $t = Tui::headless(fn() => $make($s->spec), 40, 12);
            $t->settle();
            for ($step = 0; $step < 15; $step++) {
                $next = $s->spec;
                $i = mt_rand(0, \count($next['rows']) - 1);
                switch (mt_rand(0, 7)) {
                    case 0: $next['rows'][$i]['value'] = mt_rand(0, 10) / 10; break;
                    case 1: $next['rows'][$i]['button'] = ['ok', 'go', 'stop'][mt_rand(0, 2)]; break;
                    case 2: $next['rows'][$i]['class'] = [null, 'bold', 'hidden', 'dim'][mt_rand(0, 3)]; break;
                    case 3: $next['rows'][$i]['text'] = 'r' . mt_rand(0, 3); break;
                    case 4: $next['rows'][$i]['width'] = [null, 3, 6][mt_rand(0, 2)]; break;
                    case 5: $next['rows'][$i]['gap'] = mt_rand(0, 1); break;
                    case 6: \count($next['rows']) < 5 ? $next['rows'][] = $row() : array_pop($next['rows']); break;
                    default: $next['title'] = 't' . mt_rand(0, 2);     // (or nothing but new closures)
                }
                $s->spec = $next;
                $t->settle();
                $seen[] = $t->root()->dump();
                $specs[] = $next;
            }
            $t->detach();
        });
        fiber\run(function () use ($make, $seen, $specs, $seed) {
            foreach ($specs as $step => $next) {
                $fresh = Tui::headless(fn() => $make($next), 40, 12);
                $fresh->settle();
                expect($seen[$step])->toBe($fresh->root()->dump(), "seed $seed, step $step");
                $fresh->detach();
            }
        });
    }
});
