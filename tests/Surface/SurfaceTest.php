<?php

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Style;
use phasync\Tui\Surface;

/** A stream with every kind of item, used for the chunking properties. */
function mixedStream(): string
{
    return "plain \e[1;31mred\e[0m 中文 e\u{0301}\r\n"
        . "\e]2;a title\x07\e[2;5H\e[K\e(0lqk\e(B\e[?25l\e[?1049h\e[Halt\e[?1049l"
        . "\eP1\$r0m\e\\\e_apc\e\\\e[38;2;1;2;3mrgb\e[0m\e7\e[3;3Hx\e8\t|\x08\e[3@"
        . "\e]8;;http://example.com\e\\link\e]8;;\e\\\e[2;4r\e[4;1H\n\n\eM\e[r\e[5n"
        . "\xc3\xa6\xe2\x82\xac\xf0\x9f\x98\x80 \e[4:3mcurly\e[24m\u{9b}1mC1\e[m\e[1 2Hbad\x18\e[31\e[32mok";
}

function snapshot(Surface $s): array
{
    return [$s->dump(), [$s->cursor()->x, $s->cursor()->y, $s->cursor()->visible], $s->title(), $s->input->peek(), $s->scrollbackSize()];
}

describe('chunking', function () {
    it('gives the same result whatever the chunk boundaries', function () {
        $bytes = mixedStream();
        $whole = new Surface(30, 6);
        $whole->write($bytes);
        $expected = snapshot($whole);
        for ($i = 1; $i < strlen($bytes); $i++) {
            $s = new Surface(30, 6);
            $s->write(substr($bytes, 0, $i));
            $s->write(substr($bytes, $i));
            expect(snapshot($s))->toBe($expected, "split at byte $i");
        }
    });

    it('gives the same result when written one byte at a time', function () {
        $bytes = mixedStream();
        $whole = new Surface(30, 6);
        $whole->write($bytes);
        $s = new Surface(30, 6);
        foreach (str_split($bytes) as $b) {
            $s->write($b);
        }
        expect(snapshot($s))->toBe(snapshot($whole));
    });

    it('recovers after an unterminated string longer than the limit', function () {
        $s = new Surface(10, 1);
        $s->write("\e]2;" . str_repeat('x', (1 << 20) + 10));
        $s->write("ab");
        expect($s->title())->toBe('');
    });
});

describe('reading', function () {
    it('returns cells, lines and styled runs', function () {
        $s = vt("ab\e[1;32mcd\e[0m");
        $runs = $s->runs(0);
        expect($runs)->toHaveCount(3)
            ->and($runs[0]->text)->toBe('ab')
            ->and($runs[1]->x)->toBe(2)
            ->and($runs[1]->text)->toBe('cd')
            ->and($runs[1]->style)->toBe(Style::of(Color::GREEN, attrs: Style::BOLD))
            ->and($runs[2]->width)->toBe(16);
    });

    it('counts a wide character once in text and twice in width', function () {
        $runs = vt('中x', 4, 1)->runs(0);
        expect($runs[0]->text)->toBe('中x ')
            ->and($runs[0]->width)->toBe(4);
    });

    it('dumps styles as markup, doubling literal brackets', function () {
        expect(vt("[a] \e[1mb\e[0m \e[38;5;200;48;2;0;0;255mc\e[0m", 10, 2)->dump())
            ->toBe("[[a] [bold]b[/] [fg=200 bg=#0000ff]c[/]\n");
    });

    it('rejects coordinates outside the surface', function () {
        $s = new Surface(5, 2);
        expect(fn() => $s->cell(5, 0))->toThrow(OutOfRangeException::class)
            ->and(fn() => $s->line(2))->toThrow(OutOfRangeException::class)
            ->and(fn() => $s->line(-1))->toThrow(OutOfRangeException::class);
    });

    it('converts to a string as its text', function () {
        expect((string) vt("a\r\nb", 3, 2))->toBe("a\nb");
    });
});

describe('damage', function () {
    it('reports every row the first time, then only changed rows', function () {
        $s = new Surface(10, 4);
        expect($s->takeDamage())->toBe([0, 1, 2, 3])
            ->and($s->takeDamage())->toBe([]);
        $s->write("\e[2;1Hx\e[4;1Hy");
        expect($s->takeDamage())->toBe([1, 3]);
    });

    it('marks the scrolled region when scrolling', function () {
        $s = new Surface(10, 4);
        $s->takeDamage();
        $s->write("\e[4;1H\n");
        expect($s->takeDamage())->toBe([0, 1, 2, 3]);
    });

    it('lets independent readers track changes by version', function () {
        $s = new Surface(10, 4);
        $a = $s->version();
        $s->write("\e[1;1Hx");
        $b = $s->version();
        $s->write("\e[3;1Hy");
        expect($s->damagedSince($a))->toBe([0, 2])
            ->and($s->damagedSince($b))->toBe([2])
            ->and($s->damagedSince($s->version()))->toBe([]);
    });

    it('notifies change listeners after each write and resize, until removed', function () {
        $s = new Surface(10, 4);
        $count = 0;
        $off = $s->on('change', function () use (&$count) { $count++; });
        $s->write('a');
        $s->resize(5, 2);
        $off();
        $s->write('b');
        expect($count)->toBe(2);
    });
});

describe('resizing', function () {
    it('keeps what fits when shrinking and pads when growing', function () {
        $s = vt("abcdef\r\nghijkl", 6, 2);
        $s->resize(3, 2);
        expect(rows($s))->toBe('abc|ghi');
        $s->resize(5, 3);
        expect(rows($s))->toBe('abc  |ghi  |     ');
    });

    it('drops blank rows below the cursor first, then moves rows into scrollback', function () {
        $s = vt("1\r\n2\r\n3", 3, 6);
        $s->resize(3, 4);
        expect(rows($s))->toBe('1  |2  |3  |   ')
            ->and($s->scrollbackSize())->toBe(0);
        $s->resize(3, 2);
        expect(rows($s))->toBe('2  |3  ')
            ->and($s->line(-1))->toBe('1')
            ->and(cursorAt($s))->toBe([1, 1]);
    });

    it('clamps the cursor and resets the scroll region', function () {
        $s = vt("\e[2;3r\e[5;10H", 10, 5);
        $s->resize(4, 5);
        expect(cursorAt($s))->toBe([3, 4]);
        $s->write("\n");
        expect($s->scrollbackSize())->toBe(1);
    });

    it('blanks a wide character cut in half by the new width', function () {
        $s = vt('ab中', 4, 1);
        $s->resize(3, 1);
        expect($s->line(0))->toBe('ab');
    });

    it('resizes the alternate and main screens', function () {
        $s = vt("main\e[?1049halt", 6, 3);
        $s->resize(3, 2);
        $s->write("\e[?1049l");
        expect($s->line(0))->toBe('mai');
    });

    it('reports the new size to resize listeners', function () {
        $s = new Surface(10, 4);
        $got = null;
        $s->on('resize', function (array $size) use (&$got) { $got = $size; });
        $s->resize(7, 3);
        expect($got)->toBe([7, 3]);
    });
});

describe('Ansi', function () {
    it('builds sequences that the Surface understands', function () {
        $style = Style::of(Color::rgb(1, 2, 3), 200, Style::BOLD | Style::UNDERLINE);
        $s = vt(Ansi::moveTo(3, 1) . Ansi::style($style) . 'x' . Ansi::reset() . 'y'
            . Ansi::moveTo(0, 0) . Ansi::fg(Color::RED) . Ansi::bg(Color::BRIGHT_BLUE) . 'z'
            . Ansi::title('t') . Ansi::hideCursor());
        expect($s->cell(3, 1)->style)->toBe($style)
            ->and($s->cell(4, 1)->style)->toBe(Style::plain())
            ->and($s->cell(0, 0)->style)->toBe(Style::of(Color::RED, Color::BRIGHT_BLUE))
            ->and($s->title())->toBe('t')
            ->and($s->cursor()->visible)->toBeFalse();
    });

    it('round-trips every style', function () {
        foreach ([Color::DEFAULT, 0, 7, 8, 15, 16, 255, Color::rgb(255, 0, 128)] as $c) {
            foreach ([0, Style::BOLD | Style::DIM, Style::ITALIC | Style::BLINK | Style::INVERSE, Style::HIDDEN | Style::STRIKE] as $a) {
                $style = Style::of($c, $c, $a);
                expect(vt(Ansi::styled('x', $style))->cell(0, 0)->style)->toBe($style);
            }
        }
    });

    it('moves, erases and scrolls', function () {
        $s = vt("abc\r\ndef" . Ansi::up() . Ansi::left(2) . Ansi::clearToEndOfLine() . Ansi::down(1) . Ansi::right(1) . 'X'
            . Ansi::column(0) . 'Y', 6, 2);
        expect(rows($s))->toBe('a     |YeX   ');
    });
});

describe('styles and colors', function () {
    it('interns styles', function () {
        expect(Style::of(1, 2, 3))->toBe(Style::of(1, 2, 3))
            ->and(Style::plain()->with(Style::BOLD)->without(Style::BOLD))->toBe(Style::plain())
            ->and(Style::plain()->withFg(1)->withBg(2))->toBe(Style::of(1, 2));
    });

    it('converts colors', function () {
        expect(Color::toRgb(Color::hex('#102030')))->toBe([16, 32, 48])
            ->and(Color::toRgb(196))->toBe([255, 0, 0])
            ->and(Color::toRgb(232))->toBe([8, 8, 8])
            ->and(Color::toRgb(Color::DEFAULT))->toBeNull()
            ->and(Color::name(Color::rgb(1, 2, 3)))->toBe('#010203');
    });
});
