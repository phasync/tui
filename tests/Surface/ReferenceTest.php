<?php

use phasync\Tui\Surface;
use phasync\Tui\Tests\Support\Tmux;

/**
 * Known differences between tmux and xterm, which the Surface follows and which are therefore
 * left out of these cases and covered by known-answer tests instead:
 * - BS with a wrap pending: xterm moves to the second-to-last column, tmux stays in the last.
 * - HPR (CSI a) and VPR (CSI e): tmux ignores them.
 * - DEC line drawing: capture-pane prints the raw ACS characters (q, x) instead of ─ and │.
 *
 * The same bytes through tmux and through a Surface must give the same screen: text, cursor and
 * styles (tmux's styled capture is replayed into a second Surface and compared cell by cell).
 */
function compareWithTmux(string $bytes, int $cols = 30, int $rows = 8): void
{
    $ref = Tmux::render($bytes, $cols, $rows);
    $s = new Surface($cols, $rows);
    $s->write($bytes);
    expect(rtrim($s->text(), "\n"))->toBe($ref['text'], 'text differs from tmux')
        ->and([$s->cursor()->x, $s->cursor()->y])->toBe([$ref['x'], $ref['y']], 'cursor differs from tmux');
    $replay = new Surface($cols, $rows);
    $replay->write(Tmux::replayable($ref['ansi']));
    expect($s->dump())->toBe($replay->dump(), 'styles differ from tmux');
}

$cases = [
    'plain text and newlines' => "hello\r\nworld\r\n",
    'autowrap and pending wrap' => str_repeat('abcdefghij', 7) . "\r\nX" . str_repeat('y', 27) . "\x08Z",
    'scrolling with LF' => implode("\r\n", range(1, 20)),
    'tabs' => "a\tb\tc\r\n\t\t\t\t\tX\r\n12345678\tY",
    'tab stops set and cleared' => "\e[5G\eH\e[12G\eH\r\tA\tB\e[3g\r\nx\ty",
    'cursor movement' => "\e[3;4HA\e[2AB\e[5CC\e[3DD\e[2BE\e[EF\e[2FG\e[10GH\e[7dI\e[2`J\e[3CK\e[BL",
    'clamping' => "\e[99;99HX\e[99AY\e[99DZ",
    'erase display' => str_repeat("0123456789\r\n", 7) . "\e[4;5H\e[J\e[2;3H\e[1J",
    'erase line' => "abcdefghij\e[5G\e[K\r\nabcdefghij\e[5G\e[1K\r\nabcdefghij\e[5G\e[2Kx",
    'erase with background' => "\e[42mab\e[K\r\n\e[44m\e[2Kx\e[0m",
    'ECH, ICH, DCH' => "abcdefghij\e[3G\e[2X\r\nabcdefghij\e[3G\e[3@\r\nabcdefghij\e[3G\e[3P",
    'IL and DL' => implode("\r\n", range(1, 8)) . "\e[3;1H\e[2L\e[6;1H\e[M",
    'scroll region' => "\e[3;6r" . implode("\r\n", range(1, 12)) . "\e[3;1H\eM\eMtop",
    'SU and SD' => implode("\r\n", range(1, 8)) . "\e[2S\e[3T",
    'origin mode' => "\e[2;5r\e[?6h\e[HA\e[10;10HB\e[?6l\e[HC",
    'SGR attributes' => "\e[1mb\e[2md\e[3mi\e[4mu\e[5mk\e[7mr\e[9ms\e[0mn\e[22;23;24;25;27;29mx",
    'SGR colors' => "\e[31ma\e[42mb\e[93mc\e[104md\e[38;5;123me\e[48;5;200mf\e[38;2;10;20;30mg\e[39;49mh",
    'SGR colon forms' => "\e[38:5:99ma\e[48:2::10:20:30mb\e[4:3mc\e[0m",
    'wide characters' => "a中b文c\r\n" . str_repeat('x', 29) . "中\r\n中文\e[1;2HX",
    'combining characters' => "e\u{0301}a\u{0308}x\r\nn\u{0303}",
    'save and restore cursor' => "\e[3;3H\e[1;31m\e7\e[H\e[0mx\e8y\e[s\e[5;5Hz\e[uw",
    'alternate screen' => "main\r\nline\e[?1049h\e[Halt screen\e[3;3Hx\e[?1049ltail",
    'insert mode' => "abcdef\r\e[4hXY\e[4lZ",
    'reverse index at top' => "1\r\n2\r\n3\e[H\eM\eMx",
    'index and next line' => "ab\eDcd\eEef",
    'REP' => "ab\e[5bc\r\n-\e[29b",
    'OSC title and strings' => "\e]2;the title\x07a\e]0;other\e\\b\e]8;;http://x\e\\c\e]8;;\e\\",
    'DCS and APC ignored' => "a\eP1\$r0m\e\\b\e_Gx=1;AAAA\e\\c\e^pm\e\\d",
    'unknown sequences' => "a\e[5;6~b\e[>4;2mc\e[=5ud\e[?1;2\$pe\e%Gf",
    'cancel and abort' => "a\e[31\x18b\e[1\x1ac\e[33\e[1md\e[0m",
    'embedded controls in CSI' => "abcdef\e[2\x08D!\r\nx\e[2\nCy",
    'backspace at the left margin' => "\x08\x08a\x08\x08b\r\n" . str_repeat('z', 28) . "\x08\x08q",
    'cursor visibility' => "\e[?25lhidden",
    'wrap off' => "\e[?7l" . str_repeat('a', 35) . "\e[?7h\r\n" . str_repeat('b', 35),
    'wide char at last column' => str_repeat('-', 29) . '中x',
    'DECALN' => "\e#8\e[2;2Hx",
    'RIS' => "abc\e[31m\e[2;5r\ecx",
];

foreach ($cases as $name => $bytes) {
    it("matches tmux: $name", fn() => compareWithTmux($bytes))->skip(!Tmux::available(), 'tmux is not installed');
}

it('matches tmux on a small surface that scrolls a lot', function () {
    $bytes = '';
    for ($i = 0; $i < 200; $i++) {
        $bytes .= "\e[3" . ($i % 8) . "mline $i " . str_repeat('#', $i % 17) . "\e[0m\r\n";
    }
    compareWithTmux($bytes, 12, 4);
})->skip(!Tmux::available(), 'tmux is not installed');
