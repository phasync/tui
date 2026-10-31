<?php

/*
 * An exception in the program's own code ends the program, visibly: the tree is disposed, the
 * terminal restored, and the exception comes out of fiber\run() (or Tui::run()), as it does
 * without a terminal UI. Each case runs in a forked process, so a hang fails the test.
 */

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Internal\Runtime;
use phasync\Tui\Pty;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

final class FailingApp extends Component
{
    public static ?FakeTerminal $terminal = null;
    public static bool $unmounted = false;

    public function __construct(private string $mode = '')
    {
    }

    protected function render(): Element
    {
        return new Text('failing');
    }

    protected function run(): void
    {
        if ($this->mode === 'go') {
            $this->go(function () {
                $this->sleep(0.02);
                throw new RuntimeException('in go()');
            });
        }
        while ($this->sleep(0.02)) {
            if ($this->mode === 'run') {
                throw new RuntimeException('in run()');
            }
        }
    }

    protected function unmount(): void
    {
        self::$unmounted = true;
    }

    protected function secret(): void
    {
    }
}

/**
 * Run $code in a forked process; returns [exit status, what it wrote to the pipe, seconds],
 * killing it after $timeout seconds (status -1).
 *
 * @return array{int, string, float}
 */
function forked(Closure $code, float $timeout = 3.0): array
{
    $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $started = microtime(true);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pipe[0]);
        try {
            $code($pipe[1]);
            exit(0);
        } catch (Throwable $e) {
            fwrite($pipe[1], 'threw ' . $e::class . ': ' . $e->getMessage());
            exit(3);
        }
    }
    fclose($pipe[1]);
    stream_set_blocking($pipe[0], false);
    $out = '';
    while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
        $out .= (string) fread($pipe[0], 65536);
        if (microtime(true) - $started > $timeout) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            return [-1, $out, microtime(true) - $started];
        }
        usleep(2000);
    }
    $out .= (string) stream_get_contents($pipe[0]);
    return [pcntl_wexitstatus($status), $out, microtime(true) - $started];
}

/** Write what the FakeTerminal shows and whether it was restored to $pipe, after run() returned or threw. */
function report($pipe, FakeTerminal $t): void
{
    fwrite($pipe, sprintf("[attached=%s alt=%s mouse=%s unmounted=%s]\n", var_export($t->isAttached(), true),
        var_export($t->surface->isAltScreen(), true), var_export($t->surface->mode(1002), true), var_export(FailingApp::$unmounted, true)));
}

it('ends the program when the closure that started the root throws (the exact shape)', function () {
    [$status, $out, $seconds] = forked(function ($pipe) {
        $t = new FakeTerminal(30, 5);
        try {
            fiber\run(function () use ($t) {
                $app = (new FailingApp())->start($t);
                $app->secret();     // a protected method called from outside
            });
        } finally {
            report($pipe, $t);
        }
    });
    expect($status)->toBe(3)
        ->and($out)->toContain('[attached=false alt=false mouse=false unmounted=true]')
        ->and($out)->toContain('threw Error: Call to protected method FailingApp::secret()')
        ->and($seconds)->toBeLessThan(1.5);
});

it('ends the program when the closure throws after a wait', function () {
    [$status, $out] = forked(function ($pipe) {
        $t = new FakeTerminal(30, 5);
        try {
            fiber\run(function () use ($t) {
                (new FailingApp())->start($t);
                fiber\sleep(0.05);
                throw new LogicException('later');
            });
        } finally {
            report($pipe, $t);
        }
    });
    expect($status)->toBe(3)
        ->and($out)->toContain('[attached=false alt=false mouse=false unmounted=true]')
        ->and($out)->toContain('threw LogicException: later');
});

it('ends the program when a coroutine that started the root throws', function () {
    [$status, $out] = forked(function ($pipe) {
        $t = new FakeTerminal(30, 5);
        try {
            fiber\run(function () use ($t) {
                fiber\go(function () use ($t) {
                    (new FailingApp())->start($t);
                    fiber\sleep(0.02);
                    throw new LogicException('in the starting coroutine');
                });
            });
        } finally {
            report($pipe, $t);
        }
    });
    expect($status)->toBe(3)
        ->and($out)->toContain('[attached=false')
        ->and($out)->toContain('threw LogicException: in the starting coroutine');
});

it('ends the program when the code that attached a bare surface throws', function () {
    [$status, $out] = forked(function ($pipe) {
        $t = new FakeTerminal(30, 5);
        try {
            fiber\run(function () use ($t) {
                $root = new Surface();
                $t->attach($root);
                fiber\sleep(0.02);
                throw new LogicException('after attach');
            });
        } finally {
            report($pipe, $t);
        }
    });
    expect($status)->toBe(3)
        ->and($out)->toContain('[attached=false alt=false')
        ->and($out)->toContain('threw LogicException: after attach');
});

it('ends a root started on a surface of its own when its starter throws', function () {
    [$status, $out] = forked(function ($pipe) {
        fiber\run(function () {
            (new FailingApp())->start(new Surface(20, 3));
            throw new LogicException('no device');
        });
    });
    expect($status)->toBe(3)->and($out)->toBe('threw LogicException: no device');
});

it('ends the program when the root\'s run() or go() coroutine throws', function (string $mode) {
    [$status, $out] = forked(function ($pipe) use ($mode) {
        $t = new FakeTerminal(30, 5);
        try {
            fiber\run(fn() => (new FailingApp($mode))->start($t));
        } finally {
            report($pipe, $t);
        }
    });
    expect($status)->toBe(3)
        ->and($out)->toContain('[attached=false alt=false mouse=false unmounted=true]')
        ->and($out)->toContain("threw RuntimeException: in $mode()");
})->with(['run', 'go']);

it('changes nothing when the starter returns normally', function () {
    fiber\run(function () {
        $t = new FakeTerminal(30, 5);
        $app = (new FailingApp())->start($t);
        fiber\sleep(0.03);
        expect($t->isAttached())->toBeTrue()->and($app->isMounted())->toBeTrue();
        $app->dispose();
    });
});

it('does without the watcher where the runtime cannot await the starter, and Tui::headless() still ends', function () {
    Runtime::$watching = false;
    try {
        [$status] = forked(function () {
            fiber\run(function () {
                (new FailingApp())->start(new FakeTerminal(30, 5));
                throw new LogicException('hangs without the watcher');
            });
        }, 0.5);
        expect($status)->toBe(-1);      // the documented limit: run() waits for the program
        [$status, $out] = forked(function () {
            fiber\run(function () {
                $t = Tui::headless(new FailingApp('run'), 30, 5);
                $t->settle();
            });
        });
        expect($status)->toBe(3)->and($out)->toContain('in run()');
    } finally {
        Runtime::$watching = null;
    }
});

it('quits a Tui program with ctrl+q when nothing binds it, and Help lists it', function () {
    fiber\run(function () {
        $app = new class () extends Component {
            protected function render(): Element
            {
                return new Element\Column([new Text('app'), new Element\Help()]);
            }
        };
        $t = Tui::headless($app, 30, 3);
        $t->settle();
        expect($t->text())->toContain('ctrl+q quit');
        $t->press('ctrl+q');
        expect($app->isMounted())->toBeFalse()->and($t->isAttached())->toBeFalse();
    });
});

it('leaves ctrl+q to a component that binds it', function () {
    fiber\run(function () {
        $app = new class () extends Component {
            public int $asked = 0;

            protected function bindings(): array
            {
                return ['ctrl+q' => 'ask'];
            }

            public function ask(): void
            {
                $this->asked++;
            }
        };
        $t = Tui::headless($app, 30, 3);
        $t->press('ctrl+q');
        expect($app->asked)->toBe(1)->and($app->isMounted())->toBeTrue();
        $t->detach();
    });
});

/** Run tests/fixtures/programs/crash.php $mode on a pty; [exit status, stderr, the terminal after, seconds]. */
function crashOnPty(string $mode): array
{
    $err = tempnam(sys_get_temp_dir(), 'crash');
    $script = dirname(__DIR__) . '/fixtures/programs/crash.php';
    $result = null;
    fiber\run(function () use ($mode, $err, $script, &$result) {
        $s = new Surface(60, 12);
        $started = microtime(true);
        $pty = Pty::start(['sh', '-c', 'php "$0" "$1" 2>"$2"; echo "status=$?"; stty -a', $script, $mode, $err], $s);
        $code = $pty->wait(10);
        $result = [$code, $s, microtime(true) - $started];
    });
    $stderr = (string) file_get_contents($err);
    unlink($err);
    [$code, $s, $seconds] = $result;
    preg_match('/status=(\d+)/', $s->text(), $m);
    return [(int) ($m[1] ?? -1), $stderr, $s, $seconds];
}

it('restores the real terminal and exits non-zero with the message on stderr', function (string $mode, string $message) {
    [$status, $stderr, $s, $seconds] = crashOnPty($mode);
    expect($status)->not->toBe(0)
        ->and($stderr)->toContain($message)
        ->and($s->isAltScreen())->toBeFalse()
        ->and($s->mode(1000))->toBeFalse()
        ->and($s->mode(1002))->toBeFalse()
        ->and($s->mode(2004))->toBeFalse()
        ->and($s->mode(1004))->toBeFalse()
        ->and($s->cursor()->visible)->toBeTrue()
        ->and($s->text())->toMatch('/(?<!-)icanon/')      // stty: cooked mode again
        ->and($s->text())->toMatch('/(?<!-)echo /')
        ->and($seconds)->toBeLessThan(2.5);     // (php's own start-up included)
})->with([
    'the exact shape' => ['start', 'Call to protected method CrashApp::secret()'],
    'a bare surface' => ['attach', 'failed after attach()'],
    'run()' => ['run', 'failed in run()'],
    'go()' => ['go', 'failed in go()'],
    'Tui::run()' => ['tui', 'failed in run()'],
    'a closure given to Tui::run()' => ['closure', 'failed in the closure'],
    'a coroutine of Tui::go()' => ['tuigo', 'failed in Tui::go()'],
    'a key handler of Tui::run(keys:)' => ['keys', 'failed in a key handler'],
]);
