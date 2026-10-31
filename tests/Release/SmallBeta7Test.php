<?php

/*
 * TODO-0.0.3-beta7 item 3, small things from use: a Table with markup cells cost twice a plain
 * one per key (every row and cell drawn and parsed again), no way to choose the theme of a
 * headless run, a parent's bindings took Ctrl-D before a focused Terminal's shell, and all the
 * bars of a screen moving in one frame drew every bar whole.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Button;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Component\Table;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\State;
use phasync\Tui\Tui;

/** 200 rows of six markup cells, or of two plain ones. */
function beta7Table(bool $markup): Table
{
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $rows[$i] = $markup
            ? ["<b>$i</b>", "<fg=green>job-$i</>", '<dim>host-' . ($i % 7) . '</dim>', '<fg=yellow>' . ($i * 3 % 100) . '%</>', $i % 3 ? '<fg=red>failed</>' : '<fg=green>ok</>', "<i>note $i</i>"]
            : ["$i", "job-$i"];
    }
    $columns = $markup
        ? ['#' => ['align' => 'right'], 'Name' => '1fr', 'Host' => [], 'CPU' => ['align' => 'right'], 'State' => [], 'Note' => '1fr']
        : ['#' => ['align' => 'right'], 'Name' => '1fr'];
    return new Table(columns: $columns, rows: $rows);
}

/** Run tests/fixtures/programs/theme.php; what it printed. */
function beta7Theme(array $args, array $env): string
{
    $env = [...getenv(), 'TUI_HEADLESS' => '40x3@0.1', ...$env];
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/programs/theme.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return trim($out);
}

it('writes the two rows a key changes in a Table, and a key in six markup columns costs about what two plain ones cost', function () {
    $median = [];
    foreach ([true, false] as $markup) {
        fiber\run(function () use ($markup, &$median) {
            $table = beta7Table($markup);
            $t = Tui::headless(fn() => $table, 120, 40);
            $t->settle();
            $times = [];
            for ($k = 0; $k < 24; $k++) {
                $a = hrtime(true);
                $t->press('down');
                $t->settle();
                $times[] = (hrtime(true) - $a) / 1e6;
                expect($table->rowsWritten())->toBe(2);
                fiber\sleep(0.005);
            }
            sort($times);
            $median[$markup ? 'markup' : 'plain'] = $times[12];
            expect($table->current())->toBe(24);
            if ($markup) {
                // (the screen is what a fresh table at that row draws)
                $fresh = beta7Table(true);
                $fresh->setCurrent(24);
                $u = Tui::headless(fn() => $fresh, 120, 40);
                $u->settle();
                expect($t->dump())->toBe($u->dump())->and($t->text())->toContain('job-24')->toContain('failed');
                $u->detach();
            }
            $t->press('pagedown');
            $t->settle();
            expect($table->rowsWritten())->toBeGreaterThan(30);       // (scrolled: every row is another)
            $t->resize(100, 30);
            $t->settle();
            expect($t->text())->toContain('Name');
            $t->detach();
        });
    }
    // about 2.9 against 1.7 ms on the machine this was written on (the report: 18 against 8 ms)
    expect($median['markup'])->toBeLessThan($median['plain'] * 2.5 + 3.0);
});

it('chooses the theme of a run from TUI_THEME or --theme=, a theme the program passes winning', function () {
    expect(beta7Theme([], ['TUI_THEME' => 'light']))->toBe('theme light')
        ->and(beta7Theme(['--theme=mono'], ['TUI_THEME' => 'light']))->toBe('theme mono')
        ->and(beta7Theme(['explicit'], ['TUI_THEME' => 'light']))->toBe('theme dark')
        ->and(beta7Theme([], ['TUI_THEME' => 'nope']))->toContain('nope');
});

it('gives a focused Terminal the keys a shell uses, whatever the components around it bind, except their `first` and `always` bindings', function () {
    fiber\run(function () {
        $shell = new Terminal(['sh', '-c', 'echo ready; cat -v; echo got-eof; exec cat']);
        $app = new class ($shell) extends Component {
            public array $took = [];
            public Button $ok;

            public function __construct(private Terminal $shell)
            {
                $this->ok = new Button('ok');
            }

            protected function bindings(): array
            {
                return [
                    'ctrl+d' => fn() => $this->took[] = 'ctrl+d',
                    'escape' => fn() => $this->took[] = 'escape',
                    'x' => fn() => $this->took[] = 'x',
                    'ctrl+g' => [fn() => $this->took[] = 'ctrl+g', 'first' => true],
                    'ctrl+t' => [fn() => $this->took[] = 'ctrl+t', 'always' => true],
                ];
            }

            protected function render(): Element
            {
                return new Column([
                    new Row([new Box($this->shell, 'sh')], onKey: ['ctrl+d' => fn() => $this->took[] = 'row'], selected: true),
                    $this->ok,
                ]);
            }
        };
        $t = Tui::headless(fn() => $app, 40, 10);
        expect($t->waitFor('ready'))->toBeTrue();
        $shell->focus();
        $t->settle();
        $t->type('x');
        $t->press('escape');
        $t->press('enter');     // (cat ends at a Ctrl-D on an empty line; -v shows the Escape as ^[)
        $t->press('ctrl+d');
        $t->press('ctrl+g', 'ctrl+t');
        $t->settle();
        expect($t->waitFor('got-eof', 2.0))->toBeTrue($t->text() . ' took: ' . implode(',', $app->took))
            ->and($app->took)->toBe(['ctrl+g', 'ctrl+t']);
        // (with the focus elsewhere the bindings take their keys again)
        $app->ok->focus();     // (Tab is the shell's too)
        $t->settle();
        expect($shell->hasFocus())->toBeFalse();
        $t->press('ctrl+d');
        $t->settle();
        expect($app->took)->toBe(['ctrl+g', 'ctrl+t', 'ctrl+d']);
        $t->detach();
    });
});

it('draws a bar that moves as the bar drawn whole at its value, label and all', function () {
    fiber\run(function () {
        $s = new State(['v' => 0.0, 'label' => '']);
        $t = Tui::headless(fn() => new Column([new ProgressBar(fn() => $s->v, $s->label)]), 60, 2);
        $t->settle();
        // (one cell at a time, back, all, none; a label that comes, one as wide, one that goes)
        $steps = [[0.01, ''], [0.02, ''], [0.5, ''], [0.49, ''], [1.0, ''], [0.0, ''], [0.33, 'copy'], [0.34, 'move'], [0.9, 'moving'], [0.91, '']];
        foreach ($steps as [$v, $label]) {
            $s->v = $v;
            $s->label = $label;
            $t->settle();
            $u = Tui::headless(fn() => new Column([new ProgressBar($v, $label)]), 60, 2);
            $u->settle();
            expect($t->dump())->toBe($u->dump());
            $u->detach();
        }
        // (an indeterminate one moves by itself, and becomes a whole bar again)
        $s->v = null;
        $t->settle();
        fiber\sleep(0.25);
        $s->v = 0.25;
        $t->settle();
        $u = Tui::headless(fn() => new Column([new ProgressBar(0.25)]), 60, 2);
        $u->settle();
        expect($t->dump())->toBe($u->dump());
        $u->detach();
        $t->detach();
    });
});
