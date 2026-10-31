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
        $env = ['SHELL' => '/bin/sh', 'PS1' => '$ ', 'ENV' => ''];
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

it('runs host.php with a command and closes with it', function () {
    example('host.php', ['sh', '-c', "printf '\\033]2;the title\\007hosted \\033[31mred\\033[0m\\n'; read x"], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('hosted red', 10))->toBeTrue()
            ->and($t->waitFor('the title'))->toBeTrue()
            ->and($t->surface->title())->toBe('the title');
        $t->press('enter');
        expect($app->pty()->wait(5))->toBe(0);
    });
});

it('runs coroutine-pane: a shell and a printing coroutine', function () {
    $test = function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('Round 1', 10))->toBeTrue()
            ->and($t->waitFor(fn() => preg_match('/download\s+\[█+/u', $t->text()) === 1, 5))->toBeTrue()
            ->and($t->waitFor('$'))->toBeTrue();
        $t->press('ctrl+b', 'q');
        expect($app->pty()->wait(5))->toBe(0);
    };
    example('coroutine-pane.php', [], $test);
    $ext = __DIR__ . '/../../vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so';
    if (is_file($ext) && \PHP_VERSION_ID >= 80500 && !extension_loaded('phasync')) {
        example('coroutine-pane.php', [], function (FakeTerminal $t, Terminal $app) use ($test) {
            expect($t->waitFor('php: echo', 10))->toBeTrue();
            $test($t, $app);
        }, ['-d', "extension=$ext"]);
    }
});

it('runs the dashboard', function () {
    example('dashboard.php', [], function (FakeTerminal $t, Terminal $app) {
        expect($t->waitFor('random walk', 10))->toBeTrue()
            ->and($t->waitFor(fn() => preg_match('/[▁▂▃▄▅▆▇█]/u', $t->text()) === 1, 5))->toBeTrue()
            ->and($t->waitFor('samples', 5))->toBeTrue();
        $t->press('q');
        expect($app->pty()->wait(5))->toBe(0);
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
