<?php

/* The parser is generated from ansi.cpeg by charm-compile (a global install of charm/parsing: it is
   not a dependency of this package), so this test runs where that compiler is on the PATH. */

$compiler = trim((string) shell_exec('command -v charm-compile 2>/dev/null'));

it('has a compiled parser that is current with ansi.cpeg', function () use ($compiler) {
    $dir = dirname(__DIR__, 2) . '/src/Surface';
    $out = sys_get_temp_dir() . '/phasync-tui-parser-' . getmypid() . '.php';
    $cmd = sprintf(
        '%s %s -q -c AnsiParser -n %s -o %s 2>&1',
        escapeshellarg($compiler),
        escapeshellarg($dir . '/ansi.cpeg'),
        escapeshellarg('phasync\Tui\Surface'),
        escapeshellarg($out),
    );
    exec($cmd, $output, $status);
    expect($status)->toBe(0, implode("\n", $output));
    $fresh = preg_replace('/Generated parser for: .*/', '', file_get_contents($out));
    $committed = preg_replace('/Generated parser for: .*/', '', file_get_contents($dir . '/AnsiParser.php'));
    unlink($out);
    expect($committed)->toBe($fresh, 'src/Surface/AnsiParser.php is stale: run composer grammar');
})->skip($compiler === '', 'charm-compile is not on the PATH (install charm/parsing globally to check the generated parser)');
