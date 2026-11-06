<?php

use phasync\Tui\Pty;
use phasync\Tui\Surface;

/** Run tests/fixtures/programs/terminal-mode.php on a 50x10 pty; what it recorded, by key. */
function terminalMode(): array
{
    $file = tempnam(sys_get_temp_dir(), 'mode');
    $script = dirname(__DIR__) . '/fixtures/programs/terminal-mode.php';
    fiber\run(function () use ($file, $script) {
        Pty::start(['php', $script, $file], new Surface(50, 10))->wait(10);
    });
    $out = [];
    foreach (file($file, \FILE_IGNORE_NEW_LINES) as $line) {
        [$key, $value] = preg_split('/[=:] ?/', $line, 2);
        $out[$key] = $value;
    }
    unlink($file);
    return $out;
}

it('takes the terminal into raw mode while attached and restores it exactly, with Io\Terminal where PHP has it', function () {
    $mode = terminalMode();
    expect($mode['native'])->toBe(method_exists(\Io\Terminal\Terminal::class, 'fromStdio') ? 'yes' : 'no')
        ->and($mode['size'])->toBe('50x10')
        ->and($mode['while'])->toMatch('/-icanon/')->toMatch('/-echo /')->toMatch('/-isig/')
        ->and($mode['after'])->toBe($mode['before']);
});
