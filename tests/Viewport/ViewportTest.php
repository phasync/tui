<?php

use phasync\Tui\Color;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Text;
use phasync\Tui\Viewport;

it('paints the source onto the target at its position', function () {
    $src = vt("ab\e[31mc\e[0m\r\nde", 3, 2);
    $target = new Surface(8, 4);
    (new Viewport($src, 2, 1))->paint($target);
    expect(rows($target))->toBe('        |  abc   |  de    |        ')
        ->and($target->cell(4, 1)->style)->toBe(Style::of(Color::RED));
});

it('draws a border and a title around the inner area', function () {
    $src = vt("hi", 4, 2);
    $target = new Surface(8, 5);
    $view = (new Viewport($src, 0, 0, 6, 4))->setBorder(true)->setTitle('t');
    expect($view->innerWidth())->toBe(4)->and($view->innerHeight())->toBe(2);
    $view->paint($target);
    expect($target->text())->toBe("┌ t ─┐\n│hi  │\n│    │\n└────┘\n");
});

it('truncates a title that does not fit', function () {
    $target = new Surface(8, 3);
    (new Viewport(new Surface(4, 1), 0, 0, 6, 3))->setBorder(true)->setTitle('a long title')->paint($target);
    expect($target->line(0))->toBe('┌ a l┐');
});

it('clips at the edges of the target', function () {
    $src = vt("abcd\r\nefgh", 4, 2);
    $target = new Surface(3, 2);
    (new Viewport($src, -1, 1))->paint($target);
    expect(rows($target))->toBe('   |bcd');
});

it('replaces a wide character cut by the inner edges with a blank', function () {
    $src = vt("中文字", 6, 1);
    $target = new Surface(6, 1);
    (new Viewport($src, 0, 0, 3, 1))->paint($target);
    expect($target->line(0))->toBe('中');
    $target = new Surface(6, 1);
    (new Viewport($src, 0, 0, 4, 1))->pan(1)->paint($target);
    expect($target->line(0))->toBe(' 文');
});

it('follows the bottom of a source taller than the view', function () {
    $src = vt(implode("\r\n", range(1, 6)), 3, 6);
    $target = new Surface(3, 2);
    $view = new Viewport($src, 0, 0, 3, 2);
    expect($view->top())->toBe(4)->and($view->isFollowing())->toBeTrue();
    $view->paint($target);
    expect(rows($target))->toBe('5  |6  ');
});

it('scrolls back into the scrollback and stays on the same text as output continues', function () {
    $src = vt(implode("\r\n", range(1, 6)), 3, 2);   // rows "5","6"; scrollback 1..4
    $view = new Viewport($src);
    $view->scrollBy(-3);
    expect($view->top())->toBe(-3)->and($view->isFollowing())->toBeFalse();
    $target = new Surface(3, 2);
    $view->paint($target);
    expect(rows($target))->toBe('2  |3  ');
    $src->write("\r\n7\r\n8");
    expect($view->top())->toBe(-5);
    $view->paint($target);
    expect(rows($target))->toBe('2  |3  ');
    $view->scrollBy(100);
    expect($view->isFollowing())->toBeTrue();
    $view->paint($target);
    expect(rows($target))->toBe('7  |8  ');
});

it('does not scroll past the oldest line', function () {
    $src = vt("1\r\n2\r\n3", 3, 2);
    $view = (new Viewport($src))->scrollTo(-99);
    expect($view->top())->toBe(-1);
});

it('maps target positions to source positions', function () {
    $view = (new Viewport(new Surface(4, 2), 3, 2, 6, 4))->setBorder(true);
    expect($view->toSource(4, 3))->toBe([0, 0])
        ->and($view->toSource(3, 3))->toBeNull()
        ->and($view->contains(3, 3))->toBeTrue()
        ->and($view->contains(9, 3))->toBeFalse();
});

it('highlights a selection and returns its text, joining wrapped rows', function () {
    $src = vt("hello world\r\nabcdefghijkl", 10, 4);  // "hello worl" wraps onto "d"
    $view = new Viewport($src);
    $view->select(6, 0, 2, 2);
    expect($view->selectedText())->toBe("world\nabc");
    $target = new Surface(10, 4);
    $view->paint($target);
    expect($target->cell(5, 0)->style)->toBe(Style::plain())
        ->and($target->cell(6, 0)->style->has(Style::INVERSE))->toBeTrue()
        ->and($target->cell(2, 2)->style->has(Style::INVERSE))->toBeTrue()
        ->and($target->cell(3, 2)->style)->toBe(Style::plain());
    $view->select(3, 2, 0, 1);    // backwards
    expect($view->selectedText())->toBe("d\nabcd");
    $view->clearSelection();
    expect($view->selectedText())->toBe('')->and($view->hasSelection())->toBeFalse();
});

it('reports which target rows need repainting', function () {
    $src = new Surface(5, 3);
    $view = new Viewport($src, 0, 2);
    expect($view->damagedRows())->toBe([2, 3, 4]);
    $view->paint(new Surface(5, 6));
    expect($view->damagedRows())->toBe([]);
    $src->write("\e[2;1Hx");
    expect($view->damagedRows())->toBe([3]);
    $view->paint(new Surface(5, 6));
    $view->move(0, 1);
    expect($view->damagedRows())->toBe([1, 2, 3]);
});

it('paints only the requested rows', function () {
    $src = vt("1\r\n2\r\n3", 3, 3);
    $target = new Surface(3, 3);
    (new Viewport($src))->paint($target, [1]);
    expect(rows($target))->toBe('   |2  |   ');
});

it('measures text like the surface lays it out', function () {
    expect(Text::width('abc'))->toBe(3)
        ->and(Text::width('中文'))->toBe(4)
        ->and(Text::width("e\u{301}"))->toBe(1)
        ->and(Text::width("\u{1F468}\u{200D}\u{1F469}"))->toBe(2)
        ->and(Text::truncate('hello world', 6))->toBe('hello…')
        ->and(Text::truncate('中文字', 5))->toBe('中文…')
        ->and(Text::pad('ab', 5, 'right'))->toBe('   ab')
        ->and(Text::pad('ab', 5, 'center'))->toBe(' ab  ');
});
