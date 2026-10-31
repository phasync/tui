<?php

/*
 * TODO-0.0.3-beta5 item 1, as of 0.0.3-beta8: the library requires fiber-interop/api 0.3.2 and
 * conflicts with fiber-interop/phasync before 0.4.2, so it can always watch the code that started
 * a program, and
 *
 *     fiber\run(function () {
 *         $t = Tui::headless(fn() => new Text('hi'), 60, 20);
 *         throw new LogicException('test code failed');
 *     });
 *
 * ends with that exception (beta5 to beta7 warned on stderr where the installed tags could
 * not). What the program owns ends it too. Each case runs in a process of its own, killed after
 * a timeout.
 */

/** Run $code (PHP, after the autoloader) in a process; [status or null when killed, stdout, stderr]. */
function inProcess(string $code, float $timeout = 3.0): array
{
    $file = tempnam(sys_get_temp_dir(), 'tui') . '.php';
    file_put_contents($file, "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
        . "use phasync\\Tui\\{Tui, State};\nuse phasync\\Tui\\Element\\Text;\n" . $code);
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

it('ends the exact shape with its exception, and prints no warning before it', function () {
    [$status, , $err] = inProcess(<<<'PHP'
        fiber\run(function () {
            $t = Tui::headless(fn() => new Text('hi'), 60, 20);
            throw new LogicException('test code failed');
        });
        PHP);
    expect($status)->toBe(255)->and($err)->toContain('test code failed')->not->toContain('phasync/tui: ');
});

it('still ends what Tui::headless() owns: the root closure failing, a Tui::go() coroutine failing, quitting', function (string $code, string $message) {
    [$status, , $err] = inProcess($code);
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
    [$status, $out] = inProcess(<<<'PHP'
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
