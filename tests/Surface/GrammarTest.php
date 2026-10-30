<?php

use Charm\Parsing\Compiler\GrammarCompiler;

it('has a compiled parser that is current with ansi.cpeg', function () {
    $dir = dirname(__DIR__, 2) . '/src/Surface';
    $out = sys_get_temp_dir() . '/phasync-tui-parser-' . getmypid() . '.php';
    $cmd = sprintf(
        '%s %s -q -c AnsiParser -n %s -o %s 2>&1',
        escapeshellarg(dirname(__DIR__, 2) . '/vendor/bin/charm-compile'),
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
})->skip(!class_exists(GrammarCompiler::class), 'charm/parsing is not installed');
