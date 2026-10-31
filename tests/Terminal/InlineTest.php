<?php

/*
 * Inline mode (SPEC 4.1): the root as a live region at the bottom of the terminal's normal
 * screen; rows that leave the root's top are written once above it, as they were then.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;

/** Every line of $s, scrollback first, trailing blank lines cut. */
function allLines(Surface $s): array
{
    $lines = [];
    for ($y = -$s->scrollbackSize(); $y < $s->rows(); $y++) {
        $lines[] = $s->line($y);
    }
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }
    return $lines;
}

/** Five progress bars and a total, the value of each in a public array. */
final class Builds extends Component
{
    public array $done = [0, 0, 0, 0, 0];

    protected function render(): Element
    {
        $rows = [];
        foreach ($this->done as $i => $d) {
            $rows[] = new ProgressBar($d / 100, label: "job $i $d%");
        }
        $rows[] = new Text('total ' . array_sum($this->done) . '/500');
        return new Column($rows);
    }
}

it('shows five progress bars and a total as a region under what the terminal shows, and leaves the last frame', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(40, 12);
        $t->surface->write("$ php build.php\r\n");
        $app = (new Builds())->start($t);
        $t->run(function (FakeTerminal $t) use ($app) {
            $t->settle();
            expect($t->frozen())->toBe('$ php build.php')
                ->and(explode("\n", $t->region()))->toHaveCount(6)
                ->and($t->region())->toContain('job 0 0%')->and($t->region())->toEndWith('total 0/500')
                ->and($t->output())->not->toContain("\e[?1049h")->and($t->output())->not->toContain("\e[?1000h");
            for ($step = 1; $step <= 10; $step++) {
                $app->done = array_map(fn($d, $i) => min(100, $d + 5 * ($i + 1)), $app->done, array_keys($app->done));
                $app->requestRender();
                $t->settle();
            }
            $region = explode("\n", $t->region());
            expect($region[4])->toContain('job 4 100%')->and($region[0])->toContain('job 0 50%')
                ->and($region[5])->toBe('total 450/500')->and($t->frozen())->toBe('$ php build.php');
            // nothing above the region was touched: only relative moves, no absolute positions
            expect(preg_match('/\e\[\d+;\d+H/', $t->output()))->toBe(0);
        });
        // detached: the last frame stands, the cursor under it, the terminal given back
        expect(allLines($t->surface))->toHaveCount(7)
            ->and(allLines($t->surface)[6])->toBe('total 450/500')
            ->and($t->surface->cursor()->y)->toBe(7)->and($t->surface->cursor()->x)->toBe(0)
            ->and($t->surface->cursor()->visible)->toBeTrue()
            ->and($t->output())->not->toContain("\e[?1049l");
    });
});

it('runs the same tree full screen and inline from one source', function () {
    $screens = [];
    foreach ([new FakeTerminal(40, 12), FakeTerminal::inline(40, 12)] as $t) {
        \fiber\run(function () use ($t, &$screens) {
            $app = (new Builds())->start($t);
            $app->done = [10, 20, 30, 40, 50];
            $app->requestRender();
            $t->settle();
            $screens[] = $t->isInline() ? $t->region() : rtrim($t->text(), "\n");
            $t->detach();
        });
    }
    expect($screens[1])->toBe($screens[0])->and($screens[0])->toContain('job 4 50%');
});

it('freezes each of twenty process lines on a 10-row terminal as it showed when it left the region', function () {
    mt_srand(7);
    \fiber\run(function () {
        $t = FakeTerminal::inline(30, 10);
        $root = new Surface();
        $t->attach($root);
        $t->run(function (FakeTerminal $t) use ($root) {
            $progress = [];
            $frozen = [];
            $line = function (int $i) use (&$progress) {
                return sprintf('proc %02d: %s', $i, $progress[$i] >= 100 ? 'done' : $progress[$i] . '%');
            };
            $started = 0;
            for ($step = 0; $step < 400 && count($frozen) < 20; $step++) {
                if ($started < 20 && ($started < 3 || mt_rand(0, 3) === 0)) {
                    $progress[$started] = 0;
                    $root->write($line($started) . "\r\n");      // the cursor waits on the row after the last line
                    $started++;
                    // a line that scrolled off the root's top is frozen as it is now
                    for ($i = 0; $i < $root->linesScrolled(); $i++) {
                        $frozen[$i] ??= $line($i);
                    }
                }
                $i = mt_rand(0, $started - 1);
                if ($i >= $root->linesScrolled() && $progress[$i] < 100) {
                    $progress[$i] = min(100, $progress[$i] + mt_rand(5, 40));
                    $row = $i - $root->linesScrolled();
                    $root->write("\e7\e[" . ($row + 1) . ";1H\e[2K" . $line($i) . "\e8");
                }
                if (mt_rand(0, 2) === 0) {
                    $t->refresh();                                    // frames at random moments
                }
                if ($started === 20 && $step > 300) {
                    $root->write("\r\n");                            // push the rest out
                    for ($i = 0; $i < $root->linesScrolled(); $i++) {
                        $frozen[$i] ??= $line($i);
                    }
                }
            }
            $t->refresh();
            $shown = explode("\n", $t->frozen());
            $scrolled = $root->linesScrolled();
            expect(\array_slice($shown, 0, $scrolled))->toBe(\array_slice(array_values($frozen), 0, $scrolled))
                ->and($scrolled)->toBeGreaterThan(10);
            // and the region shows the root's rows that have content
            $lines = [];
            for ($y = 0; $y < $root->rows(); $y++) {
                $lines[] = $root->line($y);
            }
            expect(rtrim($t->region(), "\n"))->toBe(rtrim(implode("\n", array_slice($lines, 0, count(explode("\n", $t->region())))), "\n"));
            // a replay of the byte stream into a terminal of the same size shows the same
            $replay = new Surface(30, 10);
            $replay->write($t->output());
            expect(allLines($replay))->toBe(allLines($t->surface));
        });
    });
});

it('prints lines above the region between frames, and the region stays whole under them', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(30, 8);
        $t->surface->write("$ ./deploy\r\n");
        $root = new Surface();
        $t->attach($root);
        $t->run(function (FakeTerminal $t) use ($root) {
            $root->write("\e[?25l\e[1mdeploying\e[0m\r\n[####      ] 40%");
            $t->refresh();
            $t->println('step 1: built');
            $t->println("step 2: \e[32mtested\e[0m\nstep 3: a line long enough to wrap onto a second row");
            $root->write("\r\e[2K[######    ] 60%");
            $t->refresh();
            expect(explode("\n", $t->frozen()))->toBe(['$ ./deploy', 'step 1: built', 'step 2: tested', 'step 3: a line long enough to', 'wrap onto a second row'])
                ->and($t->region())->toBe("deploying\n[######    ] 60%")
                ->and($t->surface->cell(8, $t->surface->cursor()->y - 2 - 3)->style->fg)->toBe(2);     // 'tested' in green
            $t->println('step 4');
            $t->refresh();
            expect($t->frozen())->toEndWith('step 4')->and($t->region())->toBe("deploying\n[######    ] 60%");
            $replay = new Surface(30, 8);
            $replay->write("$ ./deploy\r\n" . $t->output());
            expect(allLines($replay))->toBe(allLines($t->surface));
        });
    });
});

it('erases the region on detach with keep: false, and prints held lines after a full-screen detach', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(20, 6, keep: false);
        $t->surface->write("$ run\r\n");
        $root = new Surface();
        $t->attach($root);
        $root->write("working\r\nhard");
        $t->refresh();
        expect($t->region())->toBe("working\nhard");
        $t->detach();
        expect(allLines($t->surface))->toBe(['$ run'])->and($t->surface->cursor()->y)->toBe(1);
        $full = new FakeTerminal(20, 6);
        $full->attach(new Surface());
        $full->println('after the program');
        expect($full->output())->not->toContain('after the program');
        $full->detach();
        expect($full->text())->toContain('after the program');
    });
});

it('repaints the region on a width change and scrolls its top rows out when the terminal gets lower', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(20, 8);
        $root = new Surface();
        $t->attach($root);
        $t->run(function (FakeTerminal $t) use ($root) {
            $root->write("\e[?25lone\r\ntwo\r\nthree\r\nfour\r\nfive");
            $t->refresh();
            expect($t->region())->toBe("one\ntwo\nthree\nfour\nfive");
            $t->resize(25, 8);
            $t->refresh();
            expect($t->region())->toBe("one\ntwo\nthree\nfour\nfive")->and($t->frozen())->toBe('');
            $t->resize(25, 4);
            $root->write("\r\nsix");
            $t->refresh();
            expect($t->region())->toBe("four\nfive\nsix")->and($t->frozen())->toBe("one\ntwo\nthree");
        });
    });
});

it('reports the mouse in inline mode only while a surface asks for it', function () {
    \fiber\run(function () {
        $t = FakeTerminal::inline(20, 6);
        $root = new Surface();
        $t->attach($root);
        $root->write('x');
        $t->refresh();
        expect($t->takeOutput())->not->toContain("\e[?1000h");
        $root->write("\e[?1002h");
        $t->refresh();
        expect($t->takeOutput())->toContain("\e[?1000h\e[?1002h\e[?1006h");
        $root->write("\e[?1002l");
        $t->refresh();
        expect($t->takeOutput())->toContain("\e[?1002l\e[?1000l");
        $t->detach();
    });
});
