<?php

/*
 * Tree through the terminal: the keys and the mouse, expanding and collapsing, lazy children
 * (sync and awaitable), type-to-find, marks, guides, styling, setItems() by key, wide labels
 * and a hundred thousand nodes.
 */

use fiber\Deferred;
use phasync\Tui\Component\Tree;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;

function files(array $extra = []): array
{
    return [
        ['label' => 'src', 'expanded' => true, 'children' => [
            ['label' => 'Component', 'children' => [['label' => 'Tree.php'], ['label' => 'Table.php']]],
            ['label' => 'Surface.php'],
        ]],
        ['label' => 'tests', 'children' => [['label' => 'TreeTest.php', 'value' => 42]]],
        ['label' => 'README.md', 'icon' => '*'],
        ...$extra,
    ];
}

function lines(FakeTerminal $t): array
{
    return array_map('rtrim', explode("\n", $t->text()));
}

it('draws nodes with markers and guide lines, and without guides', function () {
    mountOn(new Tree(files()), 24, 6, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        expect(lines($t))->toBe(['▾ src', '├─ ▸ Component', '└─   Surface.php', '▸ tests', '  * README.md', '']);
        $tree->guides = false;
        $tree->requestRender();
        $t->settle();
        expect(lines($t)[1])->toBe('  ▸ Component')->and(lines($t)[2])->toBe('    Surface.php');
    });
});

it('draws continuation lines under open siblings', function () {
    mountOn(new Tree([['label' => 'a', 'expanded' => true, 'children' => [
        ['label' => 'b', 'expanded' => true, 'children' => [['label' => 'c']]], ['label' => 'd'],
    ]]]), 20, 4, function (FakeTerminal $t) {
        $t->settle();
        expect(lines($t))->toBe(['▾ a', '├─ ▾ b', '│  └─   c', '└─   d']);
    });
});

it('moves with Up, Down, Home, End, PageUp and PageDown and shows the current node inverted', function () {
    $seen = new ArrayObject();
    mountOn(new Tree(files(), onSelect: fn($k) => $seen[] = $k), 24, 3, function (FakeTerminal $t, Tree $tree) use ($seen) {
        $t->settle();
        expect($tree->current())->toBe('src')->and($tree->previous())->toBeFalse();
        $t->press('down', 'down');
        expect($tree->current())->toBe('src/Surface.php')
            ->and($t->dump())->toContain('inverse');
        $t->press('end');
        expect($tree->current())->toBe('README.md');
        $t->settle();
        expect($tree->scrollTop())->toBe(2)->and(lines($t)[2])->toBe('  * README.md');
        $t->press('pageup');
        expect($tree->current())->toBe('src/Surface.php');
        $t->press('pagedown', 'pagedown');
        expect($tree->current())->toBe('README.md');
        $t->press('home');
        expect($tree->current())->toBe('src')->and($seen->getArrayCopy())->toContain('src/Component', 'README.md');
        $t->press('ctrl+n');                // not a default: added with bind()
        expect($tree->current())->toBe('src');
        $tree->bind(['ctrl+n' => 'next', 'ctrl+p' => 'previous']);
        $t->press('ctrl+n');
        expect($tree->current())->toBe('src/Component');
        $t->press('ctrl+p');
        expect($tree->current())->toBe('src');
    });
});

it('opens with Right (then goes to the first child), closes with Left (then goes to the parent)', function () {
    $log = new ArrayObject();
    mountOn(new Tree(files(), onExpand: fn($k) => $log[] = "+$k", onCollapse: fn($k) => $log[] = "-$k"), 24, 8, function (FakeTerminal $t, Tree $tree) use ($log) {
        $t->settle();
        $t->press('right');                         // src is open: first child
        expect($tree->current())->toBe('src/Component');
        $t->press('right');
        expect($tree->isExpanded('src/Component'))->toBeTrue()->and($tree->visibleCount())->toBe(7);
        $t->press('right', 'right');                // Tree.php, a leaf: stays
        expect($tree->current())->toBe('src/Component/Tree.php');
        $t->press('left');                          // leaf: to the parent
        expect($tree->current())->toBe('src/Component');
        $t->press('left');                          // open: close
        expect($tree->isExpanded('src/Component'))->toBeFalse()->and($tree->visibleCount())->toBe(5);
        $t->press('left', 'left');                  // parent, then close src
        expect($tree->current())->toBe('src')->and($tree->visibleCount())->toBe(3);
        $t->press('left');                          // closed top-level: nothing, the key goes on
        expect($log->getArrayCopy())->toBe(['+src/Component', '-src/Component', '-src']);
    });
});

it('closing a node that holds the current one makes the closed node current', function () {
    $seen = new ArrayObject();
    mountOn(new Tree(files(), onSelect: fn($k) => $seen[] = $k), 24, 8, function (FakeTerminal $t, Tree $tree) use ($seen) {
        $t->settle();
        $t->press('down', 'down');
        $tree->collapse('src');
        expect($tree->current())->toBe('src')->and($seen->getArrayCopy())->toBe(['src/Component', 'src/Surface.php', 'src']);
    });
});

it('opens and closes with Enter (activating) and Space', function () {
    $act = new ArrayObject();
    mountOn(new Tree(files(), onActivate: fn($k) => $act[] = $k), 24, 8, function (FakeTerminal $t, Tree $tree) use ($act) {
        $t->settle();
        $t->press('enter');
        expect($tree->isExpanded('src'))->toBeFalse();
        $t->press('space');
        expect($tree->isExpanded('src'))->toBeTrue();
        $t->press('down', 'down', 'enter');          // a leaf: only activates
        expect($act->getArrayCopy())->toBe(['src', 'src/Surface.php']);
    });
});

it('emits the events select, expand, collapse and activate to listeners', function () {
    $events = new ArrayObject();
    $tree = new Tree(files());
    foreach (['select', 'expand', 'collapse', 'activate'] as $e) {
        $tree->on($e, fn($k) => $events[] = "$e:$k");
    }
    mountOn($tree, 24, 8, function (FakeTerminal $t) use ($events) {
        $t->settle();
        $t->press('down', 'right', 'enter', 'enter');
        expect($events->getArrayCopy())->toBe(['select:src/Component', 'expand:src/Component', 'collapse:src/Component', 'activate:src/Component', 'expand:src/Component', 'activate:src/Component']);
    });
});

it('clicks: a row becomes current, the marker opens and closes, the wheel scrolls', function () {
    mountOn(new Tree(files()), 24, 3, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        $t->click(8, 2);
        expect($tree->current())->toBe('src/Surface.php');
        $t->click(3, 1);                              // the ▸ of Component (x = 3)
        expect($tree->isExpanded('src/Component'))->toBeTrue()->and($tree->current())->toBe('src/Component');
        $t->click(3, 1);
        expect($tree->isExpanded('src/Component'))->toBeFalse();
        $t->click(10, 1);                             // the label: no toggle
        expect($tree->isExpanded('src/Component'))->toBeFalse();
        $t->click(0, 0);                              // src's own marker
        expect($tree->isExpanded('src'))->toBeFalse()->and($tree->visibleCount())->toBe(3);
        $tree->expand('src');
        $tree->expand('src/Component');
        $t->settle();
        $t->mouse(new Mouse(Mouse::WHEEL_DOWN, 2, 1));
        $t->settle();
        expect($tree->scrollTop())->toBe(3)->and($tree->current())->toBe('src');
        $t->mouse(new Mouse(Mouse::WHEEL_UP, 2, 1));
        $t->mouse(new Mouse(Mouse::WHEEL_UP, 2, 1));
        $t->settle();
        expect($tree->scrollTop())->toBe(0);
    });
});

it('types to find: next label starting with the letters, wrapping, repeated letter cycles', function () {
    mountOn(new Tree([['label' => 'apple'], ['label' => 'Avocado'], ['label' => 'banana'], ['label' => 'blueberry'], ['label' => 'cherry']]), 20, 5, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        $t->type('b');
        expect($tree->current())->toBe('banana');
        $t->type('l');                                 // "bl" within a second
        expect($tree->current())->toBe('blueberry');
        $t->type('x');                                 // no match: stays
        expect($tree->current())->toBe('blueberry');
        usleep(1_100_000);
        $t->type('a');
        expect($tree->current())->toBe('apple');
        usleep(1_100_000);
        $t->type('a');
        expect($tree->current())->toBe('Avocado');
        usleep(1_100_000);
        $t->type('a');
        expect($tree->current())->toBe('apple');
        $t->type('A');                                 // "aA" = same letter again
        expect($tree->current())->toBe('Avocado');
    });
});

it('marks with Space and Ctrl-click when multiple, in tree order, with the selected state', function () {
    $changes = new ArrayObject();
    mountOn(new Tree(files(), multiple: true, onChange: fn($k) => $changes[] = $k), 24, 8, function (FakeTerminal $t, Tree $tree) use ($changes) {
        $t->settle();
        $t->press('space', 'down', 'down', 'space');
        $t->settle();
        expect($tree->selection())->toBe(['src', 'src/Surface.php'])->and($tree->isExpanded('src'))->toBeTrue()
            ->and($t->dump())->toContain('bold');
        $t->mouse(new Mouse(Mouse::PRESS, 8, 3, Mouse::LEFT, ctrl: true));
        expect($tree->selection())->toBe(['src', 'src/Surface.php', 'tests']);
        $t->press('up', 'space');
        expect($tree->selection())->toBe(['src', 'tests'])->and(\count($changes))->toBe(4);
        $tree->setSelection(['README.md', 'nope']);
        expect($tree->selection())->toBe(['README.md']);
    });
});

it('without multiple the selection is the current node', function () {
    mountOn(new Tree(files()), 24, 8, function (FakeTerminal $t, Tree $tree) {
        $t->press('down');
        expect($tree->selection())->toBe(['src/Component']);
    });
});

it('loads children lazily and keeps them when closed and opened again', function () {
    $calls = new ArrayObject();
    $tree = new Tree(
        [['label' => 'root'], ['label' => 'file', 'leaf' => true]],
        load: function (array $node) use ($calls) {
            $calls[] = $node['key'];
            return [['label' => 'child of ' . $node['label'], 'leaf' => true]];
        },
    );
    mountOn($tree, 30, 5, function (FakeTerminal $t) use ($tree, $calls) {
        $t->settle();
        expect(lines($t)[0])->toBe('▸ root')->and(lines($t)[1])->toBe('  file');
        $t->press('right');
        $t->settle();
        expect(lines($t))->toBe(['▾ root', '└─   child of root', '  file', '', ''])->and($calls->getArrayCopy())->toBe(['root']);
        $t->press('left', 'right');
        expect(\count($calls))->toBe(1);
        $t->press('down', 'down');                    // file: a leaf
        expect($tree->expand('file'))->toBeFalse()->and($tree->isBranch('file'))->toBeFalse();
        $t->press('home');
        $tree->reload('root');
        expect(\count($calls))->toBe(2)->and($tree->visibleCount())->toBe(3);
    });
});

it('loads through an awaitable with a spinner on the node, and lets the tree be used meanwhile', function () {
    $deferred = new Deferred();
    $tree = new Tree([['label' => 'slow'], ['label' => 'other', 'leaf' => true]], load: fn() => $deferred);
    mountOn($tree, 30, 4, function (FakeTerminal $t) use ($tree, $deferred) {
        $t->settle();
        $t->press('right');
        $t->settle();
        expect($tree->isLoading('slow'))->toBeTrue()->and(lines($t)[0])->toMatch('/^[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏] slow$/u');
        $first = lines($t)[0];
        \fiber\sleep(0.3);
        $t->settle();
        expect(lines($t)[0])->not->toBe($first);        // it spins
        $t->press('down');                              // usable while loading
        expect($tree->current())->toBe('other');
        $deferred->resolve([['label' => 'one', 'leaf' => true], ['label' => 'two', 'leaf' => true]]);
        \fiber\sleep(0.05);
        $t->settle();
        expect($tree->isLoading('slow'))->toBeFalse()
            ->and(lines($t))->toBe(['▾ slow', '├─   one', '└─   two', '  other']);
    });
});

it('closes again and tries again when loading fails', function () {
    $n = 0;
    $tree = new Tree([['label' => 'bad']], load: function () use (&$n) {
        $d = new Deferred();
        ++$n === 1 ? $d->reject(new RuntimeException('no')) : $d->resolve([['label' => 'ok', 'leaf' => true]]);
        return $d;
    });
    mountOn($tree, 30, 3, function (FakeTerminal $t) use ($tree) {
        $t->settle();
        $t->press('right');
        \fiber\sleep(0.05);
        $t->settle();
        expect($tree->hasFailed('bad'))->toBeTrue()->and($tree->isExpanded('bad'))->toBeFalse()->and(lines($t)[0])->toBe('▸ bad');
        $t->press('right');
        \fiber\sleep(0.05);
        $t->settle();
        expect($tree->hasFailed('bad'))->toBeFalse()->and(lines($t)[1])->toBe('└─   ok');
    });
});

it('loads the children of a node that was open from the start', function () {
    $tree = new Tree([['label' => 'r', 'expanded' => true]], load: fn() => [['label' => 'x', 'leaf' => true]]);
    mountOn($tree, 20, 3, function (FakeTerminal $t) {
        $t->settle();
        expect(lines($t)[1])->toBe('└─   x');
    });
});

it('keeps expansion, marks, current node and loaded children by key through setItems', function () {
    $tree = new Tree(files(), multiple: true, load: fn() => [['label' => 'lazy child', 'leaf' => true]]);
    mountOn($tree, 30, 10, function (FakeTerminal $t) use ($tree) {
        $t->settle();
        $tree->setCurrent('tests/TreeTest.php');
        $tree->expand();           // lazy (load given, no children)
        $tree->setCurrent('tests/TreeTest.php/lazy child');
        $t->press('space');
        $tree->setItems([['label' => 'new', 'expanded' => true], ...files(), ['label' => 'zzz']]);
        $t->settle();
        expect($tree->current())->toBe('tests/TreeTest.php/lazy child')
            ->and($tree->selection())->toBe(['tests/TreeTest.php/lazy child'])
            ->and($tree->isExpanded('src'))->toBeTrue()->and($tree->isExpanded('tests'))->toBeTrue()
            ->and($tree->label('tests/TreeTest.php/lazy child'))->toBe('lazy child');
        $tree->setItems([['label' => 'src', 'children' => [['label' => 'Surface.php']]]]);
        $t->settle();
        expect($tree->current())->toBe('src')->and($tree->selection())->toBe([])->and($tree->visibleCount())->toBe(2);      // src stayed open
    });
});

it('reveals a node with setCurrent(), opening what is above it', function () {
    mountOn(new Tree(files()), 30, 3, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        expect($tree->setCurrent('src/Component/Table.php'))->toBeTrue()->and($tree->isExpanded('src/Component'))->toBeTrue()
            ->and($tree->setCurrent('missing'))->toBeFalse();
        $t->settle();
        expect(lines($t)[2])->toBe('│  └─   Table.php');
    });
});

it('rejects duplicate keys', function () {
    new Tree([['label' => 'a'], ['label' => 'a']]);
})->throws(InvalidArgumentException::class);

it('cuts wide labels, keeps wide characters whole and shows icons', function () {
    mountOn(new Tree([['label' => '日本語のとても長いラベル', 'icon' => '📁'], ['label' => 'a😀b😀c😀d', 'icon' => '']]), 14, 2, function (FakeTerminal $t) {
        $t->settle();
        $l = lines($t);
        expect($l[0])->toBe('  📁 日本語の…')->and(mb_strwidth($l[0]))->toBeLessThanOrEqual(14)
            ->and($l[1])->toBe('  a😀b😀c😀d');
    });
    mountOn(new Tree([['label' => 'x', 'expanded' => true, 'children' => [['label' => 'y', 'expanded' => true, 'children' => [['label' => 'deep label here']]]]]]), 8, 3, function (FakeTerminal $t) {
        $t->settle();
        expect(mb_strwidth(lines($t)[2]))->toBeLessThanOrEqual(8);
    });
});


it('can be styled through parts of the stylesheet', function () {
    $tree = new Tree(files());
    mountOn($tree, 24, 4, function (FakeTerminal $t) use ($tree) {
        $tree->setStylesheet(\phasync\Tui\Stylesheet::default()->with([
            'Tree::node:current' => ['background' => 'red'],
            'Tree::guide' => ['color' => 'green'],
            'Tree::marker' => ['color' => 'yellow'],
        ]));
        $t->press('down', 'down');
        $t->settle();
        expect($t->dump())->toContain('fg=2]├─')->toContain('fg=3]▾')->toContain('bg=1');
    });
});

it('does nothing when disabled and on an empty tree', function () {
    mountOn(new Tree([]), 10, 2, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        $t->press('down', 'up', 'enter', 'space', 'right', 'left', 'a');
        $t->click(1, 1);
        expect($tree->current())->toBeNull()->and($tree->selection())->toBe([])->and($t->text())->toBe("\n");
    });
});

it('handles 100000 nodes: only the rows in view are drawn, and keys stay fast', function () {
    $items = [];
    for ($i = 0; $i < 100; $i++) {
        $children = [];
        for ($j = 0; $j < 1000; $j++) {
            $children[] = ['label' => "leaf $i.$j"];
        }
        $items[] = ['label' => "group $i", 'children' => $children];
    }
    $t0 = microtime(true);
    $tree = new Tree($items);
    $built = microtime(true) - $t0;
    expect($built)->toBeLessThan(2.0);
    mountOn($tree, 40, 10, function (FakeTerminal $t, Tree $tree) {
        $t->settle();
        $t0 = microtime(true);
        $t->press('right', 'down', 'pagedown', 'end', 'home');
        $tree->setCurrent('group 50/leaf 50.999');
        $t->settle();
        $dt = microtime(true) - $t0;
        expect($tree->visibleCount())->toBe(2100)
            ->and(lines($t)[9])->toContain('leaf 50.999')
            ->and($dt)->toBeLessThan(1.0);
        // expanding every group: 100100 rows
        $t0 = microtime(true);
        for ($i = 0; $i < 100; $i += 1) {
            $tree->expand("group $i");
        }
        $t->press('end');
        $t->settle();
        expect($tree->visibleCount())->toBe(100100)->and(lines($t)[9])->toContain('leaf 99.999')
            ->and(microtime(true) - $t0)->toBeLessThan(10.0);
        $t0 = microtime(true);
        $t->press('pagedown', 'pageup', 'up', 'down');
        $t->settle();
        expect(microtime(true) - $t0)->toBeLessThan(0.5);
    });
});
