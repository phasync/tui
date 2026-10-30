<?php

use phasync\Tui\Surface;

describe('printing', function () {
    it('prints text at the cursor and advances', function () {
        $s = vt('hello');
        expect($s->line(0))->toBe('hello')
            ->and(cursorAt($s))->toBe([5, 0]);
    });

    it('wraps at the right margin only when the next character arrives (pending wrap)', function () {
        $s = vt('abcde', 5, 3);
        expect(cursorAt($s))->toBe([4, 0])
            ->and($s->line(1))->toBe('');
        $s->write('f');
        expect(rows($s))->toBe('abcde|f    |     ')
            ->and(cursorAt($s))->toBe([1, 1])
            ->and($s->isWrapped(0))->toBeTrue();
    });

    it('cancels a pending wrap with CR', function () {
        $s = vt("abcde\rX", 5, 2);
        expect(rows($s))->toBe('Xbcde|     ');
    });

    it('overwrites the last column without autowrap (DECAWM off)', function () {
        $s = vt("\e[?7labcdefg", 5, 2);
        expect(rows($s))->toBe('abcdg|     ')
            ->and($s->mode(7))->toBeFalse();
        $s->write("\e[?7h\rabcdefg");
        expect(rows($s))->toBe('abcde|fg   ');
    });

    it('scrolls when wrapping on the last row', function () {
        $s = vt('abcdefghijk', 5, 2);
        expect(rows($s))->toBe('fghij|k    ')
            ->and($s->line(-1))->toBe('abcde');
    });

    it('gives wide characters two cells, the second a continuation', function () {
        $s = vt('a中b');
        expect($s->line(0))->toBe('a中b')
            ->and($s->cell(1, 0)->char)->toBe('中')
            ->and($s->cell(1, 0)->width)->toBe(2)
            ->and($s->cell(2, 0)->char)->toBe('')
            ->and($s->cell(2, 0)->width)->toBe(0)
            ->and($s->cell(3, 0)->char)->toBe('b')
            ->and(cursorAt($s))->toBe([4, 0]);
    });

    it('wraps a wide character that does not fit in the last column', function () {
        $s = vt('abcd中', 5, 2);
        expect(rows($s))->toBe('abcd |中   ')
            ->and(cursorAt($s))->toBe([2, 1]);
    });

    it('repairs a wide character when half of it is overwritten', function () {
        $s = vt("中文\e[2GX");
        expect($s->line(0))->toBe(' X文');
        $s = vt("中文\e[1GX");
        expect($s->line(0))->toBe('X 文');
    });

    it('attaches combining marks to the previous character', function () {
        $s = vt("e\u{0301}x");
        expect($s->cell(0, 0)->char)->toBe("e\u{0301}")
            ->and($s->cell(1, 0)->char)->toBe('x')
            ->and(cursorAt($s))->toBe([2, 0]);
    });

    it('attaches a combining mark to a character in the last column with wrap pending', function () {
        $s = vt("abcde\u{0301}", 5, 2);
        expect($s->cell(4, 0)->char)->toBe("e\u{0301}")
            ->and(cursorAt($s))->toBe([4, 0]);
    });

    it('keeps emoji with variation selectors and joiners in one cell', function () {
        $s = vt("\u{2764}\u{FE0F}!");
        expect($s->cell(0, 0)->char)->toBe("\u{2764}\u{FE0F}")
            ->and($s->cell(1, 0)->char)->toBe('!');
    });

    it('shows malformed UTF-8 as U+FFFD', function () {
        $s = vt("a\xffb\xc3(c");
        expect($s->line(0))->toBe("a\u{FFFD}b\u{FFFD}(c");
    });

    it('completes a UTF-8 character split across writes', function () {
        $s = new Surface(10, 2);
        $s->write("\xe4");
        $s->write("\xb8");
        expect($s->line(0))->toBe('');
        $s->write("\xadx");
        expect($s->line(0))->toBe('中x');
    });

    it('inserts instead of overwriting in insert mode (IRM)', function () {
        $s = vt("abcdef\r\e[4hXY\e[4lZ", 10, 1);
        expect($s->line(0))->toBe('XYZbcdef');
    });

    it('repeats the last character (REP)', function () {
        expect(vt("ab\e[3b")->line(0))->toBe('abbbb');
    });
});

describe('C0 controls', function () {
    it('moves back with BS but not past the left margin', function () {
        $s = vt("ab\x08\x08\x08X");
        expect($s->line(0))->toBe('Xb');
    });

    it('moves back from a pending wrap with BS', function () {
        $s = vt("abcde\x08X", 5, 2);
        expect(rows($s))->toBe('abcXe|     ');
    });

    it('moves to tab stops every 8 columns with HT', function () {
        $s = vt("a\tb\tc", 20, 1);
        expect($s->line(0))->toBe('a       b       c');
    });

    it('stops HT at the last column', function () {
        $s = vt("\t\t\t\tX", 20, 1);
        expect(cursorAt($s))->toBe([19, 0])
            ->and($s->line(0))->toBe(str_repeat(' ', 19) . 'X');
    });

    it('moves down with LF, VT and FF without returning the carriage', function () {
        $s = vt("a\nb\x0bc\x0cd", 10, 4);
        expect(rows($s))->toBe('a         | b        |  c       |   d      ');
    });

    it('returns to column 0 with CR', function () {
        expect(vt("abc\rX")->line(0))->toBe('Xbc');
    });

    it('rings the bell with BEL', function () {
        $s = new Surface(10, 1);
        $rang = 0;
        $s->on('bell', function () use (&$rang) { $rang++; });
        $s->write("a\x07b\x07");
        expect($rang)->toBe(2)
            ->and($s->line(0))->toBe('ab');
    });

    it('switches to G1 with SO and back with SI', function () {
        $s = vt("\e)0q\x0eq\x0fq");
        expect($s->line(0))->toBe('q─q');
    });

    it('ignores the other C0 controls, CAN, SUB and DEL in text', function () {
        expect(vt("a\x00\x01\x05\x18\x1a\x7fb\x1c\x1d")->line(0))->toBe('ab');
    });
});
