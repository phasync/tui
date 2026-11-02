<?php

use phasync\Tui\Pty;
use phasync\Tui\Surface;

it('sends what a frame still had to send before the restore sequence when the program quits, so nothing of it prints on the main screen', function () {
    // (a program that quit while a Sixel frame was on its way left the rest of it as text on
    // the shell, and the images after the switch back: the restore bytes went around the queue)
    $script = dirname(__DIR__) . '/fixtures/programs/exit-in-flight.php';
    $s = null;
    fiber\run(function () use ($script, &$s) {
        $s = new Surface(60, 12);
        $pty = Pty::start(['sh', '-c', 'php "$0"; echo "status=$?"', $script], $s);
        $pty->wait(20);
    });
    expect($s->text())->toContain('status=0')->and($s->text())->toContain('done')
        ->and($s->text())->not->toContain('SPAM')->and($s->isAltScreen())->toBeFalse();
});
