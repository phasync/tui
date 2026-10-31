<?php

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Component\Box;
use phasync\Tui\Component\Log;
use phasync\Tui\Component\StatusBar;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\TextInput;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Key;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** Text drawn by render(), counting renders. */
final class Label extends Component
{
    public int $renders = 0;
    public array $log = [];

    public function __construct(public string $text = '')
    {
    }

    protected function mount(): void
    {
        $this->log[] = 'mount';
    }

    protected function render(): ?Layout
    {
        $this->renders++;
        $this->surface->write("\e[2J\e[H" . $this->text);
        return null;
    }

    protected function unmount(): void
    {
        $this->log[] = 'unmount';
    }
}

/** A component whose children are chosen by $keys. */
final class Panel extends Component
{
    public array $keys = ['a', 'b'];
    public array $keysSeen = [];

    protected function render(): ?Layout
    {
        $split = Split::horizontal();
        foreach ($this->keys as $key) {
            $split->add($this->child($key, fn() => new Label($key))->surface);
        }
        return $split;
    }

    public function label(string $key): Label
    {
        return $this->child($key, fn() => new Label($key));
    }

    protected function onKey(Key $key): bool
    {
        $this->keysSeen[] = (string) $key;
        return $key->is('ctrl+q');
    }
}

final class Failing extends Component
{
    protected function render(): ?Layout
    {
        throw new RuntimeException('broken widget');
    }
}

final class Ticker extends Component
{
    public int $ticks = 0;
    public bool $ended = false;
    public ?bool $endedBeforeUnmount = null;

    protected function run(): void
    {
        while ($this->sleep(0.01)) {
            $this->ticks++;
        }
        $this->ended = true;
    }

    protected function unmount(): void
    {
        $this->endedBeforeUnmount = $this->ended;
    }
}

/** Start $root on a FakeTerminal and run $test. */
function withApp(Component $root, int $cols, int $rows, Closure $test): void
{
    run(function () use ($root, $cols, $rows, $test) {
        $root->start();
        $terminal = new FakeTerminal($cols, $rows);
        $terminal->attach($root->surface);
        frame();    // the render after the resize to the terminal's size
        try {
            $test($terminal, $root);
        } finally {
            $terminal->detach();
            $root->dispose();
        }
    });
}

it('mounts, renders, and lays out children by key', function () {
    withApp(new Panel(), 20, 2, function (FakeTerminal $t, Panel $panel) {
        expect($t->text())->toBe("a         b\n");
        $a = $panel->label('a');
        $panel->keys = ['a', 'c'];
        $panel->requestRender();
        frame();
        expect($t->text())->toBe("a         c\n")
            ->and($panel->label('a'))->toBe($a)
            ->and($a->log)->toBe(['mount']);
    });
});

it('disposes children a render no longer asks for', function () {
    withApp(new Panel(), 20, 2, function (FakeTerminal $t, Panel $panel) {
        $b = $panel->label('b');
        $panel->keys = ['a'];
        $panel->requestRender();
        frame();
        expect($b->isMounted())->toBeFalse()->and($b->log)->toBe(['mount', 'unmount'])
            ->and($t->text())->toBe("a\n");
    });
});

it('batches render requests', function () {
    withApp(new Label('x'), 10, 1, function (FakeTerminal $t, Label $label) {
        $before = $label->renders;
        $label->requestRender();
        $label->requestRender();
        $label->requestRender();
        frame();
        expect($label->renders)->toBe($before + 1);
    });
});

it('renders again when resized', function () {
    withApp(new Label('x'), 10, 1, function (FakeTerminal $t, Label $label) {
        $before = $label->renders;
        $t->resize(12, 2);
        frame();
        expect($label->renders)->toBe($before + 1);
    });
});

it('lets a parent see keys before its children, and stop them', function () {
    withApp(new Panel(), 20, 2, function (FakeTerminal $t, Panel $panel) {
        $t->press('x', 'ctrl+q');
        expect($panel->keysSeen)->toBe(['x', 'ctrl+q'])
            ->and($panel->label('a')->surface->input->peek())->toBe('x');
    });
});

it('tells coroutines to end on dispose, and unmounts once they have', function () {
    run(function () {
        $ticker = (new Ticker())->start();
        sleep(0.05);
        expect($ticker->ticks)->toBeGreaterThan(0);
        $start = microtime(true);
        $ticker->dispose();                         // returns once the coroutine ended and unmount() ran
        expect($ticker->isMounted())->toBeFalse()
            ->and($ticker->ended)->toBeTrue()
            ->and($ticker->endedBeforeUnmount)->toBeTrue()
            ->and(microtime(true) - $start)->toBeLessThan(0.5);
    });
});

it('contains a failing child to its rectangle', function () {
    $panel = new class extends Component {
        protected function render(): ?Layout
        {
            return Split::horizontal()
                ->add($this->child('ok', fn() => new Label('fine'))->surface)
                ->add($this->child('bad', fn() => new Failing())->surface);
        }
    };
    withApp($panel, 40, 3, function (FakeTerminal $t) {
        frame();
        expect($t->text())->toContain('fine')->toContain('✖ RuntimeException:')->toContain('broken widget');
    });
});

it('rethrows a failure of the root component', function () {
    expect(fn() => run(fn() => (new Failing())->start()))->toThrow(RuntimeException::class, 'broken widget');
});

it('moves the focus to a component', function () {
    withApp(new Panel(), 20, 2, function (FakeTerminal $t, Panel $panel) {
        $panel->label('b')->focus();
        $t->press('y');
        expect($panel->label('b')->surface->input->peek())->toBe('y')
            ->and($panel->label('b')->hasFocus())->toBeTrue()
            ->and($panel->label('a')->hasFocus())->toBeFalse();
    });
});

it('focuses a child asked for in render() once the layout shows it (regression)', function () {
    $app = new class extends Component {
        public ?TextInput $input = null;

        protected function render(): ?Layout
        {
            $log = $this->child('log', fn() => new Log());
            $this->input = $this->child('input', fn() => new TextInput());
            $this->input->focus();
            return Split::vertical()->add($log->surface)->add($this->input->surface, 1);
        }
    };
    withApp($app, 20, 4, function (FakeTerminal $t, Component $app) {
        $t->type('typed');
        expect($app->input->value())->toBe('typed')->and($app->input->hasFocus())->toBeTrue();
    });
});

describe('built-ins', function () {
    it('edits a line in a TextInput', function () {
        $submitted = [];
        $input = new TextInput(prompt: '> ', placeholder: 'type', onSubmit: function (string $v, TextInput $i) use (&$submitted) {
            $submitted[] = $v;
            $i->setValue('');
        });
        withApp($input, 14, 1, function (FakeTerminal $t, TextInput $input) use (&$submitted) {
            expect($t->text())->toBe('> type');
            $t->type('hello wörld');
            $t->press('ctrl+w', 'left', 'left');
            $t->type('👍');
            $t->press('home', 'delete', 'end');
            $t->paste("x\ny");
            frame();
            expect($input->value())->toBe('ell👍o x y')
                ->and($t->text())->toBe('> ell👍o x y');
            $c = $t->surface->cursor();
            expect([$c->x, $c->visible])->toBe([12, true]);
            $t->press('enter');
            frame();
            expect($submitted)->toBe(['ell👍o x y'])->and($t->text())->toBe('> type');
        });
    });

    it('scrolls a long TextInput value to keep the cursor in view', function () {
        withApp(new TextInput('abcdefghijklmnop'), 6, 1, function (FakeTerminal $t, TextInput $input) {
            frame();
            expect($t->text())->toBe('lmnop');
            $t->press('home');
            frame();
            expect($t->text())->toBe('abcdef')->and($t->surface->cursor()->x)->toBe(0);
        });
    });

    it('appends to a Log, follows the end and scrolls back', function () {
        withApp(new Log(), 10, 3, function (FakeTerminal $t, Log $log) {
            foreach (range(1, 6) as $i) {
                $log->append("line $i\n");
            }
            $log->append("\e[31mred");
            expect($t->text())->toBe("line 5\nline 6\nred")
                ->and($log->lines())->toBe(['line 1', 'line 2', 'line 3', 'line 4', 'line 5', 'line 6', "\e[31mred"]);
            $t->scroll(0, 0, -1);
            expect($log->isFollowing())->toBeFalse()->and($t->text())->toBe("line 2\nline 3\nline 4");
            $log->append("\nmore\n");
            expect($t->text())->toBe("line 2\nline 3\nline 4");
            $log->follow();
            expect($t->text())->toBe("red\nmore\n");
        });
    });

    it('shows a StatusBar with text on both sides', function () {
        withApp(new StatusBar('left side', 'right'), 20, 1, function (FakeTerminal $t, StatusBar $bar) {
            expect($t->text())->toBe('left side      right');
            $bar->set('a much longer left text', 'R');
            frame();
            expect($t->text())->toBe('a much longer lef… R');
        });
    });

    it('draws a Box around its content', function () {
        withApp(new Box(new Label('in'), 'title'), 12, 4, function (FakeTerminal $t, Box $box) {
            frame();
            expect($t->text())->toBe("┌ title ───┐\n│in        │\n│          │\n└──────────┘");
        });
    });

    it('puts an existing child in a Box: the box shows it, its owner keeps it', function () {
        $app = new class extends Component {
            public bool $boxed = true;

            protected function mount(): void
            {
                $this->child('input', fn() => new TextInput())->focus();
            }

            protected function render(): ?Layout
            {
                $input = $this->child('input', fn() => new TextInput());
                if (!$this->boxed) {
                    return Split::vertical()->add($input->surface);
                }
                // the child() made above, given to a container: no "already started"
                return Split::vertical()->add($this->child('box', fn() => new Box($input, 'name'))->surface);
            }

            public function input(): TextInput
            {
                return $this->child('input');
            }

            public function box(): ?Box
            {
                return $this->child('box');
            }
        };
        withApp($app, 12, 3, function (FakeTerminal $t, Component $app) {
            frame();
            expect($t->text())->toBe("┌ name ────┐\n│          │\n└──────────┘")
                ->and($app->box()->content())->toBe($app->input());
            $t->type('ab');
            expect($app->input()->value())->toBe('ab')
                ->and($app->input()->hasFocus())->toBeTrue()
                ->and($app->box()->hasFocus())->toBeTrue();
            // the box goes, the input stays (it is the app's), shown without the border now
            $box = $app->box();
            $app->boxed = false;
            $app->requestRender();
            frame();
            expect($box->isMounted())->toBeFalse()
                ->and($app->input()->isMounted())->toBeTrue()
                ->and($t->text())->toStartWith('ab');
            $t->type('c');
            expect($app->input()->value())->toBe('abc');
        });
    });

    it('runs a program in a Terminal and hangs it up on dispose', function () {
        $exits = [];
        $term = new Terminal('printf "ready\n"; read x; echo "got $x"; exec sleep 30', onExit: function (int $code) use (&$exits) { $exits[] = $code; });
        withApp($term, 20, 3, function (FakeTerminal $t, Terminal $term) {
            expect($t->waitFor('ready'))->toBeTrue();
            $t->type("hi\n");
            expect($t->waitFor('got hi'))->toBeTrue()->and($term->isRunning())->toBeTrue();
            $pty = $term->pty();
            $term->dispose();
            expect($pty->isRunning())->toBeFalse();
        });
        expect($exits)->toBe([]);
    });
});
