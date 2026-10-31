<?php

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Focus;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Style;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** Run $test with a root attached to a FakeTerminal, detached afterwards. */
function onTerminal(int $cols, int $rows, Closure $test): void
{
    run(function () use ($cols, $rows, $test) {
        $root = new Surface();
        $terminal = new FakeTerminal($cols, $rows);
        $terminal->attach($root);
        try {
            $test($terminal, $root);
        } finally {
            $terminal->detach();
        }
    });
}

/** The terminal shows exactly the root: same cells, same styles, same cursor. */
function expectSameScreen(FakeTerminal $terminal, Surface $root): void
{
    $terminal->refresh();
    $c = $root->activeCursor();
    $t = $terminal->surface->cursor();
    expect($terminal->surface->dump())->toBe($root->dump())
        ->and([$t->x, $t->y, $t->visible])->toBe([$c->x, $c->y, $c->visible]);
}

it('takes over the terminal and gives it back', function () {
    run(function () {
        $terminal = new FakeTerminal(20, 4);
        $root = new Surface();
        $terminal->attach($root);
        $s = $terminal->surface;
        expect([$root->cols(), $root->rows()])->toBe([20, 4])
            ->and($s->isAltScreen())->toBeTrue()
            ->and($s->mode(1002) && $s->mode(1006) && $s->mode(2004) && $s->mode(1004))->toBeTrue()
            ->and($terminal->hasKittyKeyboard())->toBeTrue()
            ->and($s->keyboardFlags())->toBe(1);
        $terminal->detach();
        expect($s->isAltScreen())->toBeFalse()
            ->and($s->mode(1002) || $s->mode(1006) || $s->mode(2004) || $s->mode(1004))->toBeFalse()
            ->and($s->keyboardFlags())->toBe(0)
            ->and($s->cursor()->visible)->toBeTrue()
            ->and($terminal->isAttached())->toBeFalse();
    });
});

it('shows exactly what the root holds: the library\'s output read back by its own emulator', function () {
    onTerminal(30, 8, function (FakeTerminal $terminal, Surface $root) {
        $a = new Surface(14, 6);
        $b = new Surface(12, 5);
        $root->host($a, 0, 0, 16, 8)->setBorder(true, Style::of(Color::BLUE))->setTitle('a 中文');
        $root->host($b, 17, 1);
        $a->write("\e[1;31mred\e[0m plain \e[48;5;200mbg\e[0m\r\n中文字 e\u{301} 👍🏽\r\n\e[4munder\e[24m \e[38;2;1;2;3mrgb");
        $b->write("\e[7minverse\e[27m\r\n\e[3;9mx\e[0m");
        expectSameScreen($terminal, $root);
        $seed = 7;
        mt_srand($seed);
        $pieces = ['a', 'bc', ' ', '中', '👍', "\e[1m", "\e[0m", "\e[32m", "\e[44m", "\r\n", "\e[K", "\e[2;3H", "\e[1L", "\e[2P", "\e[3@", "\e[A", 'é'];
        for ($i = 0; $i < 200; $i++) {
            $target = mt_rand(0, 1) ? $a : $b;
            $bytes = '';
            for ($j = mt_rand(1, 8); $j > 0; $j--) {
                $bytes .= $pieces[mt_rand(0, \count($pieces) - 1)];
            }
            $target->write($bytes);
            if ($i % 20 === 0) {
                expectSameScreen($terminal, $root);
            }
        }
        expectSameScreen($terminal, $root);
        // the byte stream alone, written into a fresh Surface, gives the same cells
        $fresh = new Surface(30, 8, 0);
        $fresh->write($terminal->output());
        expect($fresh->dump())->toBe($root->dump());
    });
});

it('sends only what changed', function () {
    onTerminal(40, 10, function (FakeTerminal $terminal, Surface $root) {
        $root->write("hello world\r\nsecond line");
        $terminal->refresh();
        $terminal->takeOutput();
        $root->write("\e[1;7HW");
        $terminal->refresh();
        $out = $terminal->takeOutput();
        expect($out)->toContain("\e[1;7HW")->not->toContain('hello')->not->toContain('second')
            ->and(\strlen($out))->toBeLessThan(60);
        $terminal->refresh();
        expect($terminal->takeOutput())->toBe('');
    });
});

it('costs a busy pane its changed cells per frame, whatever the byte volume', function () {
    onTerminal(60, 10, function (FakeTerminal $terminal, Surface $root) {
        $mid = new Surface();
        $pane = new Surface();
        $root->setLayout(phasync\Tui\Split::horizontal()->add(new Surface())->add($mid));
        $mid->setLayout(phasync\Tui\Split::vertical()->add($pane));
        $mid->host($pane)->setBorder(true)->setTitle('busy');
        $pane->write("working ");
        $terminal->refresh();
        $terminal->takeOutput();
        // a spinner: 40 kB of output between two frames that changes one cell in the end
        $pane->write(str_repeat("\e[1;9H|\e[1;9H/\e[1;9H-\e[1;9H\\", 2000) . "\e[1;9H*");
        $terminal->refresh();
        $out = $terminal->takeOutput();
        expect($out)->toContain('*')->and(\strlen($out))->toBeLessThan(60)
            ->and($terminal->surface->dump())->toBe($root->dump());
    });
});

it('draws by itself, at most 60 frames a second', function () {
    onTerminal(20, 3, function (FakeTerminal $terminal, Surface $root) {
        sleep(0.05);
        $terminal->takeOutput();
        $start = microtime(true);
        for ($i = 0; $i < 30; $i++) {
            $root->write("\r$i ");
            sleep(0.002);
        }
        sleep(0.05);
        $elapsed = microtime(true) - $start;
        $frames = substr_count($terminal->takeOutput(), "\e[?2026h");
        expect($terminal->surface->line(0))->toBe('29')
            ->and($frames)->toBeGreaterThan(0)
            ->and($frames)->toBeLessThanOrEqual((int) ceil($elapsed * 60) + 1);
    });
});

it('decodes input and dispatches it to the root and down the focus path', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $a = new Surface(10, 4);
        $b = new Surface(10, 4);
        $root->host($a);
        $root->host($b, 10, 0);
        $seen = [];
        $root->on('key', function (Key $k) use (&$seen) { $seen[] = (string) $k; });
        $root->on('paste', function (Paste $p) use (&$seen) { $seen[] = "paste $p->text"; });
        $terminal->press('a', 'ctrl+i', 'shift+enter', 'escape');
        $terminal->paste("two\nlines");
        $terminal->feed("\e");
        expect($seen)->toBe(['a', 'ctrl+i', 'shift+enter', 'escape', "paste two\nlines", 'escape'])
            ->and($a->input->peek())->toBe("a\t\r\etwo\rlines\e");
        $b->write("\e[?1000h\e[?1006h");
        $terminal->click(12, 1);
        expect($b->input->peek())->toBe("\e[<0;3;2M\e[<0;3;2m");
    });
});

it('takes raw bytes as the terminal sends them with feed(), escape sequences included', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $seen = [];
        $root->on('key', function (Key $k) use (&$seen) { $seen[] = (string) $k; });
        $root->on('paste', function (Paste $p) use (&$seen) { $seen[] = "paste $p->text"; });
        $root->on('mouse', function (Mouse $m) use (&$seen) { $seen[] = "mouse $m->action $m->x,$m->y"; });
        $root->on('focus', function (Focus $f) use (&$seen) { $seen[] = $f->focused ? 'focus in' : 'focus out'; });
        $terminal->feed("x\e[1;5A\eOP\e[97;5u\e[3~é");   // several in one read: text, CSI, SS3, kitty
        $terminal->feed("\e[<0;5;3M\e[<0;5;3m");            // SGR mouse press and release
        $terminal->feed("\e[200~a\r\nb\e[201~");             // bracketed paste
        $terminal->feed("\e[200~split ");                    // a paste over two reads
        $terminal->feed("paste\e[201~");
        $terminal->feed("\e[O\e[I");                          // focus out, in
        $terminal->feed("\ex");                              // Alt-x
        $terminal->feed("\e");                               // a lone ESC, then a pause: Escape
        expect($seen)->toBe([
            'x', 'ctrl+up', 'f1', 'ctrl+a', 'delete', 'é',
            'mouse ' . Mouse::PRESS . ' 4,2', 'mouse ' . Mouse::RELEASE . ' 4,2',
            "paste a\nb", 'paste split paste',
            'focus out', 'focus in',
            'alt+x', 'escape',
        ]);
    });
});

it('types text with type(), and refuses escape sequences and control bytes there', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $seen = [];
        $root->on('key', function (Key $k) use (&$seen) { $seen[] = (string) $k; });
        $terminal->type("a é\tb\n");
        expect($seen)->toBe(['a', 'space', 'é', 'tab', 'b', 'enter']);
        expect(fn() => $terminal->type("\e[A"))->toThrow(InvalidArgumentException::class, 'press()')
            ->and(fn() => $terminal->type("\x03"))->toThrow(InvalidArgumentException::class)
            ->and($seen)->toHaveCount(6);       // nothing of a refused call arrives
    });
});

it('passes focus reports to the focused program', function () {
    onTerminal(10, 2, function (FakeTerminal $terminal, Surface $root) {
        $a = new Surface(10, 2);
        $root->host($a);
        $a->write("\e[?1004h");
        $terminal->focus(false);
        $terminal->focus(true);
        expect($a->input->peek())->toBe("\e[O\e[I");
    });
});

it('follows the terminal size', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $root->write('top');
        $terminal->resize(30, 6);
        expect([$root->cols(), $root->rows()])->toBe([30, 6]);
        expectSameScreen($terminal, $root);
    });
});

it('passes the title, the bell and clipboard requests to the terminal', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $a = new Surface(10, 4);
        $root->host($a);
        $a->write("\e]2;my title\x07" . Ansi::clipboard('copied'));
        expect($terminal->surface->title())->toBe('my title')
            ->and($terminal->clipboardText())->toBe('copied');
    });
});

it('copies a mouse selection to the terminal\'s clipboard', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $a = vt("first line\r\nsecond", 20, 4);
        $root->host($a);
        $terminal->drag(6, 0, 2, 1);
        expect($terminal->clipboardText())->toBe("line\nsec");
        expectSameScreen($terminal, $root);
    });
});

it('shows the cursor of the focused window where it is', function () {
    onTerminal(20, 6, function (FakeTerminal $terminal, Surface $root) {
        $a = new Surface(5, 2);
        $root->host($a, 3, 2);
        $a->write("ab\e[5 q");
        $terminal->refresh();
        $c = $terminal->surface->cursor();
        expect([$c->x, $c->y, $c->visible, $c->shape, $c->blinking])->toBe([5, 2, true, 'bar', true]);
        $a->write("\e[?25l");
        $terminal->refresh();
        expect($terminal->surface->cursor()->visible)->toBeFalse();
    });
});

it('reports mouse motion without a button while a program below asks for it (mode 1003)', function () {
    onTerminal(20, 4, function (FakeTerminal $terminal, Surface $root) {
        $pane = new Surface();
        $mid = new Surface();
        $root->setLayout(phasync\Tui\Split::horizontal()->add(new Surface(), 10)->add($mid));
        $mid->setLayout(phasync\Tui\Split::vertical()->add($pane));
        $terminal->refresh();
        expect($terminal->surface->mode(1003))->toBeFalse();
        $terminal->mouse(new Mouse(Mouse::MOVE, 12, 1));
        expect($pane->input->peek())->toBe('');                 // not reported: nobody asked
        $pane->write("\e[?1003h\e[?1006h");
        $terminal->refresh();
        expect($terminal->surface->mode(1003))->toBeTrue();
        $terminal->mouse(new Mouse(Mouse::MOVE, 12, 1));
        expect($pane->input->peek())->toBe("\e[<35;3;2M");      // motion, no button, at the pane's (2,1)
        $pane->write("\e[?1003l");
        $terminal->refresh();
        expect($terminal->surface->mode(1003))->toBeFalse()->and($terminal->surface->mode(1002))->toBeTrue();
    });
});

it('closes the root when detached, and detaches when the root is closed', function () {
    run(function () {
        $root = new Surface();
        $closed = 0;
        $root->on('close', function () use (&$closed) { $closed++; });
        $terminal = new FakeTerminal(10, 2);
        $terminal->attach($root);
        $terminal->detach();
        expect($closed)->toBe(1)->and($terminal->isAttached())->toBeFalse();
        $terminal->attach($root);
        $root->close();
        expect($terminal->isAttached())->toBeFalse()->and($closed)->toBe(2)
            ->and($terminal->surface->isAltScreen())->toBeFalse();
    });
});

it('ends a headless run whose test throws: run() detaches, fiber\run() rethrows instead of hanging', function () {
    $start = microtime(true);
    expect(fn() => run(function () {
        $t = new FakeTerminal(20, 3);
        $app = new class () extends phasync\Tui\Component {
            protected function run(): void
            {
                while ($this->sleep(0.01)) {
                }
            }
        };
        $app->start($t);
        $t->run(function () {
            throw new RuntimeException('the test failed');
        });
    }))->toThrow(RuntimeException::class, 'the test failed');
    expect(microtime(true) - $start)->toBeLessThan(2);
});

it('settles: the screen shows what the input made the program draw', function () {
    run(function () {
        $t = new FakeTerminal(20, 2);
        $app = new class () extends phasync\Tui\Component {
            protected function onKey(Key $key): bool
            {
                $this->state->text = ($this->state->text ?? '') . $key->text;
                return true;
            }

            protected function render(): phasync\Tui\Element
            {
                return new phasync\Tui\Element\Text('got ' . ($this->state->text ?? ''));
            }
        };
        $app->start($t);
        $t->run(function (FakeTerminal $t) use ($app) {
            $t->type('abc');
            expect($app->surface->text())->toStartWith("got\n");     // the render comes in the next frame,
            expect($t->text())->toStartWith('got abc');                // or when the device draws (flush())
            $t->type('d');
            $t->settle();
            expect($t->text())->toStartWith('got abcd');
        });
    });
});

describe('hyperlinks', function () {
    it('writes the cells\' hyperlinks to a terminal that supports them, and a replay keeps them', function () {
        run(function () {
            $root = new Surface();
            $t = new FakeTerminal(30, 3);
            $t->attach($root);
            $root->write('see ' . Ansi::hyperlink('the docs', 'https://example.com/docs', 'd') . ' or ' . Ansi::hyperlink('home', 'https://example.com') . "\r\nplain");
            $t->refresh();
            $replay = new Surface(30, 3);
            $replay->write($t->output());
            foreach ([$t->surface, $replay] as $s) {
                expect($s->line(0))->toBe('see the docs or home')
                    ->and([$s->cell(4, 0)->style->link, $s->cell(11, 0)->style->linkId])->toBe(['https://example.com/docs', 'd'])
                    ->and($s->cell(12, 0)->style->link)->toBe('')
                    ->and($s->cell(16, 0)->style->link)->toBe('https://example.com')
                    ->and($s->cell(0, 1)->style->link)->toBe('');
            }
            $t->detach();
        });
    });

    it('writes the text alone to a terminal without hyperlinks', function () {
        run(function () {
            $root = new Surface();
            $t = new FakeTerminal(30, 2, hyperlinks: false);
            $t->attach($root);
            $root->write('a ' . Ansi::hyperlink('link', 'https://example.com') . ' b');
            $t->refresh();
            expect($t->supportsHyperlinks())->toBeFalse()->and($t->text())->toStartWith('a link b')
                ->and($t->output())->not->toContain("\e]8;")->and($t->surface->cell(2, 0)->style->link)->toBe('');
            $t->detach();
        });
    });

    it('shows the links a program in a Terminal pane prints, clickable or as text by the device', function () {
        foreach ([true, false] as $supported) {
            run(function () use ($supported) {
                $t = new FakeTerminal(40, 4, hyperlinks: $supported);
                $term = (new phasync\Tui\Component\Terminal(['printf', '\033]8;;https://example.org\033\\\\click\033]8;;\007 done']))->start($t);
                $t->run(function (FakeTerminal $t) use ($supported) {
                    expect($t->waitFor('click done'))->toBeTrue()
                        ->and($t->surface->cell(0, 0)->style->link)->toBe($supported ? 'https://example.org' : '')
                        ->and($t->surface->cell(6, 0)->style->link)->toBe('');
                });
            });
        }
    });

    it('knows from the terminal\'s name or the environment whether hyperlinks show', function () {
        run(function () {
            $t = new FakeTerminal(10, 2);
            $t->attach(new Surface());
            expect($t->terminalName())->toStartWith('phasync-tui');
            $t->detach();
        });
        $env = fn(array $vars) => phasync\Tui\Tty::hyperlinksFromEnvironment($vars);
        expect($env(['TERM' => 'xterm-kitty']))->toBeTrue()
            ->and($env(['TERM' => 'xterm-256color', 'VTE_VERSION' => '7200']))->toBeTrue()
            ->and($env(['TERM' => 'xterm-256color', 'TERM_PROGRAM' => 'WezTerm']))->toBeTrue()
            ->and($env(['TERM' => 'xterm-256color', 'VTE_VERSION' => '7200', 'TMUX' => '/tmp/tmux-1/default,1,0']))->toBeFalse()
            ->and($env(['TERM' => 'screen-256color']))->toBeFalse()
            ->and($env(['TERM' => 'linux']))->toBeFalse()
            ->and($env(['TERM' => 'xterm-256color']))->toBeNull();
    });
});
