<?php

use phasync\Tui\Surface;
use phasync\Tui\Tests\Support\Tmux;

/**
 * Output of real programs (recorded with tools/record-fixtures.php: ls --color, a symfony/console
 * progress bar, vim and less start and exit, htop) replayed into a Surface must give the screen
 * tmux showed at the same moment.
 */
$dirs = glob(dirname(__DIR__) . '/fixtures/recorded/*', GLOB_ONLYDIR);

foreach ($dirs as $dir) {
    it('renders recorded ' . basename($dir) . ' output like tmux', function () use ($dir) {
        $s = new Surface(80, 24);
        foreach (glob("$dir/*.bytes") as $bytesFile) {
            $phase = basename($bytesFile, '.bytes');
            $s->write(file_get_contents($bytesFile));
            $expected = file_get_contents("$dir/$phase.txt");
            expect(rtrim($s->text(), "\n"))->toBe(rtrim($expected, "\n"), "text after phase $phase");
            [$x, $y] = array_map('intval', explode(' ', file_get_contents("$dir/$phase.cursor")));
            expect([$s->cursor()->x, $s->cursor()->y])->toBe([$x, $y], "cursor after phase $phase");
            $replay = new Surface(80, 24);
            $replay->write(Tmux::replayable(file_get_contents("$dir/$phase.ansi")));
            if (trim(file_get_contents("$dir/$phase.ansi")) !== '') {
                expect($s->dump())->toBe($replay->dump(), "styles after phase $phase");
            }
        }
    });
}
