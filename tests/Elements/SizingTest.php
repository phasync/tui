<?php

/*
 * 'auto' sizes in a Row or Column: what the content needs, capped by a fair share, so an element
 * that measures large (a one-line Editor's width, a Table's 10,000 rows) does not swallow its
 * siblings.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Checkbox;
use phasync\Tui\Component\Editor;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\Select;
use phasync\Tui\Component\Table;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\Size;

it('gives a one-line Editor without a width the room left beside a fixed Text', function () {
    $app = new class () extends Component {
        protected function render(): Element
        {
            return new Row([$this->child('e', fn() => new Editor('typed', maxLines: 1)), new Text('twenty columns here.', width: 20)]);
        }
    };
    mountOn($app, 60, 3, function (FakeTerminal $t) {
        frame();
        expect(explode("\n", $t->text())[0])->toBe('typed' . str_repeat(' ', 35) . 'twenty columns here.');
    });
});

it('keeps the Help line under a Table of many rows that has no height', function () {
    $app = new class () extends Component {
        protected function bindings(): array
        {
            return ['ctrl+q' => ['quit', 'quit']];
        }

        public function quit(): void
        {
        }

        protected function render(): Element
        {
            $rows = [];
            for ($i = 0; $i < 10000; $i++) {
                $rows[$i] = ["row $i"];
            }
            return new Column([$this->child('t', fn() => new Table(['Name' => '1fr'], $rows)), new Help()]);
        }
    };
    mountOn($app, 30, 8, function (FakeTerminal $t) {
        frame();
        $lines = explode("\n", $t->text());
        expect($lines[0])->toContain('Name')->and($lines[6])->toContain('row 5')->and($lines[7])->toContain('ctrl+q quit');
    });
});

it('shares the room between auto items that need more than their share and fr items', function () {
    // fixed first; an auto item that fits its share gets what it needs; the rest as weights
    expect(Size::distribute([['auto', 3.0], ['fr', 1.0]], 24))->toBe([3, 21])
        ->and(Size::distribute([['auto', 10000.0], ['auto', 1.0]], 24))->toBe([23, 1])
        ->and(Size::distribute([['auto', 80.0], ['cells', 20.0]], 80))->toBe([60, 20])
        ->and(Size::distribute([['auto', 50.0], ['fr', 1.0]], 24))->toBe([12, 12])
        ->and(Size::distribute([['auto', 50.0], ['fr', 2.0]], 30))->toBe([10, 20])
        ->and(Size::distribute([['auto', 2.0], ['auto', 3.0]], 24))->toBe([2, 3])
        ->and(Size::distribute([['cells', 30.0], ['auto', 5.0]], 24))->toBe([24, 0]);
});

it('sizes a container without a height by its children: a toolbar Row of one-line fields is one row', function () {
    $app = new class () extends Component {
        protected function render(): Element
        {
            return new Column([
                new Row([
                    $this->child('a', fn() => new Editor('first', maxLines: 1)),
                    $this->child('b', fn() => new Editor('second', maxLines: 1)),
                    $this->child('c', fn() => new Select(['x' => 'pick'], dropdown: true)),
                ], gap: 1),
                $this->log(),
                new Text('status'),
            ]);
        }

        public function log(): Log
        {
            return $this->child('log', fn() => new Log());
        }
    };
    mountOn($app, 60, 20, function (FakeTerminal $t, Component $app) {
        $app->log()->append("log line\n");
        frame();
        $lines = explode("\n", $t->text());
        expect($lines[0])->toStartWith('first')->toContain('second')->toContain('pick')
            ->and($lines[1])->toBe('log line')        // one row: the Log right under it takes the rest
            ->and($lines[19])->toBe('status');
    });
});

it('sizes a Column of one-row Texts and a Column of row components without explicit sizes', function () {
    $app = new class () extends Component {
        protected function render(): Element
        {
            return new Column([
                new Column([new Text('one'), new Text('two')]),
                new Column([
                    $this->child('x', fn() => new Checkbox('first')),
                    $this->child('y', fn() => new Checkbox('second')),
                ]),
                new Text('after'),
                $this->child('rest', fn() => new Log()),
            ]);
        }
    };
    mountOn($app, 30, 12, function (FakeTerminal $t) {
        frame();
        $lines = explode("\n", $t->text());
        expect($lines[0])->toBe('one')->and($lines[1])->toBe('two')
            ->and($lines[2])->toContain('first')->and($lines[3])->toContain('second')
            ->and($lines[4])->toBe('after');
    });
});

it('gives a container a share of the room when a child takes any room', function () {
    $app = new class () extends Component {
        protected function render(): Element
        {
            return new Column([
                new Row([new Text('left'), $this->child('log', fn() => new Log())]),
                new Text('under'),
            ]);
        }
    };
    mountOn($app, 30, 10, function (FakeTerminal $t) {
        frame();
        expect(explode("\n", $t->text())[9])->toBe('under');
    });
});

it('caps an auto size with max-height and max-width', function () {
    $app = new class () extends Component {
        protected function render(): Element
        {
            return new Column([
                new Text("1\n2\n3\n4\n5", style: ['max-height' => 2]),
                $this->child('log', fn() => (new Log())->with(style: ['max-height' => 3])),
                new Row([new Text(str_repeat('w', 30), wrap: false, style: ['max-width' => 5]), new Text('|')]),
                new Text('end'),
            ]);
        }
    };
    mountOn($app, 40, 12, function (FakeTerminal $t) {
        frame();
        $lines = explode("\n", $t->text());
        expect($lines[0])->toBe('1')->and($lines[1])->toBe('2')->and($lines[2])->toBe('')
            ->and($lines[5])->toStartWith('wwww…|')     // cut to 5 columns
            ->and($lines[6])->toBe('end');
    });
});
