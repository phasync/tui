<?php

/*
 * Markdown through the terminal: every block kind, wrapping at 40 columns, wide characters,
 * links, highlighting, scrolling with the keys and the wheel, following the tail, and streaming
 * in chunks that end anywhere.
 */

use phasync\Tui\Component\Markdown;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Style;
use phasync\Tui\Stylesheet;

const MD_DOC = <<<'MD'
# Heading *one*

Intro with **bold**, *emphasis*, ~~gone~~ and `code` in a paragraph that is long enough to wrap
at forty columns, with a [link](https://example.com/page) inside it.

## Second level

> A quote
> over two lines
>
> - with a list

- apple
- banana
  - nested one
  - nested two
- cherry

1. first
2. second
   1. inner

```php
<?php
// a comment
$x = "text" . 42; // trailing
function f() { return true; }
```

| Name | Qty | Price |
|:-----|:---:|------:|
| tea  | 2   | 3.50  |
| milk | 10  | 12.00 |

---

Setext heading
==============

    indented code

Hard  
break and the end.
MD;

function md(Markdown $md, int $cols, int $rows, Closure $test): void
{
    mountOn($md, $cols, $rows, function (FakeTerminal $t, Markdown $md) use ($test) {
        frame();
        $test($t, $md);
    });
}

function mdRows(FakeTerminal $t): array
{
    return explode("\n", $t->text());
}

it('renders headings, paragraphs, emphasis and code spans with their parts styled', function () {
    md(new Markdown(MD_DOC), 40, 60, function (FakeTerminal $t) {
        $rows = mdRows($t);
        expect($rows[0])->toBe('Heading one')
            ->and($rows[2])->toBe('Intro with bold, emphasis, gone and code')
            ->and($t->dump())->toContain('[bold underline fg=6]Heading [/][bold italic underline fg=6]one[/]')
            ->and($t->dump())->toContain('[bold]bold[/]')->toContain('[italic]emphasis[/]')->toContain('[strike]gone[/]')
            ->and($t->dump())->toContain('[fg=3]code[/]')->toContain('[bold fg=6]Second level[/]');
    });
});

it('wraps paragraphs between words at 40 columns, every row within the width', function () {
    md(new Markdown(MD_DOC), 40, 60, function (FakeTerminal $t) {
        foreach (mdRows($t) as $row) {
            expect(mb_strwidth($row))->toBeLessThanOrEqual(40);
        }
        $text = $t->text();
        expect($text)->toContain("gone and code\nin a paragraph that is long enough to\nwrap at forty columns")->not->toContain('to wrap');
    });
    md(new Markdown('one two three four five six seven eight nine ten'), 20, 4, function (FakeTerminal $t) {
        expect(mdRows($t))->toBe(['one two three four', 'five six seven eight', 'nine ten', '']);
    });
});

it('shows a link with its address dimmed after the text', function () {
    md(new Markdown('See [the docs](https://x.org/a) now, <https://y.org> too.'), 60, 2, function (FakeTerminal $t) {
        expect(mdRows($t)[0])->toBe('See the docs (https://x.org/a) now, https://y.org too.')
            ->and($t->dump())->toContain('[underline fg=6]the docs[/][dim fg=8] (https://x.org/a)[/]');
    });
});

it('can show links as OSC 8 hyperlinks instead: the text alone is on the screen', function () {
    md(new Markdown('See [the docs](https://x.org/a) now, a long one: [wrapping link text here](https://x.org/b).', hyperlinks: true), 30, 3, function (FakeTerminal $t) {
        expect(mdRows($t))->toBe(['See the docs now, a long one:', 'wrapping link text here.', ''])
            ->and($t->surface->cell(4, 0)->style->link)->toBe('https://x.org/a')
            ->and($t->surface->cell(13, 0)->style->link)->toBe('')
            ->and($t->surface->cell(0, 1)->style->link)->toBe('https://x.org/b')       // the link goes on after the wrap
            ->and($t->surface->cell(22, 1)->style->link)->toBe('https://x.org/b')
            ->and($t->surface->cell(23, 1)->style->link)->toBe('')
            ->and($t->output())->toContain("\e]8;;https://x.org/a\e\\the docs");
    });
});

it('renders bullet and numbered lists with nesting', function () {
    md(new Markdown("- apple\n- banana\n  - nested\n    1. deep\n- cherry\n\n1. one\n2. two\n10. ten"), 30, 14, function (FakeTerminal $t) {
        expect(mdRows($t))->toMatchArray(['• apple', '• banana', '  ◦ nested', '    1. deep', '• cherry', '', '1. one', '2. two'])
            ->and(mdRows($t)[8])->toBe('3. ten');
    });
});

it('wraps a long list item under its marker', function () {
    md(new Markdown('- alpha beta gamma delta epsilon zeta eta theta'), 20, 4, function (FakeTerminal $t) {
        expect(mdRows($t))->toBe(['• alpha beta gamma', '  delta epsilon zeta', '  eta theta', '']);
    });
});

it('renders block quotes with a bar, nested blocks inside', function () {
    md(new Markdown("> quoted *text*\n> more\n>\n> - item\n\nafter"), 30, 8, function (FakeTerminal $t) {
        expect(mdRows($t))->toMatchArray(['│ quoted text more', '│', '│ • item', '', 'after'])
            ->and($t->dump())->toContain('[fg=8]│ [/]');
    });
});

it('renders fenced code blocks filled across the width, highlighting php, js, sh and json', function () {
    md(new Markdown("```php\n<?php\n// c\n\$x = \"s\" . 42;\nreturn true;\n```"), 24, 6, function (FakeTerminal $t) {
        $d = $t->dump();
        expect(mdRows($t)[2])->toBe(' $x = "s" . 42;')
            ->and($d)->toContain('[fg=5 bg=8]<?php[/]')->toContain('[italic fg=8 bg=8]// c[/]')->toContain('[fg=6 bg=8]$x[/]')
            ->and($d)->toContain('[fg=2 bg=8]"s"[/]')->toContain('[fg=3 bg=8]42[/]')->toContain('[fg=5 bg=8]return[/]');
    });
    md(new Markdown("```js\nconst a = `x` + 1; // c\n```\n```sh\necho \$HOME --flag # c\n```\n```json\n{\"k\": [true, 2]}\n```\n``` weird\nplain\n```"), 40, 12, function (FakeTerminal $t) {
        $d = $t->dump();
        expect($d)->toContain('[fg=5 bg=8]const[/]')->toContain('[fg=2 bg=8]`x`[/]')
            ->and($t->text())->toContain(' echo $HOME --flag # c');
        expect($d)->toContain('[fg=6 bg=8]$HOME[/]')->toContain('[fg=3 bg=8]--flag[/]')
            ->and($d)->toContain('[fg=6 bg=8]"k"[/]')->toContain('[fg=5 bg=8]true[/]')->toContain('[fg=3 bg=8]2[/]')
            ->and($t->text())->toContain(' plain');
    });
});

it('keeps an unterminated fence as code running to the end', function () {
    md(new Markdown("text\n\n```js\nlet x = 1;"), 20, 5, function (FakeTerminal $t) {
        expect(mdRows($t)[2])->toBe(' let x = 1;')->and($t->dump())->toContain('[fg=5 bg=8]let[/]');
    });
});

it('renders tables with alignment, borders, and shrinks cells to the width', function () {
    md(new Markdown("| Name | Qty | Price |\n|:-----|:---:|------:|\n| tea | 2 | 3.50 |\n| milk | 10 | 12.00 |"), 40, 8, function (FakeTerminal $t) {
        expect(mdRows($t))->toMatchArray([
            '┌──────┬─────┬───────┐',
            '│ Name │ Qty │ Price │',
            '├──────┼─────┼───────┤',
            '│ tea  │  2  │  3.50 │',
            '│ milk │ 10  │ 12.00 │',
            '└──────┴─────┴───────┘',
        ])->and($t->dump())->toContain('[bold]Name[/]');
    });
    md(new Markdown("| a | b |\n|---|---|\n| one two three four five six | x |"), 20, 12, function (FakeTerminal $t) {
        foreach (mdRows($t) as $row) {
            expect(mb_strwidth($row))->toBeLessThanOrEqual(20);
        }
        expect($t->text())->toContain('three')->toContain('six');
    });
});

it('renders thematic breaks, setext headings, indented code and hard breaks', function () {
    md(new Markdown("Title\n=====\n\n---\n\n    code here\n\nline  \nbreak"), 12, 10, function (FakeTerminal $t) {
        expect(mdRows($t))->toMatchArray(['Title', '', '────────────', '', ' code here', '', 'line', 'break']);
    });
});

it('measures wide characters and emoji by display width, in text, lists and tables', function () {
    md(new Markdown("中文字符中文字符中文字符 😀😀😀😀 abc 中文字符中文字符\n\n- 😀 中文字符中文字符中文字符中文字符\n\n| 名前 | x |\n|---|---|\n| 😀😀 | 中 |"), 20, 16, function (FakeTerminal $t) {
        foreach (mdRows($t) as $row) {
            expect(mb_strwidth($row))->toBeLessThanOrEqual(20);
        }
        expect($t->text())->toContain("中文字符中文字符中文\n字符 😀😀😀😀 abc\n中文字符中文字符")
            ->and($t->text())->toContain("• 😀\n  中文字符中文字符中")->toContain('│ 😀😀 │ 中 │')->toContain('│ 名前 │ x  │');
    });
});

it('drops escape sequences and control characters from the text', function () {
    md(new Markdown("evil \e]0;title\x07 \e[31mred\e[0m and \x00 more"), 40, 2, function (FakeTerminal $t) {
        expect(mdRows($t)[0])->toBe('evil ]0;title [31mred[0m and  more');
    });
});

it('is styled through the stylesheet, one selector per block kind', function () {
    $md = new Markdown("# H\n\ntext `c`\n\n```sh\nif x\n```");
    $md->setStylesheet(Stylesheet::default()->with(['Markdown::h1' => ['color' => 'red', 'underline' => false], 'Markdown::code' => ['color' => 'green'], 'Markdown::codeblock' => ['background' => 'blue'], 'Markdown::keyword' => ['color' => 'yellow']]));
    md($md, 20, 6, function (FakeTerminal $t) {
        expect($t->dump())->toContain('[bold fg=1]H[/]')->toContain('[fg=2]c[/]')->toContain('[fg=3 bg=4]if[/]');
    });
});

it('scrolls with the keys and the wheel, and lets a key go on at the edges', function () {
    $doc = implode("\n\n", array_map(fn($i) => "para $i", range(1, 20)));
    md(new Markdown($doc), 20, 5, function (FakeTerminal $t, Markdown $md) {
        expect(mdRows($t)[0])->toBe('para 1')->and($md->rowCount())->toBe(39)->and($md->lineUp())->toBeFalse();
        $t->press('down', 'down');
        frame();
        expect(mdRows($t)[0])->toBe('para 2')->and($md->scrollTop())->toBe(2);
        $t->press('pagedown');
        frame();
        expect($md->scrollTop())->toBe(6)->and(mdRows($t)[0])->toBe('para 4');
        $t->press('end');
        frame();
        expect(mdRows($t)[4])->toBe('para 20')->and($md->lineDown())->toBeFalse();
        $t->scroll(3, 2, -2);
        frame();
        expect($md->scrollTop())->toBe(34 - 6);
        $t->scroll(3, 2, 1);
        $t->press('home');
        frame();
        expect($md->scrollTop())->toBe(0)->and(mdRows($t)[0])->toBe('para 1');
        $md->bind(['j' => 'lineDown', 'k' => 'lineUp']);           // the keys of less, if you want them
        $t->press('j');
        expect($md->scrollTop())->toBe(1);
        $t->press('k', 'k');
        expect($md->scrollTop())->toBe(0);
    });
});

it('shows a scrollbar in its last column, the text laid out beside it', function () {
    $doc = implode("\n\n", array_map(fn($i) => "para $i", range(1, 10)));
    md(new Markdown($doc, scrollbar: true), 12, 4, function (FakeTerminal $t, Markdown $md) {
        expect(array_map(fn($r) => mb_substr($r, -1), mdRows($t)))->toBe(['┃', '│', '│', '│'])
            ->and(mdRows($t)[0])->toStartWith('para 1');
        $md->toBottom();
        frame();
        expect(array_map(fn($r) => mb_substr($r, -1), mdRows($t)))->toBe(['│', '│', '│', '┃'])
            ->and(rtrim(mdRows($t)[3], '┃ '))->toBe('para 10');
        $md->set('short');
        frame();
        expect(mdRows($t))->toBe(['short', '', '', '']);
    });
});

it('follows the tail while text arrives, until the user scrolls back', function () {
    md(new Markdown('', tail: true), 20, 4, function (FakeTerminal $t, Markdown $md) {
        $scrolls = 0;
        $md->on('scroll', function () use (&$scrolls) { $scrolls++; });
        for ($i = 1; $i <= 6; $i++) {
            $md->append("line $i\n\n");
        }
        frame();
        expect(mdRows($t))->toBe(['', 'line 5', '', 'line 6'])->and($md->isFollowing())->toBeTrue()->and($scrolls)->toBeGreaterThan(0);
        $t->press('up', 'up', 'up');
        $md->append("line 7\n\n");
        frame();
        expect($md->isFollowing())->toBeFalse()->and(mdRows($t)[0])->toBe('line 3');
        $t->press('end');
        frame();
        expect($md->isFollowing())->toBeTrue();
        $md->append('line 8');
        frame();
        expect(mdRows($t))->toContain('line 8');
        $md->scrollTo(0);
        $md->follow();
        frame();
        expect($md->isFollowing())->toBeTrue()->and(mdRows($t)[3])->toBe('line 8');
    });
});

it('does not follow without tail mode', function () {
    md(new Markdown(''), 20, 3, function (FakeTerminal $t, Markdown $md) {
        $md->append("a\n\nb\n\nc\n\nd\n");
        frame();
        expect(mdRows($t)[0])->toBe('a')->and($md->isFollowing())->toBeFalse();
    });
});

it('lays out again when the terminal is resized', function () {
    md(new Markdown('one two three four five six seven eight'), 30, 4, function (FakeTerminal $t, Markdown $md) {
        expect(mdRows($t)[0])->toBe('one two three four five six');
        $t->resize(12, 4);
        frame();
        expect(mdRows($t))->toBe(['one two', 'three four', 'five six', 'seven eight']);
    });
});

it('renders an unfinished construct at the tail as well as the text so far allows', function () {
    $md = new Markdown('');
    md($md, 60, 8, function (FakeTerminal $t, Markdown $md) {
        $md->append('A **bold and *emph');
        frame();
        expect(mdRows($t)[0])->toBe('A **bold and *emph');
        $md->append('asis** done, [a li');
        frame();
        expect(mdRows($t)[0])->toBe('A bold and *emphasis done, [a li');
        $md->append("nk](http://u)\n\n```php\n\$a = ");
        frame();
        expect($t->text())->toContain('A bold and *emphasis done, a link (http://u)')->toContain(' $a =')
            ->and($t->dump())->toContain('[fg=6 bg=8]$a[/]');
    });
});

it('streams a long document in random chunks to the same screen as set()', function () {
    $doc = str_repeat(MD_DOC . "\n\n", 6) . "😀 中文 end\n";
    foreach ([1, 7, 2024] as $seed) {
        mt_srand($seed);
        $chunked = null;
        mountOn(new Markdown('', tail: true), 40, 25, function (FakeTerminal $t, Markdown $md) use ($doc, &$chunked) {
            for ($at = 0; $at < \strlen($doc);) {
                $n = mt_rand(1, 40);
                $md->append(substr($doc, $at, $n));
                $at += $n;
                if (mt_rand(0, 3) === 0) {
                    frame();
                }
            }
            frame();
            $chunked = [$t->text(), $t->dump(), $md->rowCount()];
        });
        $whole = null;
        mountOn(new Markdown('', tail: true), 40, 25, function (FakeTerminal $t, Markdown $md) use ($doc, &$whole) {
            $md->set($doc);
            frame();
            $whole = [$t->text(), $t->dump(), $md->rowCount()];
        });
        expect($chunked)->toBe($whole)->and($chunked[2])->toBeGreaterThan(100);
    }
});

it('writes only what changed when text arrives at the tail', function () {
    $doc = implode("\n\n", array_map(fn($i) => "paragraph number $i", range(1, 8)));
    md(new Markdown($doc), 30, 20, function (FakeTerminal $t, Markdown $md) {
        $t->text();
        $t->takeOutput();
        $md->append(' and more');
        frame();
        $t->text();
        $out = $t->takeOutput();
        expect($out)->toContain('more')->not->toContain('paragraph number 1')->not->toContain('number 7');
    });
});

it('replaces all text with set() and reads it back', function () {
    md(new Markdown('# old'), 20, 3, function (FakeTerminal $t, Markdown $md) {
        $md->set('new *text*');
        frame();
        expect(mdRows($t)[0])->toBe('new text')->and($md->text())->toBe('new *text*');
    });
});

it('draws in its own style: text outside every part, the fill and the cleared rows', function () {
    $md = new Markdown("plain **bold**\n\n- item");
    $md->setStylesheet(Stylesheet::default()->with(['Markdown' => ['background' => 'blue', 'color' => 'white']]));
    md($md, 20, 4, function (FakeTerminal $t) {
        $t->refresh();
        foreach ([[0, 0], [7, 0], [19, 0], [19, 1], [5, 3]] as [$x, $y]) {
            expect($t->surface->cell($x, $y)->style->bg)->toBe(4);
        }
        expect($t->surface->cell(0, 0)->style->fg)->toBe(7)->and($t->surface->cell(6, 0)->style->attrs & Style::BOLD)->toBe(Style::BOLD);
    });
});
