<?php

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Tests\Support\AnsiComposite;

/**
 * A random window tree on a root of 30x12, changed $steps times; after every few changes the
 * root must show what the reference ANSI compositor makes of the same tree. With $own, the
 * hosts' own producers write too (mirrored to their twins, the reference's view of their cells).
 */
function blitScenario(int $seed, bool $own, int $steps = 120, ?Closure $debug = null): void
{
    mt_srand($seed);
    $border = Style::of(Color::BLUE);
    $focus = Style::of(Color::GREEN, Color::DEFAULT, Style::BOLD);
    $twins = new WeakMap();
    $root = new Surface(30, 12);
    $twins[$root] = new Surface(30, 12);
    $hosts = [$root];
    $leaves = [];
    $views = [];
    $place = function (Surface $host, Surface $child) use (&$views, $border, $focus): void {
        $v = $host->host($child, mt_rand(-3, $host->cols() - 2), mt_rand(-2, $host->rows() - 1), mt_rand(2, 18), mt_rand(1, 9));
        if (mt_rand(0, 1) === 1) {
            $v->setBorder(['single', 'round', 'double', 'heavy', 'ascii'][mt_rand(0, 4)], $border, $focus)
                ->setTitle(mt_rand(0, 1) ? 'w' . \count($views) : '', ['left', 'center', 'right'][mt_rand(0, 2)]);
        }
        $views[] = [$host, $child];
    };
    // two or three windows on the root, some of which host windows of their own, to depth 3
    $grow = function (Surface $host, int $depth) use (&$grow, &$hosts, &$leaves, $twins, $place): void {
        for ($i = mt_rand(2, 3); $i > 0; $i--) {
            $cols = mt_rand(3, 20);
            $rows = mt_rand(1, 8);
            $child = new Surface($cols, $rows, 40);
            if ($depth < 3 && mt_rand(0, 2) === 0) {
                $twins[$child] = new Surface($cols, $rows, 40);
                $hosts[] = $child;
                $grow($child, $depth + 1);
            } else {
                $leaves[] = $child;
            }
            $place($host, $child);
        }
    };
    $grow($root, 1);
    $pieces = ['ab', 'X', ' ', '  ', '中', '👍', 'é', "\e[1m", "\e[0m", "\e[31m", "\e[44m", "\e[7m", "\e[4m", "\e[38;2;1;2;3m",
        "\r\n", "\r\n\r\n", "\e[K", "\e[2;2H", "\e[H", "\e[1L", "\e[2P", "\e[3@", Ansi::hyperlink('ln', 'http://x', 'k'), '中文字'];
    $writers = $own ? [...$leaves, ...$hosts] : $leaves;
    $oracle = new AnsiComposite($twins, $border, $focus);
    for ($step = 0; $step < $steps; $step++) {
        [$host, $s] = $views[mt_rand(0, \count($views) - 1)];
        $v = $host->viewport($s);
        switch (mt_rand(0, 17)) {
            case 15:
                mt_rand(0, 2) === 0 ? $v?->clearSelection() : $v?->select(mt_rand(-1, 8), mt_rand(-3, 6), mt_rand(-1, 10), mt_rand(-3, 8));
                break;
            case 6:
                $v?->move(mt_rand(-3, $host->cols() - 2), mt_rand(-2, $host->rows() - 1));
                break;
            case 7:
                $host->raise($s);
                break;
            case 8:
                $v?->scrollBy(mt_rand(-4, 4));
                break;
            case 9:
                $v?->resize(mt_rand(1, 18), mt_rand(1, 9));
                break;
            case 10:
                $v?->pan(mt_rand(0, 6));
                break;
            case 11:
                $s->focus();
                break;
            case 12:
                $v?->setBorder(mt_rand(0, 2) === 0 ? false : ['single', 'round', 'double', 'heavy', 'ascii'][mt_rand(0, 4)], $border, $focus)
                    ->setTitle(mt_rand(0, 1) ? 'title' : 't', ['left', 'center', 'right'][mt_rand(0, 2)]);
                break;
            case 13:
                $v?->setShadow([null, Color::DEFAULT, Color::rgb(20, 20, 20)][mt_rand(0, 2)]);
                break;
            case 14:
                if ($v !== null) {
                    [$x, $y, $w, $h] = $v->rect();
                    $host->unhost($s);
                    if (mt_rand(0, 3) > 0) {
                        $host->host($s, $x + mt_rand(-1, 1), $y, $w, $h);
                    }
                } else {
                    $host->host($s, mt_rand(-3, $host->cols() - 2), mt_rand(-2, $host->rows() - 1), mt_rand(2, 18), mt_rand(1, 9));
                }
                break;
            default:
                $w = $writers[mt_rand(0, \count($writers) - 1)];
                $bytes = '';
                for ($j = mt_rand(1, 6); $j > 0; $j--) {
                    $bytes .= $pieces[mt_rand(0, \count($pieces) - 1)];
                }
                $w->write($bytes);
                if (isset($twins[$w])) {
                    $twins[$w]->write($bytes);
                }
        }
        if ($step % 3 === 1 && $hosts !== []) {
            // (a host below composed on its own as well: its frame, and the root's after it)
            $nested = $hosts[mt_rand(0, \count($hosts) - 1)];
            expect($nested->dump())->toBe($oracle->of($nested)->dump(), "seed $seed step $step, a host below");
        }
        if ($step % 3 === 2) {
            $debug && $debug($root, $step);
            expect($root->dump())->toBe($oracle->of($root)->dump(), "seed $seed step $step");
        }
    }
}

it('composes random window trees exactly as the ANSI compositor did (property)', function () {
    foreach (range(1, 40) as $seed) {
        blitScenario($seed, false);
    }
});

it('composes them so when the hosts\' own producers write too: their cells show where no window covers them (property)', function () {
    foreach (range(1, 40) as $seed) {
        blitScenario($seed, true);
    }
});
