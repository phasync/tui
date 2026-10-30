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
            sleep(0.01);
            expect($t->text())->toBe("item 2\nitem 3\nitem 4")->and($list->selected())->toBe('k4');
            $t->press('end');
            sleep(0.01);
            expect($t->text())->toBe("item 8\nitem 9\nitem 10");
            $t->press('pageup', 'enter');
            sleep(0.01);
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
            sleep(0.01);
            expect($events)->toBe(['select 1', 'activate 1'])->and($t->text())->toBe("d\ne");
            $t->click(0, 0);
            sleep(0.01);
            expect($list->selected())->toBe(3);
        });
    });

    it('keeps the selection on the same key when the items change, and draws styled labels', function () {
        $list = new ListView(['x' => 'x', 'y' => "\e[32mgreen\e[0m y", 'z' => 'z']);
        onScreen($list, 10, 3, function (FakeTerminal $t, ListView $list) {
            $list->select('y');
            sleep(0.01);
            expect($t->dump())->toBe("x\n[inverse fg=2]green[/][inverse] y   [/]\nz");
            $list->setItems(['new' => 'new', 'y' => 'y', 'z' => 'z']);
            expect($list->selected())->toBe('y');
            $list->setItems(['only' => 'only']);
            expect($list->selected())->toBe('only')->and($list->select('missing'))->toBeFalse();
            $list->setItems([]);
            expect($list->selected())->toBeNull();
        });
    });
});
