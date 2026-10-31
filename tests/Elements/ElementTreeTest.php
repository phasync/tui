<?php

/*
 * render() returning an element tree: laid out into the component's surface tree, painted
 * through write(), and matched with the previous render's tree so that surfaces, focus,
 * scroll positions and child components stay.
 */

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\TextInput;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Layers;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

/** A component whose tree is given by a closure, rendered again on demand. */
final class TreeApp extends Component
{
    public int $renders = 0;

    public function __construct(public \Closure $tree)
    {
    }

    protected function render(): Element
    {
        $this->renders++;
        return ($this->tree)($this);
    }

    /** @return Component|null the child under $key (for the tests) */
    public function get(string $key, ?\Closure $create = null): ?Component
    {
        return $this->child($key, $create);
    }
}

/** A component that draws itself with escape sequences. */
final class Gauge extends Component
{
    public function __construct(public int $value = 0)
    {
    }

    protected function render(): null
    {
        $w = $this->surface->cols();
        $full = intdiv($w * $this->value, 100);
        $this->surface->write(Ansi::clearHome() . "\e[32m" . str_repeat('#', $full) . "\e[0m" . str_repeat('.', $w - $full));
        return null;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }
}

it('gives text the rows it needs and the rest to what fills', function () {
    $app = new TreeApp(fn(TreeApp $app) => new Column([
        new Text('Title'),
        $app->get('log', fn() => new Log()),
        new Text('one two three four', style: ['align' => 'right']),
    ]));
    mountOn($app, 10, 6, function (FakeTerminal $t, TreeApp $app) {
        $app->get('log')->append("a\nb\n");
        frame();
        expect($t->text())->toBe("Title\na\nb\n\n   one two\nthree four");
        $app->get('log')->append("c\nd\ne\n");     // scrolls inside its window
        frame();
        expect($t->text())->toBe("Title\nd\ne\n\n   one two\nthree four");
    });
});

it('frames a box around its content, with a title, sized by its content', function () {
    $app = new TreeApp(fn() => new Column([
        new Box(new Text("one\ntwo"), 'box', style: ['border' => 'single']),
        new Row([new Box(new Text('ab'), style: ['border' => 'ascii']), new Text('x')]),
    ]));
    mountOn($app, 12, 7, function (FakeTerminal $t) {
        expect($t->text())->toBe(implode("\n", [
            '┌ box ─────┐',
            '│one       │',
            '│two       │',
            '└──────────┘',
            '+--+x',
            '|ab|',
            '+--+',
        ]));
    });
});

it('wraps and aligns text, cuts it when wrapping is off, and styles spans', function () {
    $app = new TreeApp(fn() => new Column([
        new Text('the quick brown fox', style: ['align' => 'center']),
        new Text("a long line here\nx", wrap: false),
        new Text(['plain ', new Span('bold', style: ['bold' => true]), ' plain'], style: ['color' => 'red']),
    ]));
    mountOn($app, 12, 6, function (FakeTerminal $t) {
        expect($t->text())->toBe(" the quick\n brown fox\na long line…\nx\nplain bold\nplain")
            ->and(drawn($t))->toContain('[fg=1]plain [/][bold fg=1]bold[/]');
    });
});

it('places layers over each other by size and alignment', function () {
    $app = new TreeApp(fn() => new Layers([
        new Text(str_repeat('.', 100)),
        new Box(new Text('hi'), width: 6, height: 3, style: ['align' => 'center', 'border' => 'single']),
    ]));
    mountOn($app, 10, 5, function (FakeTerminal $t) {
        expect($t->text())->toBe(implode("\n", ['..........', '..┌────┐..', '..│hi  │..', '..└────┘..', '..........']));
    });
});

it('keeps focus and scroll while the tree is rebuilt on every render', function () {
    $app = new TreeApp(fn(TreeApp $app) => new Column([
        new Text('render ' . $app->renders),
        new Row([
            new Box($app->get('log', fn() => new Log()), 'log ' . $app->renders),
            new Column([
                $app->get('a', fn() => new TextInput()),
                $app->get('b', fn() => new TextInput()),
            ]),
        ]),
    ]));
    mountOn($app, 30, 6, function (FakeTerminal $t, TreeApp $app) {
        $log = $app->get('log');
        $log->append(implode("\n", range(1, 30)) . "\n");
        frame();
        $log->scrollBy(-5);
        $app->get('b')->focus();
        frame();
        $t->type('x');
        $before = $log->topLine();
        for ($i = 0; $i < 5; $i++) {
            $app->requestRender();
            frame();
            $t->type((string) $i);
        }
        expect($app->get('b')->value())->toBe('x01234')
            ->and($app->get('a')->value())->toBe('')
            ->and($log->topLine())->toBe($before)
            ->and($log->isFollowing())->toBeFalse()
            ->and($t->text())->toContain('render ' . $app->renders);
    });
});

it('matches elements by key, so reordered items keep their components', function () {
    $order = ['a', 'b', 'c'];
    $app = new TreeApp(function () use (&$order) {
        return new Column(array_map(fn(string $k) => (new Gauge(50))->with(key: $k), $order));
    });
    mountOn($app, 10, 3, function (FakeTerminal $t, TreeApp $app) use (&$order) {
        $surfaces = [];
        foreach ($app->surface->viewports() as $i => $v) {
            $surfaces[$order[$i]] = $v->source;
        }
        $order = ['c', 'a', 'b'];
        $app->requestRender();
        frame();
        $now = [];
        foreach ($app->surface->viewports() as $v) {
            $now[] = array_search($v->source, $surfaces, true);
        }
        sort($now);
        expect($now)->toBe(['a', 'b', 'c'])
            ->and($app->surface->viewport($surfaces['c'])->y())->toBe(0)
            ->and($app->surface->viewport($surfaces['b'])->y())->toBe(2);
    });
});

it('mixes elements with components that draw themselves with escape sequences', function () {
    $value = 30;
    $app = new TreeApp(function () use (&$value) {
        return new Column([new Text('cpu'), new Gauge($value), new Text('end')]);
    });
    mountOn($app, 10, 4, function (FakeTerminal $t, TreeApp $app) use (&$value) {
        expect($t->text())->toBe("cpu\n###.......\nend\n");
        $value = 80;
        $app->requestRender();
        frame();
        expect($t->text())->toBe("cpu\n########..\nend\n")
            ->and(drawn($t))->toContain('[fg=2]########[/]');
    });
});

it('draws under its windows when render() writes and returns a tree', function () {
    $app = new class extends Component {
        protected function render(): Element
        {
            $this->surface->write(Ansi::clearHome() . str_repeat('~', $this->surface->cols() * $this->surface->rows()));
            return new Layers([new Text('hi', width: 2, height: 1, style: ['align' => 'center'])]);
        }
    };
    mountOn($app, 6, 3, function (FakeTerminal $t) {
        expect($t->text())->toBe("~~~~~~\n~~hi~~\n~~~~~~");
    });
});

it('paints only through write(): the device stream alone reproduces the screen', function () {
    $app = new TreeApp(fn(TreeApp $app) => new Column([
        new Text(['a ', new Span('styled', style: ['color' => '#ff8800', 'underline' => true]), ' text'], style: ['background' => 'blue']),
        new Box(new Text('中文 and emoji 👍'), 'wide', style: ['border' => 'double red']),
    ]));
    mountOn($app, 20, 6, function (FakeTerminal $t, TreeApp $app) {
        $t->refresh();
        $replay = new Surface(20, 6);
        $replay->write($t->output());
        expect(seen($replay))->toBe(seen($app->surface))
            ->and(seen($t->surface))->toBe(seen($app->surface));
    });
});

it('does not give the keys to text: the first window that takes them gets them', function () {
    $app = new TreeApp(fn(TreeApp $app) => new Column([new Text('Name'), $app->get('name', fn() => new TextInput())]));
    mountOn($app, 20, 3, function (FakeTerminal $t, TreeApp $app) {
        $t->type('Ada');
        expect($app->get('name')->value())->toBe('Ada');
    });
});

it('keeps focus and child components through random rebuilds of the tree', function () {
    mt_srand(4242);
    $shape = null;
    $wrap = function (Element $e, int $depth) use (&$wrap): Element {
        if ($depth === 0) {
            return $e;
        }
        $filler = new Text('t' . mt_rand(0, 9));
        $inner = $wrap($e, $depth - 1);
        return match (mt_rand(0, 4)) {
            0 => new Column(mt_rand(0, 1) ? [$filler, $inner] : [$inner, $filler]),
            1 => new Row(mt_rand(0, 1) ? [$filler, $inner] : [$inner, $filler]),
            2 => new Box($inner, 'b' . mt_rand(0, 9)),
            3 => new Layers([$filler, $inner]),
            default => $inner,
        };
    };
    $app = new TreeApp(function (TreeApp $app) use (&$shape, $wrap) {
        $shape = mt_rand(0, 3);
        $input = $app->get('input', fn() => new TextInput());
        $other = $app->get('other', fn() => new TextInput());
        return new Column([
            $wrap($input, $shape),
            $wrap($other, mt_rand(0, 3)),
        ]);
    });
    mountOn($app, 40, 20, function (FakeTerminal $t, TreeApp $app) {
        $input = $app->get('input');
        $other = $app->get('other');
        $input->focus();
        $typed = '';
        for ($i = 0; $i < 60; $i++) {
            $app->requestRender();
            frame();
            if ($i % 7 === 3) {
                $other->focus();
                $t->type('o');
                $input->focus();
            }
            $ch = chr(ord('a') + $i % 26);
            $t->type($ch);
            $typed .= $ch;
            expect($input->hasFocus())->toBeTrue()
                ->and($other->hasFocus())->toBeFalse()
                ->and($app->get('input'))->toBe($input);
        }
        expect($input->value())->toBe($typed)
            ->and($other->value())->toBe(str_repeat('o', 9));
        $t->refresh();
        $replay = new Surface(40, 20);
        $replay->write($t->output());
        expect($replay->text())->toBe($app->surface->text());
    });
});
