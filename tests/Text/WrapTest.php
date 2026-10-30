<?php

use phasync\Tui\Surface;
use phasync\Tui\Text;

it('measures and cuts styled text by what it shows', function () {
    expect(Text::width("\e[1;31mred\e[0m"))->toBe(3)
        ->and(Text::width("a\e]2;title\x07b"))->toBe(2)
        ->and(Text::truncate("\e[1mhello world\e[0m", 6))->toBe("\e[1mhello…\e[0m")
        ->and(Text::pad("\e[2mab\e[0m", 4))->toBe("\e[2mab\e[0m  ");
});

it('wraps by display width and grapheme cluster', function () {
    expect(Text::wrap('abcdefghij', 4))->toBe(['abcd', 'efgh', 'ij'])
        ->and(Text::wrap('ab中文', 3))->toBe(['ab', '中', '文'])            // a wide character does not split
        ->and(Text::wrap('👍👍👍', 3))->toBe(['👍', '👍', '👍'])
        ->and(Text::wrap("e\u{301}e\u{301}e\u{301}", 2))->toBe(["e\u{301}e\u{301}", "e\u{301}"])
        ->and(Text::wrap("one\ntwo\r\n", 10))->toBe(['one', 'two', ''])
        ->and(Text::wrap('', 10))->toBe(['']);
});

it('wraps at spaces with word wrapping, breaking only words longer than a row', function () {
    expect(Text::wrap('hello world foo', 7, true))->toBe(['hello', 'world', 'foo'])
        ->and(Text::wrap('the quick brown fox', 10, true))->toBe(['the quick', 'brown fox'])
        ->and(Text::wrap('aa bbbbbbbbbbbb c', 5, true))->toBe(['aa', 'bbbbb', 'bbbbb', 'bb c'])
        ->and(Text::wrap('  indented text here', 10, true))->toBe(['  indented', 'text here'])
        ->and(Text::wrap('wide 中文中文 words', 6, true))->toBe(['wide', '中文中', '文', 'words']);
});

it('keeps escape sequences in the rows, so the rows show the styles of the text', function () {
    $text = "\e[31mred and still red\e[0m plain";
    $rows = Text::wrap($text, 8, true);
    expect($rows)->toBe(["\e[31mred and", 'still', "red\e[0m", 'plain']);
    $s = new Surface(8, 4);
    $s->write(implode("\r\n", $rows));
    expect($s->dump())->toBe("[fg=1]red and[/]\n[fg=1]still[/]\n[fg=1]red[/]\nplain");
});

it('lays text out as the surface does: character wrapping matches autowrap', function () {
    $samples = ['plain ascii text that goes on', "中文字符 and 👍🏽 emoji, e\u{301} too", str_repeat('ab中', 9)];
    foreach ($samples as $text) {
        foreach ([3, 5, 7, 11] as $cols) {
            $s = new Surface($cols, 30);
            $s->write($text);
            $rows = Text::wrap($text, $cols);
            foreach ($rows as $y => $row) {
                expect($s->line($y))->toBe(rtrim($row), "$cols columns, row $y of '$text'");
            }
        }
    }
});
