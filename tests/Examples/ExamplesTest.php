<?php

/*
 * Every example runs: each is started in a pty under a FakeTerminal, checked for what it should
 * show, and quit with its own key.
 */

use phasync\Tui\Component\Terminal;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

use function fiber\run;

/** Run examples/$name in a pty on a FakeTerminal and give it to $test. */
function example(string $name, array $args, Closure $test, array $php = []): void
{
    run(function () use ($name, $args, $test, $php) {
        // (not inside the screen or tmux this may run in: the program's terminal is the FakeTerminal)
        $env = ['SHELL' => '/bin/sh', 'PS1' => '$ ', 'ENV' => '', 'STY' => '', 'TMUX' => ''];
        $app = (new Terminal(['php', ...$php, __DIR__ . "/../../examples/$name", ...$args], env: $env))->start();
        $t = new FakeTerminal(100, 30);
        $t->attach($app->surface);
        try {
            $test($t, $app);
        } finally {
            $app->dispose();
            $t->detach();
        }
    });
}

it('runs split-shells: two shells, switching, quitting', function () {
    example('split-shells.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor(fn() => substr_count($t->text(), '$') >= 2, 10))->toBeTrue()
            ->and($t->text())->toContain('shell 1')->toContain('shell 2')->toContain('Ctrl-B q: quit');
        $t->type("echo left\n");
        $t->press('ctrl+b', 'tab');
        $t->type("echo right\n");
        expect($t->waitFor(fn() => preg_match('/│left\s+│.*\n.*│\$\s+│.*right/u', $t->text()) === 1 || str_contains($t->text(), 'right'), 5))->toBeTrue();
        $lines = explode("\n", $t->text());
        $leftCol = implode("\n", array_map(fn($l) => mb_substr($l, 0, 50), $lines));
        $rightCol = implode("\n", array_map(fn($l) => mb_substr($l, 50), $lines));
        expect($leftCol)->toContain('left')->not->toContain('right')->and($rightCol)->toContain('right');
        $t->press('ctrl+b', 'q');
        expect($app->pty()->wait(5))->toBe(0)
            ->and($app->surface->isAltScreen())->toBeFalse()    // it gave its terminal back
            ->and($app->surface->mode(1002))->toBeFalse();
    });
    example('split-shells.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor(fn() => substr_count($t->text(), '$') >= 2, 10))->toBeTrue();
        $t->press('ctrl+b', 'ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
});

it('runs split-shells: arrows move the focus, and it ends when both shells have exited', function () {
    example('split-shells.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor(fn() => substr_count($t->text(), '$') >= 2, 10))->toBeTrue();
        $t->press('ctrl+b', 'right');
        $t->type("echo on-the-right\n");
        expect($t->waitFor('on-the-right'))->toBeTrue();
        $right = implode("\n", array_map(fn($l) => mb_substr($l, 50), explode("\n", $t->text())));
        expect($right)->toContain('on-the-right');
        $t->type("exit\n");
        $t->press('ctrl+b', 'left');
        $t->type("exit\n");
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
});

it('runs host.php with a command, says goodbye with its exit status for three seconds and closes', function () {
    example('host.php', ['sh', '-c', "printf '\\033]2;the title\\007hosted \\033[31mred\\033[0m\\n'; read x; exit 3"], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('hosted red', 10))->toBeTrue()
            ->and($t->waitFor('the title'))->toBeTrue()
            ->and($t->surface->title())->toBe('the title')
            ->and($t->text())->toContain('Ctrl-B q: quit');
        $t->press('enter');
        $ended = microtime(true);
        expect($t->waitFor('exited with status 3', 5))->toBeTrue()
            ->and($app->pty()->wait(6))->toBe(0)
            ->and(microtime(true) - $ended)->toBeGreaterThan(2.5);
    });
    example('host.php', ['sh'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('$', 10))->toBeTrue();
        $t->press('ctrl+b', 'ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
});

it('runs coroutine-pane: a shell and a printing coroutine', function () {
    $test = function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Round 1', 10))->toBeTrue()
            ->and($t->waitFor(fn() => preg_match('/download\s+\[█+/u', $t->text()) === 1, 5))->toBeTrue()
            ->and($t->waitFor('$'))->toBeTrue();
        expect($t->text())->toContain('Ctrl-B q quit');
        $t->press('ctrl+b', 'q');
        expect($app->pty()->wait(5))->toBe(0);
    };
    example('coroutine-pane.php', [], $test);
    // Ctrl-B Ctrl-Q quits too; the shell exiting shows a goodbye, then the program ends
    example('coroutine-pane.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('$', 10))->toBeTrue();
        $t->press('ctrl+b', 'ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
    example('coroutine-pane.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('$', 10))->toBeTrue();
        $t->type("exit 7\n");
        expect($t->waitFor('exited with status 7', 5))->toBeTrue()->and($t->text())->toContain('goodbye')
            ->and($app->pty()->wait(6))->toBe(0);
    });
    $ext = __DIR__ . '/../../vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so';
    if (is_file($ext) && \PHP_VERSION_ID >= 80500 && !extension_loaded('phasync')) {
        example('coroutine-pane.php', [], function (FakeTerminal $t, Terminal $app) use ($test) {
            expect($t->waitFor('php: echo', 10))->toBeTrue();
            $test($t, $app);
        }, ['-d', "extension=$ext"]);
    }
});

it('runs the graphs: canvases in pixels inside a terminal that has Kitty graphics, in Braille with --marker=braille, the graph alone inline', function () {
    // (the program runs in a Terminal, a surface of this library, which answers Kitty's query:
    // it draws pixels; this FakeTerminal has no images, so it shows them as quarter blocks)
    example('graphs.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('cpu and net', 10))->toBeTrue()
            ->and($t->waitFor(fn() => substr_count($app->surface->dump(), 'img=') > 100, 5))->toBeTrue()
            ->and($t->waitFor(fn() => preg_match('/[▘▝▀▖▌▞▛▗▚▐▜▄▙▟█]/u', $t->text()) === 1, 5))->toBeTrue()
            ->and($t->text())->toContain('ctrl+q quit')->toContain('100%');
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
    example('graphs.php', ['--marker=braille'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor(fn() => preg_match('/[\x{2801}-\x{28ff}]/u', $t->text()) === 1, 10))->toBeTrue()
            ->and($t->text())->toContain('marker: braille')->toContain('logo')
            ->and(substr_count($app->surface->dump(), 'img='))->toBe(0);
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
    example('graphs.php', ['--inline', '--marker=braille'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('cpu and net', 10))->toBeTrue()->and($t->text())->not->toContain('logo');
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
});

it('prints the Braille graphs with --headless', function () {
    exec('php ' . escapeshellarg(__DIR__ . '/../../examples/graphs.php') . ' --headless=90x24 2>&1', $out, $status);
    $text = implode("\n", $out);
    expect($status)->toBe(0)->and($text)->toContain('cpu and net')->toContain('photo')
        ->and(preg_match('/[\x{2801}-\x{28ff}]/u', $text))->toBe(1);
});

it('runs the dashboard in a terminal, full screen and inline, and quits with Ctrl-Q', function () {
    example('dashboard.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('release notes', 10))->toBeTrue()->and($t->waitFor('run 1', 10))->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
    example('dashboard.php', ['--inline', '--theme=mono'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('╭ deploy', 10))->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0)->and($t->text())->toContain('╭ build')->not->toContain('release notes');
    });
});

it('runs form: fields, validation, the dropdown, the pick list, the switch, the confirmation, the progress', function () {
    example('form.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Sign up', 10))->toBeTrue()
            ->and($t->text())->toContain('your name')->toContain('ctrl+s save')->toContain('Languages')->toContain('[ Save ]');
        $t->press('ctrl+s');
        expect($t->waitFor('A name, please.'))->toBeTrue();
        $t->type('Ada');
        $t->press('tab');                           // the notes
        $t->type("one\ntwo");
        $t->press('tab', 'down', 'down', 'enter');  // the role: Operations
        $t->press('tab', 'space');                  // the newsletter
        $t->press('tab', 'tab', 'tab');             // Save, Clear, the languages
        $t->press('space', 'down', 'space');        // PHP and Go
        $t->press('ctrl+s');
        expect($t->waitFor('Save this sign-up?'))->toBeTrue();
        $t->press('enter');
        expect($t->waitFor('Saved: Ada, ops (php, go), newsletter'))->toBeTrue()->and($t->text())->not->toContain('A name, please.');
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
});

it('runs inline-progress: a live region under the shell\'s output, finished jobs printed above it', function () {
    example('inline-progress.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('total', 10))->toBeTrue()
            ->and($app->surface->isAltScreen())->toBeFalse();
        expect($t->waitFor('total 100%', 20))->toBeTrue();
        expect($app->pty()->wait(5))->toBe(0);
        $t->refresh();
        $lines = explode("\n", rtrim($t->text()));
        $done = array_values(array_filter($lines, fn($l) => str_contains($l, ' done in ')));
        expect($done)->toHaveCount(5)
            ->and(array_search($done[4], $lines))->toBeLessThan(array_key_last(array_filter($lines, fn($l) => str_starts_with($l, 'fetch'))))
            ->and(end($lines))->toBe('total 100%');
    });
    example('inline-progress.php', ['--fullscreen'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('total', 10))->toBeTrue()->and($app->surface->isAltScreen())->toBeTrue();
        $t->press('ctrl+c');
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
});

it('runs working: the one-file program, inline and full screen, until Ctrl-Q', function () {
    example('working.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Working… 100%', 15))->toBeTrue()
            ->and($app->surface->isAltScreen())->toBeFalse()
            ->and($t->text())->toContain(str_repeat('█', 95) . ' 100%')->toContain('ctrl+q quit')
            ->and($t->text())->not->toContain('<dim>');
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
        $t->refresh();
        expect($t->text())->toContain('Working… 100%');        // the last frame stays
    });
    example('working.php', ['--full'], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Working… 2', 10))->toBeTrue()->and($app->surface->isAltScreen())->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(1))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();   // the counter stopped with it
    });
});

it('runs markdown: a document streams in, the view follows it, End and Ctrl-Q work', function () {
    example('markdown.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Streaming Markdown', 10))->toBeTrue();
        expect($t->waitFor('Wide characters and emoji take two columns', 15))->toBeTrue()
            ->and($t->text())->toContain('ctrl+r again')->toContain('┌');
        $t->press('home');
        expect($t->waitFor('Streaming Markdown'))->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
});

it('runs tree: a folder loads its entries with a spinner, keys and type-to-find work, Ctrl-Q quits', function () {
    $dir = sys_get_temp_dir() . '/tui-tree-' . getmypid();
    @mkdir("$dir/alpha", 0777, true);
    touch("$dir/alpha/inner.txt");
    touch("$dir/zebra.txt");
    example('tree.php', [$dir], function (FakeTerminal $t, Terminal $app) use ($dir) {
        expect($t->waitFor('alpha', 10))->toBeTrue()->and($t->text())->toContain('zebra.txt');
        $t->press('down', 'right');                 // alpha: loading, then its entries
        expect($t->waitFor('inner.txt', 5))->toBeTrue();
        $t->type('z');
        expect($t->waitFor("$dir/zebra.txt  (0 bytes)"))->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0)->and($app->surface->isAltScreen())->toBeFalse();
    });
    @unlink("$dir/alpha/inner.txt");
    @unlink("$dir/zebra.txt");
    @rmdir("$dir/alpha");
    @rmdir($dir);
});

it('runs tabs: switching, a shell that goes on in a hidden tab, adding and closing tabs', function () {
    example('tabs.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('1 Shell', 10))->toBeTrue()->and($t->text())->toContain('2 Log')->toContain('3 Help ×');
        expect($t->waitFor(fn() => str_contains($t->text(), '$'), 10))->toBeTrue();
        $t->type("echo before\n");
        $t->press('alt+2');
        expect($t->waitFor('tick', 5))->toBeTrue()->and($t->text())->not->toContain('echo before');
        $t->press('alt+1');
        expect($t->waitFor('echo before'))->toBeTrue();
        $t->press('ctrl+n');
        expect($t->waitFor('Note 1 ×'))->toBeTrue()->and($t->text())->toContain('Close me');
        $t->press('escape', 'delete');
        expect($t->waitFor(fn() => !str_contains($t->text(), 'Note 1'), 5))->toBeTrue();
        $t->press('ctrl+q');
        expect($app->pty()->wait(5))->toBe(0);
    });
});
