<?php

use phasync\Tui\Color;
use phasync\Tui\Ansi;
use phasync\Tui\Style;
use phasync\Tui\Surface;

describe('CSI cursor movement', function () {
    it('moves with CUU, CUD, CUF and CUB, treating 0 and omitted as 1', function () {
        $s = vt("\e[3;5H\e[A", 10, 5);
        expect(cursorAt($s))->toBe([4, 1]);
        $s->write("\e[2B");
        expect(cursorAt($s))->toBe([4, 3]);
        $s->write("\e[0C");
        expect(cursorAt($s))->toBe([5, 3]);
        $s->write("\e[3D");
        expect(cursorAt($s))->toBe([2, 3]);
    });

    it('clamps movement at the edges', function () {
        $s = vt("\e[99A\e[99D", 10, 5);
        expect(cursorAt($s))->toBe([0, 0]);
        $s->write("\e[99B\e[99C");
        expect(cursorAt($s))->toBe([9, 4]);
    });

    it('moves to the start of a line below or above with CNL and CPL', function () {
        $s = vt("\e[3;5H\e[E", 10, 5);
        expect(cursorAt($s))->toBe([0, 3]);
        $s->write("\e[4G\e[2F");
        expect(cursorAt($s))->toBe([0, 1]);
    });

    it('positions absolutely with CUP, HVP, CHA, HPA, VPA, and relatively with HPR and VPR', function () {
        $s = vt("\e[2;3H", 10, 5);
        expect(cursorAt($s))->toBe([2, 1]);
        $s->write("\e[4;6f");
        expect(cursorAt($s))->toBe([5, 3]);
        $s->write("\e[8G");
        expect(cursorAt($s))->toBe([7, 3]);
        $s->write("\e[2`");
        expect(cursorAt($s))->toBe([1, 3]);
        $s->write("\e[1d");
        expect(cursorAt($s))->toBe([1, 0]);
        $s->write("\e[3a\e[2e");
        expect(cursorAt($s))->toBe([4, 2]);
        $s->write("\e[H");
        expect(cursorAt($s))->toBe([0, 0]);
    });

    it('clears a pending wrap when the cursor moves', function () {
        $s = vt("abcde\e[1DX", 5, 2);
        expect(rows($s))->toBe('abcXe|     ');
    });

    it('positions relative to the scroll region in origin mode (DECOM)', function () {
        $s = vt("\e[2;4r\e[?6h\e[1;1HA\e[9;1HB", 5, 5);
        expect(rows($s))->toBe('     |A    |     |B    |     ');
        $s->write("\e[6n");
        expect($s->input->peek())->toBe("\e[3;2R");
    });
});

describe('erasing', function () {
    it('erases in display (ED 0, 1, 2)', function () {
        $fill = "aaaaa\r\nbbbbb\r\nccccc\e[2;3H";
        expect(rows(vt($fill . "\e[J", 5, 3)))->toBe('aaaaa|bb   |     ')
            ->and(rows(vt($fill . "\e[1J", 5, 3)))->toBe('     |   bb|ccccc')
            ->and(rows(vt($fill . "\e[2J", 5, 3)))->toBe('     |     |     ');
    });

    it('erases the character just written into the last column with EL, as xterm does (docs/surface.md)', function () {
        // the cursor stays on the last column after writing it, wrap pending or (autowrap off) not
        expect(rows(vt("\e[?7labcde\e[K", 5, 1)))->toBe('abcd ')
            ->and(rows(vt("abcde\e[K", 5, 2)))->toBe('abcd |     ')
            ->and(rows(vt("\e[?7l\e[2Kabcde", 5, 1)))->toBe('abcde');
    });

    it('does not move the cursor when erasing', function () {
        expect(cursorAt(vt("abc\e[2J", 5, 3)))->toBe([3, 0]);
    });

    it('clears the scrollback with ED 3', function () {
        $s = vt("1\r\n2\r\n3\r\n4", 5, 2);
        expect($s->scrollbackSize())->toBe(2);
        $s->write("\e[3J");
        expect($s->scrollbackSize())->toBe(0)
            ->and(rows($s))->toBe('3    |4    ');
    });

    it('erases in line (EL 0, 1, 2)', function () {
        expect(vt("abcdef\e[3G\e[K")->line(0))->toBe('ab')
            ->and(vt("abcdef\e[3G\e[1K")->line(0))->toBe('   def')
            ->and(vt("abcdef\e[3G\e[2K")->line(0))->toBe('');
    });

    it('fills erased cells with the current background color (bce)', function () {
        $s = vt("\e[44m\e[2J\e[0mx", 3, 2);
        expect($s->cell(1, 1)->style)->toBe(Style::of(bg: Color::BLUE))
            ->and($s->cell(0, 0)->style)->toBe(Style::plain());
    });

    it('erases characters with ECH without moving', function () {
        $s = vt("abcdef\e[2G\e[3X");
        expect($s->line(0))->toBe('a   ef')
            ->and(cursorAt($s))->toBe([1, 0]);
    });

    it('treats the selective erase forms like plain erases', function () {
        expect(vt("abcdef\e[3G\e[?K")->line(0))->toBe('ab');
    });
});

describe('inserting and deleting', function () {
    it('inserts blank characters with ICH, pushing the rest right', function () {
        expect(vt("abcdef\e[2G\e[2@", 8, 1)->line(0))->toBe('a  bcdef')
            ->and(vt("abcdefgh\e[2G\e[2@", 8, 1)->line(0))->toBe('a  bcdef');
    });

    it('deletes characters with DCH, pulling the rest left', function () {
        expect(vt("abcdef\e[2G\e[2P")->line(0))->toBe('adef');
    });

    it('inserts and deletes lines with IL and DL inside the scroll region', function () {
        $fill = "1\r\n2\r\n3\r\n4\r\n5";
        expect(rows(vt($fill . "\e[2;1H\e[2L", 3, 5)))->toBe('1  |   |   |2  |3  ')
            ->and(rows(vt($fill . "\e[2;1H\e[2M", 3, 5)))->toBe('1  |4  |5  |   |   ')
            ->and(rows(vt($fill . "\e[2;4r\e[3;1H\e[L", 3, 5)))->toBe('1  |2  |   |3  |5  ');
    });

    it('ignores IL and DL outside the scroll region', function () {
        expect(rows(vt("1\r\n2\r\n3\e[1;2r\e[3;1H\e[L", 3, 3)))->toBe('1  |2  |3  ');
    });

    it('scrolls the region with SU and SD', function () {
        $fill = "1\r\n2\r\n3\r\n4";
        expect(rows(vt($fill . "\e[S", 3, 4)))->toBe('2  |3  |4  |   ')
            ->and(rows(vt($fill . "\e[2T", 3, 4)))->toBe('   |   |1  |2  ');
    });

    it('does not put lines scrolled with SU into scrollback', function () {
        expect(vt("1\r\n2\e[S", 3, 2)->scrollbackSize())->toBe(0);
    });
});

describe('scroll region (DECSTBM)', function () {
    it('scrolls only the region at its bottom margin and homes the cursor when set', function () {
        $s = vt("1\r\n2\r\n3\r\n4\e[2;3r", 3, 4);
        expect(cursorAt($s))->toBe([0, 0]);
        $s->write("\e[3;1H\nX");
        expect(rows($s))->toBe('1  |3  |X  |4  ')
            ->and($s->scrollbackSize())->toBe(0);
    });

    it('scrolls the region down with RI at its top margin', function () {
        $s = vt("1\r\n2\r\n3\r\n4\e[2;3r\e[2;1H\eMX", 3, 4);
        expect(rows($s))->toBe('1  |X  |2  |4  ');
    });

    it('keeps lines scrolled out of a region starting at the top in scrollback', function () {
        $s = vt("1\r\n2\r\n3\e[1;2r\e[2;1H\n", 3, 3);
        expect($s->line(-1))->toBe('1')
            ->and(rows($s))->toBe('2  |   |3  ');
    });

    it('ignores an invalid region', function () {
        $s = vt("\e[3;2r\e[5;1Habc\n", 3, 5);
        expect($s->scrollbackSize())->toBe(1);
    });
});

describe('SGR', function () {
    it('sets and resets every attribute', function () {
        $names = [1 => Style::BOLD, 2 => Style::DIM, 3 => Style::ITALIC, 4 => Style::UNDERLINE,
            5 => Style::BLINK, 7 => Style::INVERSE, 8 => Style::HIDDEN, 9 => Style::STRIKE];
        $off = [1 => 22, 2 => 22, 3 => 23, 4 => 24, 5 => 25, 7 => 27, 8 => 28, 9 => 29];
        foreach ($names as $code => $bit) {
            $s = vt("\e[{$code}mA\e[{$off[$code]}mB");
            expect($s->cell(0, 0)->style->attrs)->toBe($bit)
                ->and($s->cell(1, 0)->style)->toBe(Style::plain());
        }
    });

    it('resets everything with 0 and with no parameter', function () {
        $s = vt("\e[1;4;31;42mA\e[mB\e[1m\e[0mC");
        expect((string) $s->cell(0, 0)->style)->toBe('bold underline fg=1 bg=2')
            ->and($s->cell(1, 0)->style)->toBe(Style::plain())
            ->and($s->cell(2, 0)->style)->toBe(Style::plain());
    });

    it('sets the 16 colors', function () {
        $s = vt("\e[31;42mA\e[91;102mB\e[39;49mC");
        expect($s->cell(0, 0)->style)->toBe(Style::of(Color::RED, Color::GREEN))
            ->and($s->cell(1, 0)->style)->toBe(Style::of(Color::BRIGHT_RED, Color::BRIGHT_GREEN))
            ->and($s->cell(2, 0)->style)->toBe(Style::plain());
    });

    it('sets 256 and truecolor with semicolons and colons', function () {
        $s = vt("\e[38;5;208mA\e[48;2;10;20;30mB\e[0;38:5:99mC\e[38:2::1:2:3mD\e[38:2:4:5:6mE\e[1;38;5;1;4mF");
        expect($s->cell(0, 0)->style->fg)->toBe(208)
            ->and($s->cell(1, 0)->style->bg)->toBe(Color::rgb(10, 20, 30))
            ->and($s->cell(2, 0)->style)->toBe(Style::of(99))
            ->and($s->cell(3, 0)->style->fg)->toBe(Color::rgb(1, 2, 3))
            ->and($s->cell(4, 0)->style->fg)->toBe(Color::rgb(4, 5, 6))
            ->and((string) $s->cell(5, 0)->style)->toBe('bold underline fg=1');
    });

    it('understands underline styles and ignores underline colors', function () {
        $s = vt("\e[4:3mA\e[4:0mB\e[58;5;3mC\e[58:2::1:2:3;1mD");
        expect($s->cell(0, 0)->style->attrs)->toBe(Style::UNDERLINE)
            ->and($s->cell(1, 0)->style)->toBe(Style::plain())
            ->and($s->cell(2, 0)->style)->toBe(Style::plain())
            ->and($s->cell(3, 0)->style)->toBe(Style::of(attrs: Style::BOLD));
    });
});

describe('modes', function () {
    it('hides and shows the cursor (DECTCEM)', function () {
        $s = vt("\e[?25l");
        expect($s->cursor()->visible)->toBeFalse();
        $s->write("\e[?25h");
        expect($s->cursor()->visible)->toBeTrue();
    });

    it('switches to the alternate screen and back with 1049, restoring the cursor', function () {
        $s = vt("main\r\nscreen\e[?1049h", 10, 3);
        expect($s->isAltScreen())->toBeTrue()
            ->and($s->text())->toBe("\n\n");
        $s->write("\e[Halt\r\n1\r\n2\r\n3\r\n4");
        expect($s->scrollbackSize())->toBe(0);
        $s->write("\e[?1049l");
        expect($s->isAltScreen())->toBeFalse()
            ->and(rows($s))->toBe('main      |screen    |          ')
            ->and(cursorAt($s))->toBe([6, 1]);
    });

    it('switches screens with 47 and 1047 too', function () {
        $s = vt("main\e[?47hx\e[?47l", 5, 1);
        expect($s->line(0))->toBe('main');
        $s = vt("main\e[?1047hx\e[?1047l", 5, 1);
        expect($s->line(0))->toBe('main');
    });

    it('records bracketed paste, mouse modes and application cursor keys', function () {
        $s = vt("\e[?2004h\e[?1000h\e[?1006h\e[?1h\e=");
        expect($s->mode(2004))->toBeTrue()
            ->and($s->mode(1000))->toBeTrue()
            ->and($s->mode(1006))->toBeTrue()
            ->and($s->mode(1))->toBeTrue()
            ->and($s->isKeypadApplication())->toBeTrue();
        $s->write("\e[?1002h");
        expect($s->mode(1000))->toBeFalse()
            ->and($s->mode(1002))->toBeTrue();
        $s->write("\e[?2004;1002;1l\e>");
        expect($s->mode(2004))->toBeFalse()
            ->and($s->mode(1002))->toBeFalse()
            ->and($s->mode(1))->toBeFalse()
            ->and($s->isKeypadApplication())->toBeFalse();
    });

    it('sets the cursor shape (DECSCUSR)', function () {
        $s = vt("\e[6 q");
        expect($s->cursor()->shape)->toBe('bar')
            ->and($s->cursor()->blinking)->toBeFalse();
        $s->write("\e[3 q");
        expect($s->cursor()->shape)->toBe('underline')
            ->and($s->cursor()->blinking)->toBeTrue();
        $s->write("\e[0 q");
        expect($s->cursor()->shape)->toBe('block');
    });

    it('soft-resets with DECSTR', function () {
        $s = vt("\e[?25l\e[4h\e[1m\e[2;3r\e[!pab\r\nc", 5, 4);
        expect($s->cursor()->visible)->toBeTrue()
            ->and($s->cell(0, 0)->style)->toBe(Style::plain());
    });
});

describe('saving the cursor', function () {
    it('saves and restores position and style with DECSC and DECRC', function () {
        $s = vt("\e[2;3H\e[1m\e7\e[H\e[0mX\e8Y", 10, 3);
        expect(cursorAt($s))->toBe([3, 1])
            ->and($s->cell(2, 1)->style->attrs)->toBe(Style::BOLD)
            ->and($s->cell(0, 0)->style)->toBe(Style::plain());
    });

    it('saves and restores with CSI s and CSI u', function () {
        expect(cursorAt(vt("\e[2;3H\e[s\e[H\e[u", 10, 3)))->toBe([2, 1]);
    });

    it('restores to home when nothing was saved', function () {
        expect(cursorAt(vt("abc\e8")))->toBe([0, 0]);
    });

    it('does not treat kitty keyboard CSI > u and CSI ? u as restore', function () {
        expect(cursorAt(vt("\e[sabc\e[>1u\e[?u")))->toBe([3, 0]);
    });
});

describe('tab stops', function () {
    it('sets a stop with HTS and clears with TBC', function () {
        $s = vt("\e[3G\eH\r\tX", 20, 1);
        expect($s->line(0))->toBe('  X');
        $s->write("\e[3G\e[g\r\tY");
        expect($s->line(0))->toBe('  X     Y');
        $s->write("\e[3g\r\tZ");
        expect(cursorAt($s))->toBe([19, 0]);
    });

    it('moves forward and back by stops with CHT and CBT', function () {
        $s = vt("\e[2I", 30, 1);
        expect(cursorAt($s))->toBe([16, 0]);
        $s->write("\e[Z");
        expect(cursorAt($s))->toBe([8, 0]);
        $s->write("\e[5Z");
        expect(cursorAt($s))->toBe([0, 0]);
    });
});

describe('OSC and other strings', function () {
    it('stores the title from OSC 0 and 2, terminated by BEL or ST', function () {
        $s = new Surface(10, 1);
        $titles = [];
        $s->on('title', function (string $t) use (&$titles) { $titles[] = $t; });
        $s->write("\e]0;first\x07a\e]2;second\e\\b");
        expect($s->title())->toBe('second')
            ->and($titles)->toBe(['first', 'second'])
            ->and($s->line(0))->toBe('ab');
    });

    it('parses and ignores other OSC strings', function () {
        $s = vt("a\e]8;;http://example.com\e\\link\e]8;;\e\\\e]52;c;aGVsbG8=\x07\e]4;1;?\x07\e]11;?\x07b");
        expect($s->line(0))->toBe('alinkb');
    });

    it('keeps OSC 8 hyperlinks in the cells: the URI and id, across SGR, until the link ends', function () {
        $s = vt("a\e]8;id=x1;https://example.com/a;b\e\\li\e[1;31mnk\e[0m!\e]8;;\x07b \e]8;;file:///tmp\e\\f\e]8;;\e\\");
        $link = fn(int $x) => [$s->cell($x, 0)->style->link, $s->cell($x, 0)->style->linkId];
        expect($s->line(0))->toBe('alink!b f')
            ->and($link(0))->toBe(['', ''])
            ->and($link(1))->toBe(['https://example.com/a;b', 'x1'])
            ->and($link(3))->toBe(['https://example.com/a;b', 'x1'])          // SGR changed the colour, not the link
            ->and($s->cell(3, 0)->style->fg)->toBe(1)
            ->and($link(5))->toBe(['https://example.com/a;b', 'x1'])          // SGR 0 does not end a link
            ->and($link(6))->toBe(['', ''])
            ->and($link(8))->toBe(['file:///tmp', ''])
            ->and(explode("\n", $s->dump())[0])->toBe('a[link=x1;https://example.com/a;b]li[/][bold fg=1 link=x1;https://example.com/a;b]nk[/][link=x1;https://example.com/a;b]![/]b [link=file:///tmp]f[/]');
    });

    it('does not put a hyperlink on erased, inserted or scrolled-in blank cells', function () {
        $s = vt("\e]8;;http://x\e\\ab\e[K\r\e[2@\n\e[2Kz\e]8;;\e\\", 6, 2);
        expect(rows($s))->toBe('  ab  |z     ')
            ->and($s->cell(0, 0)->style->link)->toBe('')->and($s->cell(2, 0)->style->link)->toBe('http://x')
            ->and($s->cell(4, 0)->style->link)->toBe('')
            ->and($s->cell(0, 1)->style->link)->toBe('http://x')->and($s->cell(1, 1)->style->link)->toBe('');
    });

    it('builds hyperlinks with Ansi::link() and Ansi::hyperlink(), dropping control characters', function () {
        expect(Ansi::hyperlink('docs', 'https://example.com', 'd1'))->toBe("\e]8;id=d1;https://example.com\e\\docs\e]8;;\e\\")
            ->and(Ansi::link("http://a\e]b"))->toBe("\e]8;;http://a]b\e\\")
            ->and(Ansi::link())->toBe("\e]8;;\e\\");
        $s = vt('x' . Ansi::hyperlink('docs', 'https://example.com', 'd1') . 'y');
        expect($s->cell(1, 0)->style->link)->toBe('https://example.com')->and($s->cell(5, 0)->style->link)->toBe('');
    });

    it('drops an OSC cancelled with CAN or aborted with ESC', function () {
        $s = vt("\e]2;one\x18a\e]2;two\e[1mb");
        expect($s->title())->toBe('')
            ->and($s->line(0))->toBe('ab')
            ->and($s->cell(1, 0)->style->attrs)->toBe(Style::BOLD);
    });

    it('parses and ignores DCS (other than Sixel, see ImagesTest), SOS, PM and APC', function () {
        $s = vt("a\eP1\$r0m\e\\b\eXsos\e\\c\e^pm\e\\d\e_Gapc;x=1\e\\e\eP+q544e\e\\f");
        expect($s->line(0))->toBe('abcdef');
    });

    it('keeps BEL inside a DCS string', function () {
        expect(vt("a\eP+q\x07544e\e\\b")->line(0))->toBe('ab');
    });
});

describe('ESC sequences', function () {
    it('indexes, reverse-indexes and does next-line (IND, RI, NEL)', function () {
        $s = vt("ab\eDc\eEd", 5, 3);
        expect(rows($s))->toBe('ab   |  c  |d    ');
        $s = vt("a\eMb", 3, 2);
        expect(rows($s))->toBe(' b |a  ');
    });

    it('draws lines with the DEC special graphics set', function () {
        $s = vt("\e(0lqqk\r\nx  x\r\nmqqj\e(Bq");
        expect($s->text())->toBe("┌──┐\n│  │\n└──┘q\n\n");
    });

    it('resets everything with RIS but keeps the scrollback', function () {
        $s = vt("1\r\n2\r\n3\e[1;31m\e[?25l\e]2;t\x07\ec", 3, 2);
        expect($s->text())->toBe("\n")
            ->and(cursorAt($s))->toBe([0, 0])
            ->and($s->cursor()->visible)->toBeTrue()
            ->and($s->line(-1))->toBe('1');
        $s->write('x');
        expect($s->cell(0, 0)->style)->toBe(Style::plain());
    });

    it('fills the screen with E for DECALN', function () {
        expect(vt("\e#8", 3, 2)->text())->toBe("EEE\nEEE");
    });

    it('ignores unknown ESC sequences and charset designations it does not know', function () {
        expect(vt("a\e%Gb\e Fc\e#3d\e)Ae\eNf\e(Kg")->line(0))->toBe('abcdefg');
    });
});

describe('unknown and malformed sequences', function () {
    it('consumes unknown CSI sequences', function () {
        expect(vt("a\e[5;6~b\e[>4;2mc\e[?1;2\$pd\e[=5ue\e[12;3;4zf")->line(0))->toBe('abcdef');
    });

    it('ignores CSI sequences with a parameter after an intermediate or a misplaced marker', function () {
        $s = vt("a\e[1 2Hb\e[1?2Hc");
        expect($s->line(0))->toBe('abc');
    });

    it('executes C0 controls embedded in a sequence', function () {
        $s = vt("abc\e[1\x08D", 10, 2);
        expect(cursorAt($s))->toBe([1, 0]);
        $s = vt("x\e[2\nC", 10, 2);
        expect(cursorAt($s))->toBe([3, 1]);
    });

    it('applies an escape sequence the same with controls embedded in it as without', function () {
        // (the plain form is the grammar's fast path, the embedded one its general rule)
        $plain = vt("ab\e7\e[2;3Hx\e8y\e(0q\e(Bq\e#8", 6, 3);
        $embedded = vt("ab\e\x007\e[2;3Hx\e\x0e8y\e(\x000q\e(\x00Bq\e#\x008", 6, 3);
        expect(rows($embedded))->toBe(rows($plain))
            ->and(rows($plain))->toBe('EEEEEE|EEEEEE|EEEEEE');       // DECALN fills the screen last
        $s = vt("a\e7\e[2;2Hb\e8c\e(0qx\e(Bq", 8, 2);
        expect(rows($s))->toBe('ac─│q   | b      ');
        $s = vt("a\e\x0b7b", 8, 2);                                  // VT embedded: executed, then DECSC
        expect(rows($s))->toBe('a       | b      ');
    });

    it('cancels a sequence with CAN or SUB', function () {
        expect(vt("a\e[31\x18b\e[1\x1ac")->line(0))->toBe('abc')
            ->and(vt("a\e[31\x18b")->cell(1, 0)->style)->toBe(Style::plain());
    });

    it('aborts a sequence when a new one starts', function () {
        $s = vt("a\e[31\e[1mb");
        expect($s->cell(1, 0)->style)->toBe(Style::of(attrs: Style::BOLD));
    });

    it('ignores a CSI sequence containing non-ASCII characters', function () {
        expect(vt("a\e[1éHb")->line(0))->toBe('ab');
    });

    it('accepts the 8-bit C1 forms of CSI, OSC and the single controls', function () {
        $s = vt("ab\u{9b}1;3H\u{9d}2;t\u{9c}c\u{85}d", 5, 3);
        expect(rows($s))->toBe('abc  |d    |     ')
            ->and($s->title())->toBe('t');
    });
});

describe('replies', function () {
    it('answers DSR status and cursor position, and device attributes', function () {
        $s = vt("\e[5n\e[2;3H\e[6n\e[c\e[>c\e[?6n");
        expect($s->input->peek())->toBe("\e[0n\e[2;3R\e[?62;4;22c\e[>1;10;0c\e[?2;3R");
    });

    it('reports the text area size and private modes', function () {
        $s = vt("\e[18t\e[?2004h\e[?2004\$p\e[?25\$p\e[?9999\$p", 20, 5);
        expect($s->input->peek())->toBe("\e[8;5;20t\e[?2004;1\$y\e[?25;1\$y\e[?9999;0\$y");
    });
});

describe('scrollback', function () {
    it('keeps lines that scroll off the top, newest at row -1', function () {
        $s = vt("1\r\n2\r\n3\r\n4", 3, 2);
        expect($s->scrollbackSize())->toBe(2)
            ->and($s->line(-1))->toBe('2')
            ->and($s->line(-2))->toBe('1')
            ->and($s->linesScrolled())->toBe(2);
    });

    it('limits the scrollback', function () {
        $s = new Surface(3, 1, scrollback: 3);
        $s->write("1\r\n2\r\n3\r\n4\r\n5\r\n6");
        expect($s->scrollbackSize())->toBe(3)
            ->and($s->line(-3))->toBe('3')
            ->and($s->linesScrolled())->toBe(5);
        expect(fn() => $s->line(-4))->toThrow(OutOfRangeException::class);
    });

    it('keeps no scrollback when the limit is 0', function () {
        expect((new Surface(3, 1, scrollback: 0))->scrollbackSize())->toBe(0);
    });
});

describe('keyboard protocols and terminal queries', function () {
    it('keeps a kitty keyboard flag stack per screen and answers the query', function () {
        $s = vt("\e[?u\e[>1u\e[?u\e[>3u\e[?u\e[<u\e[?u");
        expect($s->input->peek())->toBe("\e[?0u\e[?1u\e[?3u\e[?1u")
            ->and($s->keyboardFlags())->toBe(1);
        $s->write("\e[?1049h");
        expect($s->keyboardFlags())->toBe(0);
        $s->write("\e[=5u\e[=2;3u");
        expect($s->keyboardFlags())->toBe(5);
        $s->write("\e[?1049l\e[<5u");
        expect($s->keyboardFlags())->toBe(0);
    });

    it('records modifyOtherKeys', function () {
        $s = vt("\e[>4;2m");
        expect($s->modifyOtherKeys())->toBe(2);
        $s->write("\e[>4m");
        expect($s->modifyOtherKeys())->toBe(0);
        $s->write("\e[>4;1m\e[>4n");
        expect($s->modifyOtherKeys())->toBe(0);
    });

    it('answers XTVERSION', function () {
        expect(vt("\e[>q")->input->peek())->toBe("\eP>|phasync-tui(0.0.1)\e\\");
    });

    it('answers color queries with the same terminator', function () {
        $s = vt("\e]11;?\x07\e]10;?\e\\");
        expect($s->input->peek())->toBe("\e]11;rgb:0000/0000/0000\x07\e]10;rgb:e5e5/e5e5/e5e5\e\\");
        $s = new Surface(10, 1);
        $s->setDefaultColors(Color::rgb(0, 0, 0), Color::rgb(255, 255, 255));
        $s->write("\e]10;?;?\x07");
        expect($s->input->peek())->toBe("\e]10;rgb:0000/0000/0000\x07\e]11;rgb:ffff/ffff/ffff\x07");
    });

    it('passes clipboard writes (OSC 52) on as an event', function () {
        $s = new Surface(10, 1);
        $copied = [];
        $s->on('clipboard', function (string $text) use (&$copied) { $copied[] = $text; });
        $s->write("\e]52;c;" . base64_encode('hello') . "\x07\e]52;c;?\x07\e]52;c;!!\x07");
        expect($copied)->toBe(['hello'])
            ->and($s->input->peek())->toBe('');
    });

    it('records focus reporting and synchronized output and reports them to DECRQM', function () {
        $s = vt("\e[?1004h\e[?2026h\e[?2026\$p");
        expect($s->mode(1004))->toBeTrue()
            ->and($s->mode(2026))->toBeTrue()
            ->and($s->input->peek())->toBe("\e[?2026;1\$y");
    });
});
