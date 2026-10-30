<?php

use phasync\Tui\Internal\Size;
use phasync\Tui\Pty;
use phasync\Tui\Split;
use phasync\Tui\Stack;
use phasync\Tui\Surface;

use function fiber\run;

/** [x, y, width, height] of each surface's window on $host. */
function windows(Surface $host, Surface ...$surfaces): array
{
    return array_map(fn(Surface $s) => $host->viewport($s)?->rect(), $surfaces);
}

describe('sizes', function () {
    it('gives cells and percentages first and shares the rest by weight, exactly', function () {
        $sizes = array_map(Size::parse(...), [10, '25%', '1fr', '2fr']);
        expect(Size::distribute($sizes, 100))->toBe([10, 25, 22, 43])
            ->and(Size::distribute(array_map(Size::parse(...), ['1fr', '1fr', '1fr']), 10))->toBe([4, 3, 3])
            ->and(Size::distribute(array_map(Size::parse(...), [8, 8]), 10))->toBe([8, 2]);
        expect(fn() => Size::parse('wide'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Split', function () {
    it('puts windows side by side and keeps the surfaces their size', function () {
        $root = new Surface(30, 5);
        [$a, $b, $c] = [new Surface(), new Surface(), new Surface()];
        $root->setLayout(Split::horizontal()->add($a, 10)->add($b)->add($c, '20%'));
        expect(windows($root, $a, $b, $c))->toBe([[0, 0, 10, 5], [10, 0, 14, 5], [24, 0, 6, 5]])
            ->and([$b->cols(), $b->rows()])->toBe([14, 5]);
    });

    it('stacks windows vertically with separators between them', function () {
        $root = new Surface(6, 7);
        [$a, $b] = [vt('top', 6, 3), vt('bottom', 6, 3)];
        $root->setLayout(Split::vertical(separators: true)->add($a)->add($b));
        expect(windows($root, $a, $b))->toBe([[0, 0, 6, 3], [0, 4, 6, 3]])
            ->and($root->text())->toBe("top\n\n\n──────\nbottom\n\n");
    });

    it('re-lays the windows when the host is resized, and nested layouts with it', function () {
        $root = new Surface(20, 4);
        [$left, $right] = [new Surface(), new Surface()];
        [$r1, $r2] = [new Surface(), new Surface()];
        $root->setLayout(Split::horizontal(separators: true)->add($left)->add($right));
        $right->setLayout(Split::vertical()->add($r1)->add($r2));
        expect(windows($root, $left, $right))->toBe([[0, 0, 10, 4], [11, 0, 9, 4]])
            ->and(windows($right, $r1, $r2))->toBe([[0, 0, 9, 2], [0, 2, 9, 2]]);
        $root->resize(31, 6);
        expect(windows($root, $left, $right))->toBe([[0, 0, 15, 6], [16, 0, 15, 6]])
            ->and(windows($right, $r1, $r2))->toBe([[0, 0, 15, 3], [0, 3, 15, 3]])
            ->and($root->cell(15, 5)->char)->toBe('│');
    });

    it('fits a bordered window\'s surface inside the border', function () {
        $root = new Surface(20, 5);
        $a = new Surface();
        $root->host($a)->setBorder(true)->setTitle('a');
        $root->setLayout(Split::horizontal()->add($a));
        expect([$a->cols(), $a->rows()])->toBe([18, 3]);
    });

    it('adds and removes windows while in use, unhosting what it no longer places', function () {
        $root = new Surface(20, 2);
        [$a, $b] = [new Surface(), new Surface()];
        $split = Split::horizontal()->add($a);
        $root->setLayout($split);
        $split->add($b);
        expect(windows($root, $a, $b))->toBe([[0, 0, 10, 2], [10, 0, 10, 2]]);
        $split->remove($a);
        expect(windows($root, $a, $b))->toBe([null, [0, 0, 20, 2]])->and($root->focused())->toBe($b);
    });

    it('resizes the program in a pty with its window', function () {
        run(function () {
            $root = new Surface(40, 6);
            $pane = new Surface();
            $other = new Surface();
            $root->setLayout(Split::horizontal()->add($pane)->add($other));
            $pty = Pty::start('stty size; read line; stty size', $pane);
            for ($i = 0; $i < 200 && !str_contains($pane->text(), '6 20'); $i++) {
                fiber\sleep(0.01);
            }
            $root->resize(60, 8);
            fiber\sleep(0.1);
            $pane->dispatch(phasync\Tui\Key::parse('enter'));
            $pty->wait(5);
            expect($pane->text())->toContain("6 20")->toContain("8 30");
        });
    });
});

describe('Stack', function () {
    it('layers windows and places them by alignment or position', function () {
        $root = new Surface(20, 10);
        [$main, $dialog, $corner] = [new Surface(), new Surface(), new Surface()];
        $stack = (new Stack())->add($main)->add($dialog, 10, 4)->add($corner, '25%', 1, 'bottom-right');
        $root->setLayout($stack);
        expect(windows($root, $main, $dialog, $corner))->toBe([[0, 0, 20, 10], [5, 3, 10, 4], [15, 9, 5, 1]]);
        $stack->add($dialog, 10, 4, x: 1, y: 2);
        expect($root->viewport($dialog)->rect())->toBe([1, 2, 10, 4]);
    });

    it('shows what a removed overlay covered again', function () {
        $root = new Surface(8, 3);
        $main = new Surface();
        $dialog = new Surface();
        $stack = (new Stack())->add($main);
        $root->setLayout($stack);
        $main->write("aaaaaaaa\r\naaaaaaaa\r\naaaaaaaa");
        $stack->add($dialog, 4, 1);
        $dialog->write('OK');
        expect($root->line(1))->toBe('aaOK  aa');
        $stack->remove($dialog);
        expect($root->line(1))->toBe('aaaaaaaa');
    });

    it('keeps the stack order as the z-order', function () {
        $root = new Surface(4, 1);
        [$a, $b] = [vt('aaaa', 4, 1), vt('bb', 2, 1)];
        $root->host($b);
        $root->host($a);
        $root->setLayout((new Stack())->add($a)->add($b, 2, 1, 'left'));
        expect($root->line(0))->toBe('bbaa');
    });
});

describe('nesting', function () {
    it('nests a layout directly, in a surface the outer layout owns', function () {
        $root = new Surface(20, 4);
        [$a, $b, $c] = [new Surface(), new Surface(), new Surface()];
        $inner = Split::vertical()->add($b)->add($c);
        $root->setLayout(Split::horizontal(separators: true)->add($a, 9)->add($inner));
        $group = $inner->host();
        expect($group)->not->toBeNull()
            ->and($group->parent())->toBe($root)
            ->and($b->parent())->toBe($group)
            ->and(windows($root, $a, $group))->toBe([[0, 0, 9, 4], [10, 0, 10, 4]])
            ->and(windows($group, $b, $c))->toBe([[0, 0, 10, 2], [0, 2, 10, 2]]);
        $c->write('c');
        expect($root->text())->toBe("         │\n         │\n         │c\n         │");
        $c->focus();
        expect($root->focusToward('left'))->toBeTrue()->and($a->hasFocus())->toBeTrue()
            ->and($root->focusNext())->toBeTrue()->and($b->hasFocus())->toBeTrue();
    });

    it('keeps the nested surface, its windows and focus when the layouts are built anew', function () {
        $root = new Surface(20, 4);
        [$a, $b, $c] = [new Surface(), new Surface(), new Surface()];
        $build = fn() => Split::horizontal()->add($a)->add(Split::vertical()->add($b)->add($c));
        $root->setLayout($build());
        $c->focus();
        $group = $c->parent();
        $view = $group->viewport($c);
        $root->setLayout($build());
        $root->resize(30, 6);
        $root->setLayout($build());
        expect($c->parent())->toBe($group)->and($group->viewport($c))->toBe($view)
            ->and($c->hasFocus())->toBeTrue()->and(windows($group, $b, $c))->toBe([[0, 0, 15, 3], [0, 3, 15, 3]]);
        // a nested layout the outer one no longer has gives its windows up
        $root->setLayout(Split::horizontal()->add($a));
        expect($group->parent())->toBeNull()->and($c->parent())->toBeNull();
    });

    it('nests a layout in a stack, as a dialog of several windows', function () {
        $root = new Surface(20, 6);
        [$main, $q, $answer] = [new Surface(), vt('Save?', 10, 1), new Surface()];
        $root->setLayout((new Stack())->add($main)->add(Split::vertical()->add($q, 1)->add($answer), 10, 2));
        $answer->write('yes');
        expect($root->line(2))->toBe('     Save?')->and($root->line(3))->toBe('     yes')
            ->and($answer->area()->x)->toBe(5);
    });
});
