<?php

use phasync\Tui\Pty;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** Wait until $check() is true (polling), failing the test after $timeout seconds. */
function eventually(callable $check, float $timeout = 3.0): void
{
    $deadline = microtime(true) + $timeout;
    while (!$check()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Condition not met within ' . $timeout . 's');
        }
        sleep(0.01);
    }
}

it('runs a command and writes its output into the surface', function () {
    run(function () {
        $s = new Surface(40, 5);
        $pty = Pty::start("printf 'hello \\033[1mworld\\033[0m\\n'", $s);
        expect($pty->wait(5))->toBe(0)
            ->and($s->line(0))->toBe('hello world')
            ->and($s->cell(6, 0)->style->attrs)->toBe(phasync\Tui\Style::BOLD)
            ->and($pty->isRunning())->toBeFalse();
    });
});

it('runs an argument vector without a shell', function () {
    run(function () {
        $s = new Surface(40, 5);
        $pty = Pty::start(['printf', '%s|', 'a b', '$HOME'], $s);
        $pty->wait(5);
        expect($s->line(0))->toBe('a b|$HOME|');
    });
});

it('gives the program a terminal of the surface size with TERM and COLORTERM set', function () {
    run(function () {
        $s = new Surface(37, 11);
        Pty::start('stty size; echo $TERM $COLORTERM; test -t 0 && echo tty', $s)->wait(5);
        expect($s->line(0))->toBe('11 37')
            ->and($s->line(1))->toBe('xterm-256color truecolor')
            ->and($s->line(2))->toBe('tty');
    });
});

it('passes the environment and working directory', function () {
    run(function () {
        $s = new Surface(60, 3);
        Pty::start('echo $GREETING; pwd', $s, cwd: '/tmp', env: ['GREETING' => 'hi'])->wait(5);
        expect($s->line(0))->toBe('hi')
            ->and($s->line(1))->toBe('/tmp');
    });
});

it('sends the surface input to the program', function () {
    run(function () {
        $s = new Surface(40, 5);
        $pty = Pty::start(['cat'], $s);
        $s->input->write("typed\r");
        eventually(fn() => str_contains($s->text(), "typed\ntyped"));
        $s->input->write("\x04");
        expect($pty->wait(5))->toBe(0);
    });
});

it('answers the program\'s terminal queries through the pty', function () {
    run(function () {
        $s = new Surface(80, 5);
        // ask for the cursor position, read the reply with the terminal in raw mode
        $pty = Pty::start("stty raw -echo; printf 'ab\\033[6n'; reply=\$(dd bs=6 count=1 2>/dev/null); stty sane; printf '\\r\\n%s' \"\$reply\" | od -c | head -1", $s);
        $pty->wait(5);
        expect($s->text())->toContain('033   [   1   ;   3   R');
    });
});

it('resizes the terminal with the surface and signals the program', function () {
    run(function () {
        $s = new Surface(40, 5);
        $pty = Pty::start("trap 'stty size' WINCH; echo ready; while :; do sleep 0.05; done", $s);
        eventually(fn() => str_contains($s->text(), 'ready'));
        $s->resize(50, 8);
        eventually(fn() => str_contains($s->text(), '8 50'));
        $pty->kill();
        expect($pty->wait(5))->toBe(128 + 15);
    });
});

it('gives the program a controlling terminal so Ctrl-C interrupts it', function () {
    run(function () {
        $s = new Surface(40, 5);
        $pty = Pty::start(['sh', '-c', 'echo go; sleep 10'], $s);
        eventually(fn() => str_contains($s->text(), 'go'));
        $s->input->write("\x03");
        expect($pty->wait(5))->toBe(128 + 2);
    });
})->skip(PHP_OS_FAMILY !== 'Linux', 'needs setsid(1)');

it('reports the exit status', function () {
    run(function () {
        expect(Pty::start('exit 3', new Surface())->wait(5))->toBe(3);
    });
});

it('times out waiting for a program that keeps running', function () {
    run(function () {
        $pty = Pty::start(['sleep', '5'], new Surface());
        expect($pty->wait(0.1))->toBeNull()
            ->and($pty->isRunning())->toBeTrue()
            ->and($pty->exitCode())->toBeNull();
        $pty->kill(9);
        expect($pty->wait(5))->toBe(128 + 9);
    });
});

it('keeps other coroutines running while a program produces a lot of output', function () {
    run(function () {
        $s = new Surface(80, 24);
        $ticks = 0;
        $pty = Pty::start('seq 1 20000', $s);
        $ticker = fiber\go(function () use (&$ticks, $pty) {
            while ($pty->isRunning()) {
                $ticks++;
                sleep(0.001);
            }
        });
        $pty->wait(10);
        fiber\await($ticker);
        expect($s->line(22))->toBe('20000')
            ->and($ticks)->toBeGreaterThan(1);
    });
});

it('fails clearly for a command that cannot start', function () {
    run(function () {
        $s = new Surface(60, 3);
        expect(Pty::start(['/nonexistent/program'], $s)->wait(5))->toBe(127);
    });
});
