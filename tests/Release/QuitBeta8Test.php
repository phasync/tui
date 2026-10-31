<?php

/*
 * TODO-0.0.3-beta8 item 2: Tui::quit() from a Tui::go() job under Tui::headless() was a fatal
 * "Uncaught phasync\Tui\Quit: The program has ended" from another job's Tui::sleep().
 */

use phasync\Tui\Element\Text;
use phasync\Tui\Tui;

it('ends every Tui::go() job quietly when one of them quits, headless', function () {
    $ended = [];
    fiber\run(function () use (&$ended) {
        $t = Tui::headless(fn() => new Text('hi'), 20, 3);
        Tui::go(function () use (&$ended) {
            try {
                while (true) {
                    Tui::sleep(0.05);
                }
            } finally {
                $ended[] = 'loop';
            }
        });
        Tui::go(function () use (&$ended) {
            Tui::sleep(0.2);
            Tui::quit();
            $ended[] = 'quitter';
        });
        $t->settle();
        Tui::sleep(0.5);
        $t->detach();
    });
    expect($ended)->toContain('loop')->toContain('quitter');
});

it('ends every Tui::go() job quietly when one of them quits, headless in inline mode', function () {
    $ended = [];
    fiber\run(function () use (&$ended) {
        $t = Tui::headless(fn() => new Text('hi'), 20, 3, inline: true);
        Tui::go(function () use (&$ended) {
            try {
                while (true) {
                    Tui::sleep(0.05);
                }
            } finally {
                $ended[] = 'loop';
            }
        });
        Tui::go(function () {
            Tui::sleep(0.2);
            Tui::quit();
        });
        $t->settle();
        Tui::sleep(0.5);
        $t->detach();
        expect($t->frozen() . "\n" . $t->surface->text())->toContain('hi');
    });
    expect($ended)->toBe(['loop']);
});

it('ends the same program quietly with Tui::inline() on a terminal (a pty)', function () {
    $file = tempnam(sys_get_temp_dir(), 'tui') . '.php';
    file_put_contents($file, "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . <<<'PHP'
        use phasync\Tui\Tui;
        use phasync\Tui\Element\Text;
        fiber\run(function () {
            Tui::go(function () { while (true) { Tui::sleep(0.05); } });
            Tui::go(function () { Tui::sleep(0.2); Tui::quit(); });
            Tui::inline(fn() => new Text('hi'));
            Tui::sleep(0.1);
            echo "returned\n";
        });
        PHP);
    try {
        fiber\run(function () use ($file) {
            $screen = new phasync\Tui\Surface(40, 6);
            $pty = phasync\Tui\Pty::start([PHP_BINARY, $file], $screen);
            expect($pty->wait(10))->toBe(0)
                ->and($screen->text())->toContain('returned')->not->toContain('Uncaught');
        });
    } finally {
        @unlink($file);
    }
});
