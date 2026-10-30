<?php

/*
 * The surfaces form a tree: every surface knows its host, its viewport and where it shows on the
 * root; focus is a property of that tree, whatever kind of host is in between.
 */

use phasync\Tui\Focus;
use phasync\Tui\Rect;
use phasync\Tui\Split;
use phasync\Tui\Stack;
use phasync\Tui\Surface;

/** root 40x10: left 20 columns | right, split into top and bottom halves, in a plain surface. */
function tree(): array
{
    $root = new Surface(40, 10);
    $left = new Surface();
    $group = new Surface();
    $top = new Surface();
    $bottom = new Surface();
    $root->setLayout(Split::horizontal()->add($left, 20)->add($group));
    $group->setLayout(Split::vertical()->add($top)->add($bottom));
    return [$root, $left, $group, $top, $bottom];
}

it('knows its parent, its viewport, its root and its area on the root', function () {
    [$root, $left, $group, $top, $bottom] = tree();
    expect($bottom->parent())->toBe($group)
        ->and($group->parent())->toBe($root)
        ->and($root->parent())->toBeNull()
        ->and($bottom->root())->toBe($root)
        ->and($bottom->viewport())->toBe($group->viewport($bottom))
        ->and($root->viewport())->toBeNull()
        ->and($bottom->area())->toEqual(new Rect(20, 5, 20, 5))
        ->and($left->area())->toEqual(new Rect(0, 0, 20, 10))
        ->and($root->area())->toEqual(new Rect(0, 0, 40, 10));
    $root->viewport($group)->setBorder(true);
    expect($top->area())->toEqual(new Rect(21, 1, 18, 4));
    $root->unhost($left);
    expect($left->parent())->toBeNull()->and($left->root())->toBe($left);
});

it('clips the area by every window on the way up', function () {
    $root = new Surface(30, 10);
    $mid = new Surface(20, 20);
    $leaf = new Surface(10, 4);
    $root->host($mid, 5, 2, 10, 5);         // shows mid's rows 15..19 (it follows the bottom)
    $mid->host($leaf, 0, 16, 10, 4);
    expect($leaf->area())->toEqual(new Rect(5, 3, 10, 4));
    $mid->viewport($leaf)->move(12, 16);    // outside the window on the root
    expect($leaf->area()->isEmpty())->toBeTrue();
});

it('focuses through plain surfaces from the root down, and knows when it has the focus', function () {
    [$root, $left, $group, $top, $bottom] = tree();
    $events = [];
    $bottom->on('focus', function (Focus $f) use (&$events) { $events[] = $f->focused; });
    expect($left->hasFocus())->toBeTrue()->and($bottom->hasFocus())->toBeFalse();
    $bottom->focus();
    expect($root->focused())->toBe($group)->and($group->focused())->toBe($bottom)
        ->and($bottom->hasFocus())->toBeTrue()->and($group->hasFocus())->toBeTrue()
        ->and($left->hasFocus())->toBeFalse()->and($top->hasFocus())->toBeFalse()
        ->and($events)->toBe([true]);
    $root->dispatch(new phasync\Tui\Key('x'));
    expect($bottom->input->read())->toBe('x');
    $left->focus();
    expect($events)->toBe([true, false])->and($bottom->hasFocus())->toBeFalse();
});

it('applies a focus asked for before the surface was shown, the latest request winning', function () {
    $root = new Surface(20, 4);
    $a = new Surface();
    $b = new Surface();
    $c = new Surface();
    $b->focus();
    $a->focus();
    $root->setLayout(Split::horizontal()->add($a)->add($b)->add($c));
    expect($root->focused())->toBe($a);
    // through a host that is not shown yet either
    $root2 = new Surface(20, 4);
    $inner = new Surface();
    $x = new Surface();
    $y = new Surface();
    $inner->setLayout(Split::vertical()->add($x)->add($y));
    $y->focus();
    $other = new Surface();
    $root2->setLayout(Split::horizontal()->add($other)->add($inner));
    expect($root2->focused())->toBe($inner)->and($y->hasFocus())->toBeTrue();
});

it('keeps the keys on a host itself with takeFocus()', function () {
    $root = new Surface(20, 5);
    $popup = new Surface(5, 2);
    $root->host($popup, 2, 2);
    expect($root->focused())->toBe($popup);
    $root->takeFocus();
    expect($root->focused())->toBeNull()->and($popup->hasFocus())->toBeFalse();
    $root->dispatch(new phasync\Tui\Key('q'));
    expect($root->input->read())->toBe('q');
    $root->host(new Surface(3, 1), 0, 0);        // a new window does not take the keys away
    expect($root->focused())->toBeNull();
    $popup->focus();
    expect($root->focused())->toBe($popup);
});

it('moves the focus by direction, from the geometry of the windows', function () {
    //  a | b
    //  --+---
    //  c | d | e(top-right only)
    $root = new Surface(30, 10);
    [$a, $b, $c, $d, $e] = [new Surface(), new Surface(), new Surface(), new Surface(), new Surface()];
    $upper = Split::horizontal()->add($a)->add($b);
    $lower = Split::horizontal()->add($c)->add($d)->add($e);
    $root->setLayout(Split::vertical()->add($upper)->add($lower));
    $a->focus();
    expect($root->focusToward('right'))->toBeTrue()->and($b->hasFocus())->toBeTrue()
        ->and($root->focusToward('right'))->toBeFalse()->and($b->hasFocus())->toBeTrue()
        ->and($root->focusToward('down'))->toBeTrue();
    // b spans columns 15..29; below it lie d (10..19) and e (20..29): both alongside, d is nearer the centre
    expect($d->hasFocus() || $e->hasFocus())->toBeTrue();
    $e->focus();
    expect($root->focusToward('left'))->toBeTrue()->and($d->hasFocus())->toBeTrue()
        ->and($root->focusToward('left'))->toBeTrue()->and($c->hasFocus())->toBeTrue()
        ->and($root->focusToward('up'))->toBeTrue()->and($a->hasFocus())->toBeTrue()
        ->and($root->focusToward('up'))->toBeFalse()
        ->and(fn() => $root->focusToward('sideways'))->toThrow(InvalidArgumentException::class);
});

it('tells listeners when a surface is closed', function () {
    $s = new Surface(10, 2);
    $closed = 0;
    $s->on('close', function () use (&$closed) { $closed++; });
    $s->close();
    expect($closed)->toBe(1);
});

it('focuses on click through nested layouts when set on the root', function () {
    $root = new Surface(20, 4);
    [$a, $b, $c] = [new Surface(), new Surface(), new Surface()];
    $root->setLayout(Split::horizontal()->add($a)->add(Split::vertical()->add($b)->add($c)));
    $root->setFocusOnClick(true);
    $click = function (int $x, int $y) use ($root) {
        $root->dispatch(new phasync\Tui\Mouse(phasync\Tui\Mouse::PRESS, $x, $y, phasync\Tui\Mouse::LEFT));
        $root->dispatch(new phasync\Tui\Mouse(phasync\Tui\Mouse::RELEASE, $x, $y, phasync\Tui\Mouse::LEFT));
    };
    $click(15, 3);
    expect($c->hasFocus())->toBeTrue();
    $click(2, 0);
    expect($a->hasFocus())->toBeTrue();
    $c->parent()->setFocusOnClick(false);
    $click(15, 0);
    expect($b->hasFocus())->toBeFalse()->and($c->parent()->hasFocus())->toBeTrue();
});

describe('focus follows the surface through re-arrangements', function () {
    /** The layout of an app: a | (b over c), as nested layouts. */
    $plain = fn(array $s) => Split::horizontal()->add($s['a'])->add(Split::vertical()->add($s['b'])->add($s['c']));
    /** The same with a dialog over it: the plain layout is one level deeper now. */
    $modal = fn(array $s) => (new Stack())->add($plain($s))->add($s['dialog'], 20, 4);

    $surfaces = function (): array {
        $s = ['events' => new ArrayObject()];
        foreach (['a', 'b', 'c', 'dialog'] as $name) {
            $s[$name] = new Surface();
            $s[$name]->on('focus', function (Focus $f) use ($s, $name) {
                $s['events'][] = ($f->focused ? '+' : '-') . $name;
            });
        }
        return $s;
    };

    it('keeps the focused surface focused when it moves into a nested layout and back', function () use ($plain, $modal, $surfaces) {
        $root = new Surface(60, 12);
        $s = $surfaces();
        $root->setLayout($plain($s));
        $s['b']->focus();
        $root->setLayout($modal($s));           // b is two levels deeper now, in new surfaces
        expect($s['b']->hasFocus())->toBeTrue();
        $root->setLayout($plain($s));
        $root->dispatch(new phasync\Tui\Key('x'));
        expect($s['b']->hasFocus())->toBeTrue()->and($s['b']->input->read())->toBe('x');
    });

    it('gives the focus back to the surface focused before, once the dialog is gone', function () use ($plain, $modal, $surfaces) {
        $root = new Surface(60, 12);
        $s = $surfaces();
        $root->setLayout($plain($s));
        $s['c']->focus();
        $root->setLayout($modal($s));
        $s['dialog']->focus();
        $root->dispatch(new phasync\Tui\Key('y'));
        expect($s['dialog']->input->read())->toBe('y');
        $root->setLayout($plain($s));          // the dialog is gone
        $root->dispatch(new phasync\Tui\Key('z'));
        expect($s['c']->hasFocus())->toBeTrue()->and($s['c']->input->read())->toBe('z')
            ->and($s['events']->getArrayCopy())->toBe(['+a', '-a', '+c', '-c', '+dialog', '-dialog', '+c']);
    });

    it('lets a focus() made before the re-arrangement win over it', function () use ($plain, $modal, $surfaces) {
        $root = new Surface(60, 12);
        $s = $surfaces();
        $root->setLayout($modal($s));
        $s['dialog']->focus();
        $s['b']->focus();                       // asked for while the dialog is still up
        $root->setLayout($plain($s));
        $root->dispatch(new phasync\Tui\Key('k'));
        expect($s['b']->hasFocus())->toBeTrue()->and($s['b']->input->read())->toBe('k')
            ->and($s['dialog']->input->peek())->toBe('');
    });

    it('goes to the first window when the focused one goes and none was focused before', function () {
        $root = new Surface(30, 4);
        [$a, $b, $c] = [new Surface(), new Surface(), new Surface()];
        $split = Split::horizontal()->add($a)->add($b)->add($c);
        $root->setLayout($split);
        $c->focus();
        $split->remove($c);
        expect($root->focused())->toBe($a);
        $b->focus();
        $split->add($c);
        $c->focus();
        $split->remove($c);
        expect($root->focused())->toBe($b);     // the one focused before
    });
});
