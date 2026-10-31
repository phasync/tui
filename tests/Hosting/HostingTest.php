<?php

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Focus;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Text;

describe('compositing', function () {
    it('shows a hosted surface at its position', function () {
        $src = vt("ab\e[31mc\e[0m\r\nde", 3, 2);
        $host = new Surface(8, 4);
        $host->host($src, 2, 1);
        expect(rows($host))->toBe('        |  abc   |  de    |        ')
            ->and($host->cell(4, 1)->style)->toBe(Style::of(Color::RED));
    });

    it('catches up with later output when the host is read', function () {
        $src = new Surface(4, 1);
        $host = new Surface(6, 2);
        $host->host($src, 1, 1);
        $src->write('hi');
        expect($host->needsCompose())->toBeTrue()
            ->and($host->line(1))->toBe(' hi')
            ->and($host->needsCompose())->toBeFalse();
    });

    it('composites by writing escape sequences to the host, keeping its producer\'s cursor and pen', function () {
        $host = new Surface(10, 3);
        $host->write("\e[32m\e[3;5Hx");           // the host's own producer: green, cursor at (5, 2)
        $src = vt('ab', 2, 1);
        $host->host($src, 0, 0);
        $host->compose();
        $host->write('y');
        expect(rows($host))->toBe('ab        |          |    xy    ')
            ->and($host->cell(5, 2)->style)->toBe(Style::of(Color::GREEN))
            ->and($host->cursor()->x)->toBe(6);
    });

    it('forwards hyperlinks of a hosted surface, and keeps the host producer\'s own link apart', function () {
        $src = vt('a' . Ansi::hyperlink('bc', 'https://example.com', 'i') . 'd', 4, 1);
        $host = new Surface(8, 2);
        $host->write("\e[2;1H" . Ansi::link('http://host') . 'h');   // the host's producer is in a link
        $host->host($src, 1, 0);
        $host->compose();
        $host->write('j' . Ansi::link() . 'k');
        expect(rows($host))->toBe(' abcd   |hjk     ')
            ->and($host->cell(1, 0)->style->link)->toBe('')
            ->and([$host->cell(2, 0)->style->link, $host->cell(3, 0)->style->linkId])->toBe(['https://example.com', 'i'])
            ->and($host->cell(4, 0)->style->link)->toBe('')
            ->and($host->cell(1, 1)->style->link)->toBe('http://host')       // its link went on after the compositor wrote
            ->and($host->cell(2, 1)->style->link)->toBe('');
        $nested = new Surface(10, 2);
        $nested->host($host, 1, 0);
        expect($nested->cell(3, 0)->style->link)->toBe('https://example.com')->and($nested->cell(2, 1)->style->link)->toBe('http://host');
    });

    it('keeps an insert-mode producer\'s mode and does not insert while compositing', function () {
        $host = new Surface(6, 1);
        $host->write("\e[4h");
        $src = vt('ab', 2, 1);
        $host->host($src, 0, 0);
        $host->write("\e[1;6H");
        $host->compose();
        $host->write("\e[1;4Hz");
        expect($host->line(0))->toBe('ab z');
    });

    it('draws a border and a title around the inner area', function () {
        $host = new Surface(8, 5);
        $view = $host->host(vt('hi', 4, 2), 0, 0, 6, 4)->setBorder(true)->setTitle('t');
        expect($view->innerWidth())->toBe(4)->and($view->innerHeight())->toBe(2)
            ->and($host->text())->toBe("┌ t ─┐\n│hi  │\n│    │\n└────┘\n");
    });

    it('truncates a title that does not fit', function () {
        $host = new Surface(8, 3);
        $host->host(new Surface(4, 1), 0, 0, 6, 3)->setBorder(true)->setTitle('a long title');
        expect($host->line(0))->toBe('┌ a l┐');
    });

    it('clips at the edges of the host', function () {
        $host = new Surface(3, 2);
        $host->host(vt("abcd\r\nefgh", 4, 2), -1, 1);
        expect(rows($host))->toBe('   |bcd');
    });

    it('replaces a wide character cut by the inner edges with a blank', function () {
        $src = vt('中文字', 6, 1);
        $host = new Surface(6, 1);
        $host->host($src, 0, 0, 3, 1);
        expect($host->line(0))->toBe('中');
        $host->viewport($src)->resize(4, 1)->pan(1);
        expect($host->line(0))->toBe(' 文');
    });

    it('paints overlapping windows in z-order and repaints what a closed window covered', function () {
        $host = new Surface(6, 3);
        $below = vt("aaaaaa\r\naaaaaa\r\naaaaaa", 6, 3);
        $above = vt('bb', 2, 1);
        $host->host($below);
        $host->host($above, 2, 1);
        expect(rows($host))->toBe('aaaaaa|aabbaa|aaaaaa');
        $below->write("\e[2;1Hcccccc");                // under the window above
        expect(rows($host))->toBe('aaaaaa|ccbbcc|aaaaaa');
        $host->unhost($above);
        expect(rows($host))->toBe('aaaaaa|cccccc|aaaaaa');
        $host->host($above, 0, 0);
        $host->raise($below);
        expect(rows($host))->toBe('aaaaaa|cccccc|aaaaaa');
    });

    it('blanks the area a window moves away from', function () {
        $host = new Surface(4, 2);
        $src = vt('xy', 2, 1);
        $view = $host->host($src, 0, 0);
        expect($host->line(0))->toBe('xy');
        $view->move(2, 1);
        expect(rows($host))->toBe('    |  xy');
    });

    it('paints the window again over what the host itself writes under it', function () {
        $host = new Surface(4, 1);
        $host->host(vt('ab', 2, 1), 1, 0);
        $host->compose();
        $host->write("\e[1;1HXXXX");
        expect($host->line(0))->toBe('XabX');
    });

    it('nests: a hosted surface hosting surfaces shows on the root', function () {
        $root = new Surface(8, 3);
        $mid = new Surface(6, 2);
        $leaf = new Surface(3, 1);
        $root->host($mid, 1, 1);
        $mid->host($leaf, 2, 1);
        $leaf->write('abc');
        expect(rows($root))->toBe('        |        |   abc  ');
        $leaf->write("\rxyz");
        expect($root->line(2))->toBe('   xyz');
    });

    it('refuses to host itself or a surface that hosts it', function () {
        $a = new Surface(4, 2);
        $b = new Surface(2, 1);
        $a->host($b);
        expect(fn() => $a->host($a))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $b->host($a))->toThrow(InvalidArgumentException::class);
    });

    it('holds back a window during a synchronized update', function () {
        $host = new Surface(4, 1);
        $src = new Surface(4, 1);
        $host->host($src);
        $src->write("\e[?2026hab");
        expect($host->line(0))->toBe('')->and($host->needsCompose())->toBeTrue();
        $src->write("cd\e[?2026l");
        expect($host->line(0))->toBe('abcd');
    });

    it('passes the focused window\'s title, the bell and clipboard requests up to the host', function () {
        $root = new Surface(10, 2);
        $a = new Surface(5, 1);
        $b = new Surface(5, 1);
        $root->host($a);
        $root->host($b, 5, 0);
        $events = [];
        $root->on('bell', function () use (&$events) { $events[] = 'bell'; });
        $root->on('clipboard', function (string $t) use (&$events) { $events[] = "copy $t"; });
        $a->write("\e]2;first\x07");
        $b->write("\e]2;second\x07\x07\e]52;c;" . base64_encode('hi') . "\x07");
        expect($root->title())->toBe('first')->and($events)->toBe(['bell', 'copy hi']);
        $b->focus();
        expect($root->title())->toBe('second');
    });
});

describe('scrolling', function () {
    it('follows the bottom of a source taller than the view', function () {
        $host = new Surface(3, 2);
        $view = $host->host(vt(implode("\r\n", range(1, 6)), 3, 6), 0, 0, 3, 2);
        expect($view->top())->toBe(4)->and($view->isFollowing())->toBeTrue()
            ->and(rows($host))->toBe('5  |6  ');
    });

    it('scrolls back into the scrollback and stays on the same text as output continues', function () {
        $src = vt(implode("\r\n", range(1, 6)), 3, 2);   // rows "5","6"; scrollback 1..4
        $host = new Surface(3, 2);
        $view = $host->host($src);
        $view->scrollBy(-3);
        expect($view->top())->toBe(-3)->and($view->isFollowing())->toBeFalse()
            ->and(rows($host))->toBe('2  |3  ');
        $src->write("\r\n7\r\n8");
        expect($view->top())->toBe(-5)->and(rows($host))->toBe('2  |3  ');
        $view->scrollBy(100);
        expect($view->isFollowing())->toBeTrue()->and(rows($host))->toBe('7  |8  ');
    });

    it('does not scroll past the oldest line', function () {
        $host = new Surface(3, 2);
        expect($host->host(vt("1\r\n2\r\n3", 3, 2))->scrollTo(-99)->top())->toBe(-1);
    });

    it('maps host positions to source positions and back', function () {
        $host = new Surface(12, 8);
        $view = $host->host(new Surface(4, 2), 3, 2, 6, 4)->setBorder(true);
        expect($view->toSource(4, 3))->toBe([0, 0])
            ->and($view->toSource(3, 3))->toBeNull()
            ->and($view->toSource(0, 0, clamp: true))->toBe([0, 0])
            ->and($view->toHost(1, 1))->toBe([5, 4])
            ->and($view->contains(3, 3))->toBeTrue()
            ->and($view->contains(9, 3))->toBeFalse();
    });

    it('keeps a fitted source the size of the inner area', function () {
        $host = new Surface(20, 10);
        $src = new Surface(3, 3);
        $view = $host->host($src)->setFit(true)->resize(8, 5);
        expect([$src->cols(), $src->rows()])->toBe([8, 5]);
        $view->setBorder(true);
        expect([$src->cols(), $src->rows()])->toBe([6, 3]);
    });
});

describe('selection', function () {
    it('highlights a selection and returns its text, joining wrapped rows', function () {
        $src = vt("hello world\r\nabcdefghijkl", 10, 4);  // "hello worl" wraps onto "d"
        $host = new Surface(10, 4);
        $view = $host->host($src);
        $view->select(6, 0, 2, 2);
        expect($view->selectedText())->toBe("world\nabc")
            ->and($host->cell(5, 0)->style)->toBe(Style::plain())
            ->and($host->cell(6, 0)->style->has(Style::INVERSE))->toBeTrue()
            ->and($host->cell(2, 2)->style->has(Style::INVERSE))->toBeTrue()
            ->and($host->cell(3, 2)->style)->toBe(Style::plain());
        $view->select(3, 2, 0, 1);    // backwards
        expect($view->selectedText())->toBe("d\nabcd");
        $view->clearSelection();
        expect($view->selectedText())->toBe('')->and($view->hasSelection())->toBeFalse()
            ->and($host->cell(6, 0)->style)->toBe(Style::plain());
    });

    it('selects with a mouse drag when the program did not ask for the mouse, and copies', function () {
        $root = new Surface(12, 3);
        $src = vt("one two\r\nthree", 10, 2);
        $root->host($src, 1, 1);
        $copied = null;
        $root->on('clipboard', function (string $t) use (&$copied) { $copied = $t; });
        $root->dispatch(new Mouse(Mouse::PRESS, 5, 1, Mouse::LEFT));
        $root->dispatch(new Mouse(Mouse::DRAG, 3, 2, Mouse::LEFT));
        $root->dispatch(new Mouse(Mouse::RELEASE, 3, 2, Mouse::LEFT));
        expect($copied)->toBe("two\nthr")->and($root->cell(5, 1)->style->has(Style::INVERSE))->toBeTrue();
        $root->dispatch(Key::parse('x'));    // typing clears it
        expect($root->viewport($src)->hasSelection())->toBeFalse();
    });

    it('scrolls with the wheel when the program did not ask for the mouse', function () {
        $root = new Surface(3, 2);
        $src = vt(implode("\r\n", range(1, 6)), 3, 2);
        $view = $root->host($src);
        $root->dispatch(new Mouse(Mouse::WHEEL_UP, 0, 0));
        expect($view->top())->toBe(-3);
        $root->dispatch(Key::parse('a'));    // typing goes back to the bottom
        expect($view->isFollowing())->toBeTrue();
    });
});

describe('input routing', function () {
    it('sends keys and pastes to the focused surface, encoded for its modes', function () {
        $root = new Surface(10, 2);
        $a = new Surface(5, 1);
        $b = new Surface(5, 1);
        $root->host($a);
        $root->host($b, 5, 0);
        expect($root->focused())->toBe($a);
        $b->write("\e[?1h\e[?2004h");
        $root->dispatch(Key::parse('up'));
        $b->focus();
        $root->dispatch(Key::parse('up'));
        $root->dispatch(new Paste('p'));
        expect($a->input->peek())->toBe("\e[A")->and($b->input->peek())->toBe("\eOA\e[200~p\e[201~");
    });

    it('lets a host take events first with input listeners', function () {
        $root = new Surface(10, 2);
        $a = new Surface(5, 1);
        $root->host($a);
        $root->on('key', fn(Key $k) => $k->is('ctrl+b') ? true : null);
        $root->dispatch(Key::parse('ctrl+b'));
        $root->dispatch(Key::parse('x'));
        expect($a->input->peek())->toBe('x');
    });

    it('translates mouse coordinates for a program that asked for the mouse, and captures drags', function () {
        $root = new Surface(20, 10);
        $src = new Surface(8, 4);
        $src->write("\e[?1002h\e[?1006h");
        $root->host($src, 5, 3, 10, 6)->setBorder(true);
        $root->dispatch(new Mouse(Mouse::PRESS, 7, 5, Mouse::LEFT));
        $root->dispatch(new Mouse(Mouse::DRAG, 30, 0, Mouse::LEFT));     // outside: clamped
        $root->dispatch(new Mouse(Mouse::RELEASE, 30, 0, Mouse::LEFT));
        expect($src->input->peek())->toBe("\e[<0;2;2M\e[<32;8;1M\e[<0;8;1m");
    });

    it('focuses on click when asked to', function () {
        $root = new Surface(10, 1);
        $a = new Surface(5, 1);
        $b = new Surface(5, 1);
        $root->host($a);
        $root->host($b, 5, 0);
        $root->dispatch(new Mouse(Mouse::PRESS, 6, 0, Mouse::LEFT));
        expect($root->focused())->toBe($a);
        $root->setFocusOnClick(true);
        $root->dispatch(new Mouse(Mouse::PRESS, 6, 0, Mouse::LEFT));
        expect($root->focused())->toBe($b);
    });

    it('tells surfaces when they gain and lose the focus', function () {
        $root = new Surface(10, 1);
        $a = new Surface(5, 1);
        $b = new Surface(5, 1);
        $root->host($a);
        $root->host($b, 5, 0);
        $a->write("\e[?1004h");
        $b->write("\e[?1004h");
        $b->focus();
        $root->dispatch(new Focus(false));   // the terminal lost focus
        expect($a->input->peek())->toBe("\e[O")->and($b->input->peek())->toBe("\e[I\e[O");
    });

    it('moves the focus through the leaves depth first', function () {
        $root = new Surface(20, 2);
        $a = new Surface(5, 1);
        $group = new Surface(10, 1);
        $b = new Surface(5, 1);
        $c = new Surface(5, 1);
        $root->host($a);
        $root->host($group, 5, 0);
        $group->host($b);
        $group->host($c, 5, 0);
        $root->focusNext();
        expect($root->focused())->toBe($group)->and($group->focused())->toBe($b);
        $root->focusNext();
        expect($group->focused())->toBe($c);
        $root->focusNext();
        expect($root->focused())->toBe($a);
        $root->focusNext(backwards: true);
        expect($root->focused())->toBe($group)->and($group->focused())->toBe($c);
    });

    it('shows the focused window\'s cursor as the active cursor', function () {
        $root = new Surface(20, 10);
        $src = new Surface(8, 4);
        $root->host($src, 5, 3, 10, 6)->setBorder(true);
        $src->write("\e[2;3H");
        $c = $root->activeCursor();
        expect([$c->x, $c->y, $c->visible])->toBe([8, 5, true]);
        $root->takeFocus();
        expect([$root->activeCursor()->x, $root->activeCursor()->y])->toBe([0, 0]);
    });
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

it('shows a synchronized update that ends after its host\'s host composed (regression)', function () {
    // root -> mid -> leaf: mid holds leaf back while leaf is in mode 2026; the end of the update
    // must still reach the root, although mid was already dirty when it ended
    $root = new Surface(6, 1);
    $mid = new Surface(6, 1);
    $leaf = new Surface(6, 1);
    $root->host($mid);
    $mid->host($leaf);
    $leaf->write("\e[?2026hab");
    expect($root->line(0))->toBe('')->and($root->needsCompose())->toBeTrue();
    $leaf->write("cd\e[?2026l");
    expect($root->needsCompose())->toBeTrue()->and($root->line(0))->toBe('abcd');
});

it('does not lose rows changed while a host reads them (regression)', function () {
    $host = new Surface(4, 2);
    $src = new Surface(4, 2);
    $view = $host->host($src);
    $src->write('ab');
    $host->compose();
    $src->write("\r\ncd");
    expect($view->damagedRows())->toBe([1])->and($host->line(1))->toBe('cd');
});

describe('always a diff', function () {
    /** What the compositor writes to bring $view up to date (the bytes it writes to the host). */
    function compositorBytes(Surface $host, phasync\Tui\Viewport $view): string
    {
        return $view->paint(array_flip($view->damagedRows()), $host->cols(), $host->rows());
    }

    it('writes only the cells that changed, not the row', function () {
        $host = new Surface(40, 5);
        $src = vt(str_repeat('x', 30) . "\r\n" . str_repeat('y', 30), 30, 3);
        $view = $host->host($src, 2, 1);
        $host->compose();
        $src->write("\e[1;10HZ");
        $bytes = compositorBytes($host, $view);
        expect($bytes)->toContain('Z')->not->toContain('x')
            ->and(\strlen($bytes))->toBeLessThan(20);
    });

    it('writes runs of changed cells with one style change each', function () {
        $host = new Surface(40, 2);
        $src = new Surface(30, 1);
        $view = $host->host($src);
        $host->compose();
        $src->write("\e[1;3H\e[31mabc\e[1;20H\e[32mdef");
        $bytes = compositorBytes($host, $view);
        expect(substr_count($bytes, 'H'))->toBe(2)->and($bytes)->toContain('abc')->toContain('def');
    });

    it('costs nothing for an idle window', function () {
        $host = new Surface(20, 3);
        $host->host(vt('idle', 10, 2));
        $host->compose();
        $version = $host->version();
        $host->compose();
        expect($host->version())->toBe($version);
    });

    it('writes nothing under a window above', function () {
        $host = new Surface(10, 1);
        $below = vt('aaaaaaaaaa', 10, 1);
        $above = vt('BB', 2, 1);
        $view = $host->host($below);
        $host->host($above, 4, 0);
        $host->compose();
        $below->write("\e[1;1Hcccccccccc");
        $bytes = $view->paint(array_flip($view->damagedRows()), 10, 1, [$host->viewport($above)->rect()]);
        expect(substr_count($bytes, 'c'))->toBe(8);
        // in place: the window above is not repainted, so it would show if it had been overwritten
        $below->write("\e[1;1Hdddddddddd");
        $host->compose();
        expect($host->line(0))->toBe('ddddBBdddd');
    });

    it('repaints in full only when the geometry changes', function () {
        $host = new Surface(20, 3);
        $src = vt('hello', 10, 1);
        $view = $host->host($src);
        $host->compose();
        $view->setTitle('t');           // a border was not set: nothing to write
        expect(compositorBytes($host, $view))->toBe('');
        $view->move(1, 0);
        expect(compositorBytes($host, $view))->toContain('hello');
    });
});

it('composes incrementally exactly what a fresh host composes from scratch (property)', function () {
    $focusStyle = Style::of(Color::GREEN);
    // a fresh host with the same windows, composed once from nothing
    $fresh = function (Surface $host) use ($focusStyle): string {
        $f = new Surface($host->cols(), $host->rows());
        foreach ($host->viewports() as $hv) {
            $fv = $f->host($hv->source, $hv->x(), $hv->y(), $hv->width(), $hv->height())
                ->setBorder($hv->hasBorder(), null, $focusStyle)->setTitle($hv->title());
            if (!$hv->isFollowing()) {
                $fv->scrollTo($hv->top());
            }
        }
        $host->focused()?->focus();
        // what the host's own producer wrote outside the windows
        for ($y = 0; $y < $host->rows(); $y++) {
            for ($x = 0; $x < $host->cols(); $x++) {
                $covered = array_filter($host->viewports(), fn($v) => $v->contains($x, $y)) !== [];
                $c = $host->cell($x, $y);
                if (!$covered && $c->char !== '') {
                    $f->write("\e7" . Ansi::moveTo($x, $y) . Ansi::style($c->style) . $c->char . "\e8");
                }
            }
        }
        $dump = $f->dump();
        foreach ($host->viewports() as $hv) {
            $f->unhost($hv->source);
        }
        return $dump;
    };
    $pieces = ['ab', 'X', ' ', '中', '👍', "\e[1m", "\e[0m", "\e[31m", "\e[44m", "\r\n", "\e[K", "\e[2;2H", "\e[1L", "\e[2P", 'é'];
    foreach (range(1, 12) as $seed) {
        mt_srand($seed);
        $host = new Surface(24, 10);
        $sources = [];
        for ($i = 0; $i < 3; $i++) {
            $sources[$i] = new Surface(mt_rand(4, 14), mt_rand(2, 6));
            $host->host($sources[$i], mt_rand(-2, 18), mt_rand(-1, 8), mt_rand(3, 16), mt_rand(2, 7))
                ->setBorder(mt_rand(0, 1) === 1, null, $focusStyle)->setTitle("w$i");
        }
        for ($step = 0; $step < 150; $step++) {
            $s = $sources[$si = mt_rand(0, 2)];
            $v = $host->viewport($s);
            switch ($op = mt_rand(0, 12)) {
                case 6:
                    $v->move(mt_rand(-2, 18), mt_rand(-1, 8));
                    break;
                case 7:
                    $host->raise($s);
                    break;
                case 8:
                    $v->scrollBy(mt_rand(-3, 3));
                    break;
                case 9:
                    $v->resize(mt_rand(3, 16), mt_rand(2, 7));
                    break;
                case 10:
                    [$x, $y, $w, $h] = $v->rect();
                    $host->unhost($s);
                    $host->host($s, $x, $y, $w, $h)->setBorder(true, null, $focusStyle)->setTitle("w$si");
                    break;
                case 11:
                    $s->focus();
                    break;
                case 12:
                    $host->write("\e[" . mt_rand(1, 10) . ';' . mt_rand(1, 24) . 'H' . str_repeat('#', mt_rand(1, 8)));
                    break;
                default:
                    $bytes = '';
                    for ($j = mt_rand(1, 6); $j > 0; $j--) {
                        $bytes .= $pieces[mt_rand(0, \count($pieces) - 1)];
                    }
                    $s->write($bytes);
            }
            if ($step % 3 === 0) {
                expect($host->dump())->toBe($fresh($host), "seed $seed step $step");
            }
        }
    }
});

it('paints a bordered window narrower or lower than its border (regression)', function () {
    $src = vt('xyz', 3, 1);
    $host = new Surface(6, 3);
    $view = $host->host($src, 1, 0, 1, 3)->setBorder(true);
    set_error_handler(fn(int $no, string $msg) => throw new ErrorException($msg, 0, $no));
    try {
        expect($host->line(0))->toBe(' ┌')                  // one cell wide: the left border only
            ->and($host->line(1))->toBe(' │');
        $view->resize(2, 1);
        expect($host->line(0))->toBe(' ┌┐');
        $src->write("\r1");
        expect($host->line(0))->toBe(' ┌┐');
    } finally {
        restore_error_handler();
    }
});

it('repaints a window scrolled back when its scrollback is rewritten under the same top row (regression)', function () {
    $src = new Surface(6, 2, 100);
    $src->write(implode("\r\n", array_map(fn($i) => "a$i", range(1, 10))));
    $host = new Surface(6, 2);
    $view = $host->host($src, 0, 0, 6, 2);
    $view->scrollTo(-4);
    expect($host->line(0))->toBe('a5');
    // cleared and written again (as a log laid out anew does), then back to the same row
    $src->write("\e[3J\e[H\e[2J" . implode("\r\n", array_map(fn($i) => "b$i", range(1, 10))));
    $view->scrollTo(-4);
    expect($host->line(0))->toBe('b5')->and($host->line(1))->toBe('b6');
});
