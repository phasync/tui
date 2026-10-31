<?php

use phasync\Tui\Component;
use phasync\Tui\Component\ListView;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\TextInput;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Mouse;
use phasync\Tui\Split;

use function fiber\run;
use function fiber\sleep;

/** Start $component on a FakeTerminal of $cols x $rows, run $test, detach. */
function onScreen(Component $component, int $cols, int $rows, Closure $test): void
{
    run(function () use ($component, $cols, $rows, $test) {
        $t = new FakeTerminal($cols, $rows);
        $component->start($t);
        try {
            $test($t, $component);
        } finally {
            $t->detach();
        }
    });
}

describe('Log', function () {
    it('keeps its lines and lays them out again when the terminal narrows and widens', function () {
        onScreen(new Log(), 12, 4, function (FakeTerminal $t, Log $log) {
            $log->append("0123456789ab\nshort\n");
            expect($t->text())->toBe("0123456789ab\nshort\n\n");
            $t->resize(6, 4);
            expect($t->text())->toBe("012345\n6789ab\nshort\n");
            $t->resize(12, 4);
            expect($t->text())->toBe("0123456789ab\nshort\n\n")
                ->and($log->lines())->toBe(['0123456789ab', 'short']);
        });
    });

    it('shows lines appended before it got its size laid out for the size it got', function () {
        $app = new class extends Component {
            public ?Log $log = null;

            protected function mount(): void
            {
                $this->log = $this->child('log', fn() => new Log());
                $this->log->append(str_repeat('x', 30) . "\n");     // at the guessed 80 columns
            }

            protected function render(): ?Layout
            {
                return Split::horizontal()->add($this->log->surface, 10);
            }
        };
        onScreen($app, 20, 4, function (FakeTerminal $t) {
            expect($t->text())->toBe(str_repeat("xxxxxxxxxx\n", 3) . "");
        });
    });

    it('wraps between words when asked, by display width', function () {
        onScreen(new Log(wordWrap: true), 10, 4, function (FakeTerminal $t, Log $log) {
            $log->append("the quick brown 中文字 fox\n");
            expect($t->text())->toBe("the quick\nbrown\n中文字 fox\n");
            // the open line is laid out again as it grows
            $log->append('hello');
            $log->append(' world again');
            expect($t->text())->toBe("中文字 fox\nhello\nworld\nagain")
                ->and($log->lines())->toBe(['the quick brown 中文字 fox', 'hello world again']);
            $log->setWordWrap(false);
            expect($t->text())->toBe("brown 中文\n字 fox\nhello worl\nd again");
        });
    });

    it('keeps escape sequences as given, and their styles across a new layout', function () {
        onScreen(new Log(), 8, 3, function (FakeTerminal $t, Log $log) {
            $log->append("\e[31mred line that wraps\e[0m\nplain\n");
            expect($log->lines())->toBe(["\e[31mred line that wraps\e[0m", 'plain']);
            $t->resize(10, 3);
            expect($t->dump())->toBe("[fg=1]hat wraps[/]\nplain\n");
        });
    });

    it('replaces its lines with setLines(), laid out once, and clears', function () {
        onScreen(new Log(), 10, 3, function (FakeTerminal $t, Log $log) {
            $log->append("one\ntwo\n");
            $log->setLines(array_map(fn($l) => "\e[1m$l\e[0m", $log->lines()));
            expect($t->dump())->toBe("[bold]one[/]\n[bold]two[/]\n")
                ->and($log->lines())->toBe(["\e[1mone\e[0m", "\e[1mtwo\e[0m"]);
            $log->append('three');
            expect($t->text())->toBe("one\ntwo\nthree");
            $log->clear();
            expect($t->text())->toBe("\n\n")->and($log->lines())->toBe([]);
        });
    });

    it('keeps a view scrolled back on the same line when the lines are filtered, else on the same line number', function () {
        onScreen(new Log(), 20, 3, function (FakeTerminal $t, Log $log) {
            $all = array_map(fn($i) => ($i % 2 ? 'odd ' : 'even ') . $i, range(1, 30));
            $log->setLines($all);
            $log->scrollBy(-10);
            expect($t->text())->toBe("odd 19\neven 20\nodd 21");
            $log->setLines(array_values(array_filter($all, fn($l) => str_starts_with($l, 'odd'))));
            expect($t->text())->toBe("odd 19\nodd 21\nodd 23")->and($log->isFollowing())->toBeFalse();
            // "odd 19" is gone: the view stays at line 9 rather than jumping to the end
            $log->setLines(array_values(array_filter($all, fn($l) => str_starts_with($l, 'even'))));
            expect($log->isFollowing())->toBeFalse()->and($t->text())->toBe("even 20\neven 22\neven 24");
            $log->setLines($all);                   // "even 20" is there again
            expect($t->text())->toBe("even 20\nodd 21\neven 22");
            $log->follow();
            $log->setLines($all);                   // following: it keeps following
            expect($log->isFollowing())->toBeTrue()->and($t->text())->toBe("odd 29\neven 30\n");
        });
    });

    it('scrolls with the keys when focused, by rows from the oldest, and shows a scrollbar', function () {
        onScreen(new Log(scrollbar: true), 20, 4, function (FakeTerminal $t, Log $log) {
            $log->setLines(array_map(fn($i) => "line $i", range(1, 20)));     // 21 rows: the 20 lines and the cursor's
            $log->focus();
            $t->settle();
            expect($log->scrollTop())->toBe(17)->and($log->isFollowing())->toBeTrue()
                ->and(mb_substr(explode("\n", $t->text())[3], 19))->toBe('┃');      // the thumb at the bottom
            $t->press('pageup');
            $t->settle();
            expect($log->scrollTop())->toBe(14)->and($log->isFollowing())->toBeFalse()->and($t->text())->toStartWith('line 15');
            $t->press('home');
            $t->settle();
            expect($log->scrollTop())->toBe(0)->and($t->text())->toStartWith('line 1 ')
                ->and(mb_substr(explode("\n", $t->text())[0], 19))->toBe('┃')
                ->and(mb_substr(explode("\n", $t->text())[3], 19))->toBe('│');
            $log->scrollTo(5);
            expect($t->text())->toStartWith('line 6');
            $t->press('end');
            $t->settle();
            expect($log->isFollowing())->toBeTrue();
            $log->append("line 21\n");
            $t->settle();
            expect(explode("\n", $t->text())[2])->toStartWith('line 21');
        });
    });

    it('keeps a view scrolled back in its place when setLines() re-styles the lines (regression)', function () {
        onScreen(new Log(), 20, 3, function (FakeTerminal $t, Log $log) {
            $plain = array_map(fn($i) => "line $i", range(1, 30));
            $log->setLines($plain);
            $log->scrollToLine(10);
            expect($t->text())->toBe("line 11\nline 12\nline 13");
            // every line gets a new style: no text is the same, the place is
            $log->setLines(array_map(fn($l) => "\e[1m$l\e[0m", $plain));
            expect($t->text())->toBe("line 11\nline 12\nline 13")
                ->and($log->topLine())->toBe(10)->and($log->isFollowing())->toBeFalse();
            // one line edited in place, the one at the top
            $edited = $plain;
            $edited[10] = 'line 11 (edited)';
            $log->setLines($edited);
            expect($t->text())->toBe("line 11 (edited)\nline 12\nline 13");
        });
    });

    it('emits scroll when the lines in view change, for a component that shows them', function () {
        onScreen(new Log(), 20, 3, function (FakeTerminal $t, Log $log) {
            $seen = [];
            $off = $log->on('scroll', function (Log $l) use (&$seen) {
                $seen[] = [$l->topLine(), $l->bottomLine(), $l->isFollowing()];
            });
            $log->setLines(array_map(fn($i) => "line $i", range(1, 10)));
            expect($seen)->toBe([[8, 9, true]]);
            $log->append("line 11\n");              // following: the lines in view move
            expect(end($seen))->toBe([9, 10, true]);
            $log->scrollToLine(2);
            expect(end($seen))->toBe([2, 4, false]);
            $n = \count($seen);
            $log->append("line 12\n");              // scrolled back: nothing in view changes
            expect($seen)->toHaveCount($n);
            $t->scroll(1, 1, -1);                   // the wheel, three rows up
            expect(end($seen))->toBe([0, 2, false]);
            $log->follow();
            expect(end($seen))->toBe([10, 11, true]);
            $t->resize(20, 4);
            expect(end($seen))->toBe([9, 11, true]);
            $off();
            $log->scrollBy(-2);
            expect(end($seen))->toBe([9, 11, true]);
        });
    });

    it('keeps a view scrolled back on the same line when a resize lays the lines out again', function () {
        onScreen(new Log(), 10, 3, function (FakeTerminal $t, Log $log) {
            $log->setLines(array_map(fn($i) => "line $i " . str_repeat('x', $i % 3), range(1, 20)));
            $log->scrollBy(-8);
            expect($t->text())->toStartWith('line 11 xx');
            $t->resize(5, 3);
            expect($t->text())->toStartWith("line\n11 xx");
            $t->resize(20, 3);
            sleep(0.2);
            expect($t->text())->toStartWith('line 11 xx')->and($log->isFollowing())->toBeFalse();
        });
    });

    it('keeps only the newest lines, and lays out a long scrollback in two steps on resize', function () {
        onScreen(new Log(scrollback: 500), 20, 5, function (FakeTerminal $t, Log $log) {
            foreach (range(1, 600) as $i) {
                $log->append("line $i\n");
            }
            expect(\count($log->lines()))->toBe(499)->and($log->lines()[0])->toBe('line 102');
            $t->resize(4, 5);
            expect($t->text())->toBe("line\n 599\nline\n 600\n");      // the visible part at once
            sleep(0.2);                                                    // then the whole scrollback:
            $log->scrollTo(-10000);                                        // 500 rows, 2 per line now
            expect($t->text())->toStartWith("line\n 349");
        });
    });
});

describe('Log lines', function () {
    it('goes to a line and tells which lines are shown, whatever the wrapping', function () {
        onScreen(new Log(), 10, 3, function (FakeTerminal $t, Log $log) {
            // lines 0-29; every third one takes two rows at 10 columns
            $log->setLines(array_map(fn($i) => $i % 3 === 0 ? "L$i " . str_repeat('w', 10) : "L$i", range(0, 29)));
            expect($log->topLine())->toBe(28)->and($log->bottomLine())->toBe(29)   // following the end
                ->and($log->isFollowing())->toBeTrue();
            $log->scrollToLine(10);
            expect($t->text())->toStartWith("L10\nL11\nL12 www")
                ->and($log->topLine())->toBe(10)->and($log->bottomLine())->toBe(12)
                ->and($log->isFollowing())->toBeFalse();
            $log->scrollToLine(12);                 // a line of two rows at the top
            expect($t->text())->toStartWith("L12 wwwwww\nwwww\nL13")
                ->and($log->topLine())->toBe(12)->and($log->bottomLine())->toBe(13);
            $log->append("more\n");                // stays on its line while text arrives
            expect($log->topLine())->toBe(12);
            $t->resize(5, 3);                       // laid out again: still line 12 at the top
            expect($log->topLine())->toBe(12)->and($t->text())->toStartWith('L12 w');
            $t->resize(10, 3);
            $log->scrollToLine(1000);               // clamped: the end, following again
            expect($log->isFollowing())->toBeTrue()->and($log->bottomLine())->toBe(30);
            $log->scrollToLine(-5);
            expect($log->topLine())->toBe(0)->and($t->text())->toStartWith("L0 wwwwwww\nwww\nL1");
        });
    });

    it('counts lines in word wrap mode and for an empty log', function () {
        onScreen(new Log(wordWrap: true), 12, 2, function (FakeTerminal $t, Log $log) {
            expect($log->topLine())->toBe(0)->and($log->bottomLine())->toBe(0);
            $log->scrollToLine(3);                  // nothing to go to
            $log->append("one two three four five\nsix\nseven eight nine ten\nend\n");
            $log->scrollToLine(2);
            expect($t->text())->toBe("seven eight\nnine ten")
                ->and($log->topLine())->toBe(2)->and($log->bottomLine())->toBe(2);
            $log->scrollBy(-1);                     // one row back: line 1 at the top, line 2 cut at the bottom
            expect($log->topLine())->toBe(1)->and($log->bottomLine())->toBe(2);
        });
    });
});

describe('TextInput', function () {
    it('submits the text and walks through the history with Up and Down', function () {
        $submitted = [];
        $input = new TextInput(onSubmit: function (string $v, TextInput $i) use (&$submitted) {
            $submitted[] = $v;
            $i->setValue('');
        }, history: true);
        onScreen($input, 20, 1, function (FakeTerminal $t, TextInput $input) use (&$submitted) {
            $t->type("first\nsecond\n");
            $t->type('draft');
            $t->press('up');
            expect($input->value())->toBe('second');
            $t->press('up', 'up');
            expect($input->value())->toBe('first');
            $t->press('down');
            expect($input->value())->toBe('second');
            $t->press('down');
            expect($input->value())->toBe('draft');
            $t->press('up');
            $t->type(' again');
            $t->press('enter');
            expect($submitted)->toBe(['first', 'second', 'second again'])
                ->and($input->history())->toBe(['first', 'second', 'second again']);
            $input->setHistory(['old']);
            $t->press('up');
            expect($input->value())->toBe('old');
        });
    });

    it('leaves Up and Down to the parent without history', function () {
        $input = new TextInput('x');
        onScreen($input, 10, 1, function (FakeTerminal $t, TextInput $input) {
            $input->surface->on('key', fn() => null);
            $t->press('up');
            expect($input->value())->toBe('x')->and($input->surface->input->peek())->toBe("\e[A");
        });
    });
});

describe('ListView', function () {
    it('selects with the keys, scrolls the selection into view and announces it', function () {
        $events = [];
        $items = [];
        foreach (range(1, 10) as $i) {
            $items["k$i"] = "item $i";
        }
        $list = new ListView($items, onSelect: function ($key, $label) use (&$events) {
            $events[] = "select $key";
        }, onActivate: function ($key) use (&$events) {
            $events[] = "activate $key";
        });
        onScreen($list, 8, 3, function (FakeTerminal $t, ListView $list) use (&$events) {
            expect($t->dump())->toBe("[inverse]item 1  [/]\nitem 2\nitem 3")->and($list->selected())->toBe('k1');
            $t->press('down', 'down', 'down');
            frame();
            expect($t->text())->toBe("item 2\nitem 3\nitem 4")->and($list->selected())->toBe('k4');
            $t->press('end');
            frame();
            expect($t->text())->toBe("item 8\nitem 9\nitem 10");
            $t->press('pageup', 'enter');
            frame();
            expect($list->selected())->toBe('k8')->and($list->selectedLabel())->toBe('item 8')
                ->and($events)->toBe(['select k2', 'select k3', 'select k4', 'select k10', 'select k8', 'activate k8']);
        });
    });

    it('selects with a click, activates with a second one, and scrolls with the wheel', function () {
        $events = [];
        $list = new ListView(['a', 'b', 'c', 'd', 'e'], onSelect: function ($key) use (&$events) {
            $events[] = "select $key";
        }, onActivate: function ($key) use (&$events) {
            $events[] = "activate $key";
        });
        onScreen($list, 5, 2, function (FakeTerminal $t, ListView $list) use (&$events) {
            $t->click(0, 1);
            $t->click(0, 1);
            $t->scroll(0, 0, 1);
            frame();
            expect($events)->toBe(['select 1', 'activate 1'])->and($t->text())->toBe("d\ne");
            $t->click(0, 0);
            frame();
            expect($list->selected())->toBe(3);
        });
    });

    it('keeps the selection on the same key when the items change, and draws styled labels', function () {
        $list = new ListView(['x' => 'x', 'y' => "\e[32mgreen\e[0m y", 'z' => 'z']);
        onScreen($list, 10, 3, function (FakeTerminal $t, ListView $list) {
            $list->select('y');
            frame();
            expect($t->dump())->toBe("x\n[inverse fg=2]green[/][inverse] y   [/]\nz");
            $list->setItems(['new' => 'new', 'y' => 'y', 'z' => 'z']);
            expect($list->selected())->toBe('y');
            $list->setItems(['only' => 'only']);
            expect($list->selected())->toBe('only')->and($list->select('missing'))->toBeFalse();
            $list->setItems([]);
            expect($list->selected())->toBeNull();
        });
    });

    it('emits scroll when the items in view change, and says which they are', function () {
        $items = array_map(fn($i) => "item $i", range(0, 19));
        $list = new ListView($items);
        $seen = [];
        $list->on('scroll', function (ListView $l) use (&$seen) {
            $seen[] = [$l->topIndex(), $l->bottomIndex()];
        });
        onScreen($list, 10, 5, function (FakeTerminal $t, ListView $list) use (&$seen) {
            expect($seen)->toBe([[0, 4]]);          // the first render
            $t->press('down', 'down');              // the selection stays in view: no scroll
            frame();
            expect($seen)->toBe([[0, 4]]);
            $list->select(9);
            frame();
            expect(end($seen))->toBe([5, 9])->and($t->text())->toStartWith('item 5');
            $t->scroll(1, 1, 1);                    // the wheel, three down
            frame();
            expect(end($seen))->toBe([8, 12]);
            $t->resize(10, 8);
            frame();
            expect(end($seen))->toBe([8, 15]);
        });
    });
});
