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

it('announces the end of the program as an event and an awaitable, with its output in place', function () {
    run(function () {
        $s = new Surface(40, 5);
        $seen = [];
        $pty = Pty::start('echo bye; exit 3', $s, onExit: function (int $code, Pty $p) use (&$seen, $s) {
            $seen[] = [$code, $s->line(0), $p->isRunning()];
        });
        expect(fiber\await($pty->exited(), 5))->toBe(3)
            ->and($seen)->toBe([[3, 'bye', false]])
            ->and(fiber\await($pty->exited()))->toBe(3);          // settled: awaiting again returns at once
        $killed = Pty::start('exec sleep 30', new Surface(10, 2));
        $killed->kill(9);
        expect(fiber\await($killed->exited(), 5))->toBe(128 + 9);
    });
});

/** True while any process is left in the session $sid. */
function sessionAlive(int $sid): bool
{
    return trim((string) shell_exec('ps -o pid= -s ' . $sid . ' 2>/dev/null')) !== '';
}

it('hangs up a shell with a foreground program, everything it started, given as a string or a vector', function (string|array $command) {
    run(function () use ($command) {
        $s = new Surface(60, 6);
        $pty = Pty::start($command, $s);
        $s->input->write("sleep 100\r");
        eventually(fn() => sessionAlive($pty->pid()) && str_contains((string) shell_exec('ps -o comm= -s ' . $pty->pid()), 'sleep'));
        $started = microtime(true);
        expect($pty->hangUp())->toBe(128 + 1)
            ->and(microtime(true) - $started)->toBeLessThan(1.0)
            ->and(sessionAlive($pty->pid()))->toBeFalse();
    });
})->with([
    'string' => ['sh'],
    'vector' => [['sh']],
])->skip(PHP_OS_FAMILY !== 'Linux', 'reads the session from /proc');

it('kills what ignores the hang-up after the grace period', function () {
    run(function () {
        $s = new Surface(60, 6);
        $pty = Pty::start("trap '' HUP; echo ready; sleep 100", $s);
        eventually(fn() => str_contains($s->text(), 'ready'));
        expect($pty->hangUp(0.2))->toBe(128 + 9)->and(sessionAlive($pty->pid()))->toBeFalse();
    });
})->skip(PHP_OS_FAMILY !== 'Linux', 'reads the session from /proc');

it('tells a program that was resized while starting about its size again once it writes', function () {
    run(function () {
        $s = new Surface(40, 5);
        // the program only listens for SIGWINCH after a while, and says so
        $pty = Pty::start("sleep 0.4; trap 'stty size' WINCH; echo listening; while :; do sleep 0.05; done", $s);
        try {
            sleep(0.15);
            $s->resize(50, 8);
            eventually(fn() => str_contains($s->text(), '8 50'));
            expect($s->text())->toContain('listening');
        } finally {
            $pty->hangUp();
        }
    });
});

it('hangs up the programs still running when PHP exits', function () {
    $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
        . 'fiber\run(function () { $pty = phasync\Tui\Pty::start("sh", new phasync\Tui\Surface());'
        . '$pty->write("sleep 100\r"); fiber\sleep(0.3); echo $pty->pid(); exit(0); });';
    $pid = (int) shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($script));
    expect($pid)->toBeGreaterThan(0);
    $deadline = microtime(true) + 2;
    while (sessionAlive($pid) && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect(sessionAlive($pid))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Linux', 'reads the session from /proc');
