<?php

/*
 * TODO-0.0.3-beta5 item 1: on the tagged fiber-interop versions (api 0.3.1, phasync adapter
 * 0.4.1) the library cannot watch the code that started a program, and
 *
 *     fiber\run(function () {
 *         $t = Tui::headless(fn() => new Text('hi'), 60, 20);
 *         throw new LogicException('test code failed');
 *     });
 *
 * hangs. It must never hang silently: the library says so on stderr at start-up, naming the
 * installed and the needed versions, and what it owns (its runners' closures, Tui::go()
 * coroutines, the root's closure) still ends the program. Each case runs in a process of its
 * own with canWatch() forced false, killed after a timeout.
 */

use phasync\Tui\Internal\Runtime;

/** Run $code (PHP, after the autoloader, with the watcher forced off) in a process; [status or null when killed, stdout, stderr]. */
function withoutWatcher(string $code, float $timeout = 3.0): array
{
    $file = tempnam(sys_get_temp_dir(), 'tui') . '.php';
    file_put_contents($file, "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
        . "use phasync\\Tui\\{Tui, State};\nuse phasync\\Tui\\Element\\Text;\n"
        . "phasync\\Tui\\Internal\\Runtime::\$watching = false;\n" . $code);
    $p = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    [$out, $err] = ['', ''];
    $until = microtime(true) + $timeout;
    $status = null;
    while (microtime(true) < $until) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            $status = $st['exitcode'];
            break;
        }
        usleep(10000);
    }
    if ($status === null) {
        proc_terminate($p, 9);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    proc_close($p);
    unlink($file);
    return [$status, $out, $err];
}

it('says on stderr at start-up that the exact shape cannot end, naming the installed and needed versions', function () {
    [$status, , $err] = withoutWatcher(<<<'PHP'
        fiber\run(function () {
            $t = Tui::headless(fn() => new Text('hi'), 60, 20);
            throw new LogicException('test code failed');
        });
        PHP, 1.5);
    $lines = array_values(array_filter(explode("\n", $err)));
    expect($status)->toBeNull()                    // (it still waits: the runtime cannot tell it)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith('phasync/tui: with fiber-interop/api ')
        ->toContain('fiber-interop/phasync ')
        ->toContain('Install fiber-interop/api >= 0.3.2, fiber-interop/phasync >= 0.4.2.');
});

it('warns once per process, and not for Tui::run() and Tui::inline(), which own their programs', function () {
    [$status, $out, $err] = withoutWatcher(<<<'PHP'
        fiber\run(function () {
            for ($i = 0; $i < 3; $i++) {
                Tui::headless(new Text('x'), 10, 1)->detach();
            }
        });
        echo "done\n";
        PHP);
    expect($status)->toBe(0)->and($out)->toBe("done\n")->and(substr_count($err, 'phasync/tui: '))->toBe(1);
    [$status, $out, $err] = withoutWatcher(<<<'PHP'
        $_SERVER['argv'][] = '--headless=20x2@0.05';
        Tui::run(new Text('hello'));
        Tui::inline(new Text('again'));
        PHP);
    expect($status)->toBe(0)->and($out)->toContain('hello')->toContain('again')->and($err)->toBe('');
});

it('still ends what Tui::headless() owns: the root closure failing, a Tui::go() coroutine failing, quitting', function (string $code, string $message) {
    [$status, , $err] = withoutWatcher($code);
    expect($status)->toBe(255)->and($err)->toContain($message);
})->with([
    'the closure given as the root' => [<<<'PHP'
        fiber\run(function () {
            $s = new State(['n' => 0]);
            Tui::headless(function () use ($s) {
                if ($s->n > 0) { throw new LogicException('the root closure failed'); }
                return new Text('n');
            }, 10, 1);
            fiber\sleep(0.05);
            $s->n = 1;
        });
        PHP, 'the root closure failed'],
    'a coroutine of Tui::go()' => [<<<'PHP'
        fiber\run(function () {
            Tui::headless(new Text('x'), 10, 1);
            Tui::go(function () { Tui::sleep(0.05); throw new LogicException('the job failed'); });
        });
        PHP, 'the job failed'],
]);

it('ends a headless program that quits, with coroutines of Tui::go() looping in it, on any runtime', function () {
    [$status, $out] = withoutWatcher(<<<'PHP'
        fiber\run(function () {
            $s = new State(['n' => 0]);
            Tui::go(function () use ($s) { while (true) { Tui::sleep(0.01); $s->n++; } });
            $t = Tui::headless(fn() => new Text("n={$s->n}"), 10, 1);
            fiber\sleep(0.1);
            $t->press('ctrl+q');
        });
        echo "returned\n";
        PHP);
    expect($status)->toBe(0)->and($out)->toBe("returned\n");
});

it('decides from the installed versions whether it can watch', function () {
    $canWatch = (new ReflectionMethod(Runtime::class, 'canWatch'))->invoke(null);
    expect($canWatch)->toBeTrue();      // (the suite runs on the dev branches, which carry the fix)
});
