<?php

/*
 * 'auto' sizes in a Row or Column: what the content needs, capped by a fair share, so an element
 * that measures large (a one-line Editor's width, a Table's 10,000 rows) does not swallow its
 * siblings.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Editor;
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
