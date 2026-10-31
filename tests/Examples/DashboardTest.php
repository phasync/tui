<?php

/*
 * SPEC 9.6 / TODO-0.0.3-beta2 item 3, the acceptance test: examples/dashboard.php, under sixty
 * lines and without a class, correct under FakeTerminal at 60x20 and 160x50, stacked under 80
 * columns, in all three themes, flicker-free, and inline showing the three boxes only.
 */

use phasync\Tui\Color;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Surface;
use phasync\Tui\Tui;

/**
 * Run examples/dashboard.php with $args in this process, its runner replaced by a FakeTerminal
 * of $cols x $rows given to $check; returns when the program has ended.
 */
function dashboard(array $args, int $cols, int $rows, Closure $check): void
{
    $code = (string) file_get_contents(__DIR__ . '/../../examples/dashboard.php');
    $code = preg_replace(['/^<\?php/', "/^require .*;\n/m"], '', $code);
    $code = str_replace(
        ['Tui::inline($boxes, theme: $theme)', 'Tui::run($screen, theme: $theme)'],
        ['$GLOBALS[\'dashboardRun\']($boxes, true, $theme)', '$GLOBALS[\'dashboardRun\']($screen, false, $theme)'],
        $code,
        $count,
    );
    expect($count)->toBe(2);
    $GLOBALS['dashboardRun'] = function (Closure $root, bool $inline, ?string $theme) use ($cols, $rows, $check): void {
        $t = Tui::headless($root, $cols, $rows, inline: $inline, theme: $theme);
        $t->run(fn() => $check($t));
    };
    (static function (array $argv) use ($code) {
        eval($code);
    })(['dashboard.php', ...$args]);
}

it('is a program of under sixty lines without a class, styled with utility classes only', function () {
    $code = (string) file_get_contents(__DIR__ . '/../../examples/dashboard.php');
    expect(count(file(__DIR__ . '/../../examples/dashboard.php')))->toBeLessThan(60)
        ->and(preg_match('/^\s*(final\s+)?class\s/m', $code))->toBe(0)
        ->and($code)->not->toContain('Stylesheet')->not->toContain('style:')->not->toContain('<fg=');
});

it('shows the header, three boxes stacked, the log, the notes and the keys at 60x20', function () {
    dashboard([], 60, 20, function (FakeTerminal $t) {
        expect($t->waitFor('run 1', 5))->toBeTrue();
        $rows = screenRows($t);
        $at = fn(string $title) => array_key_first(array_filter($rows, fn($r) => str_contains($r, "╭ $title")));
        expect($rows[0])->toContain('deploying v2.4')->toContain('staging')
            ->and($at('build'))->toBeLessThan($at('test'))->and($at('test'))->toBeLessThan($at('deploy'))   // stacked
            ->and($at('events'))->toBeGreaterThan($at('deploy'))->and($at('release notes'))->toBeGreaterThan($at('events'))
            ->and(end($rows))->toContain('ctrl+q quit')
            ->and($t->text())->toContain('%')->toContain('run ');
        foreach ($rows as $row) {
            expect(mb_strwidth($row))->toBeLessThanOrEqual(60);
        }
        $t->press('ctrl+q');
    });
});

it('puts the three boxes side by side, and the log beside the notes, at 160x50', function () {
    dashboard([], 160, 50, function (FakeTerminal $t) {
        expect($t->waitFor('done', 5))->toBeTrue();
        $rows = screenRows($t);
        expect($rows[1])->toContain('╭ build')->toContain('╭ test')->toContain('╭ deploy')
            ->and($rows[5])->toContain('╭ events')->toContain('╭ release notes')
            ->and($t->text())->toContain('Faster start-up')->toContain('日本語')
            ->and($rows[49])->toContain('ctrl+q quit');
        $t->press('ctrl+q');
    });
});

it('looks right in each theme: the same layout, mono without colours', function () {
    foreach (['dark', 'light', 'mono'] as $theme) {
        dashboard(["--theme=$theme"], 100, 24, function (FakeTerminal $t) use ($theme) {
            expect($t->waitFor('run ', 5))->toBeTrue();
            $rows = screenRows($t);
            expect($rows[1])->toContain('╭ build')->toContain('╭ deploy');
            $colours = 0;
            for ($y = 0; $y < 24; $y++) {
                for ($x = 0; $x < 100; $x++) {
                    $style = $t->surface->cell($x, $y)->style;
                    $colours += $style->fg !== Color::DEFAULT || $style->bg !== Color::DEFAULT ? 1 : 0;
                }
            }
            expect($theme === 'mono' ? $colours === 0 : $colours > 100)->toBeTrue();
            $t->press('ctrl+q');
        });
    }
});

it('is flicker-free: each frame writes only the cells that changed, never a whole screen', function () {
    dashboard([], 100, 30, function (FakeTerminal $t) {
        expect($t->waitFor('run ', 5))->toBeTrue();
        $t->settle();
        $replay = new Surface(100, 30);
        $replay->write($t->takeOutput());
        \fiber\sleep(1.5);
        $t->settle();
        $frames = explode("\e[?2026h", $t->takeOutput());
        expect(count($frames))->toBeGreaterThan(10);           // (10 Hz and more: three jobs)
        foreach ($frames as $frame) {
            $before = [];
            for ($y = 0; $y < 30; $y++) {
                $before[$y] = $replay->rowData($y);
            }
            $replay->write("\e[?2026h" . $frame);
            $changed = 0;
            for ($y = 0; $y < 30; $y++) {
                [$chars, $styles] = $replay->rowData($y);
                foreach ($chars as $x => $c) {
                    $changed += $c !== $before[$y][0][$x] || $styles[$x] !== $before[$y][1][$x] ? 1 : 0;
                }
            }
            $printed = mb_strlen((string) preg_replace('/\e(\[[0-9;:?<>=]*[ -\/]*[@-~]|\][^\x07\e]*(\x07|\e\\\\)|[()][0-9A-Za-z]|[78=>])/', '', $frame));
            // (a gap of up to three unchanged cells between changed ones is written rather than jumped over)
            expect($frame)->not->toContain("\e[2J")->not->toContain("\e[J")
                ->and($printed)->toBeLessThanOrEqual(4 * $changed)
                ->and(strlen($frame))->toBeLessThanOrEqual(60 + 40 * $changed);
        }
        expect($replay->dump())->toBe($t->surface->dump());     // and the bytes are the screen
        $t->press('ctrl+q');
    });
});

it('runs inline with the three boxes only, as tall as they are', function () {
    dashboard(['--inline'], 100, 30, function (FakeTerminal $t) {
        expect($t->waitFor(fn() => str_contains($t->region(), 'run '), 5))->toBeTrue();
        $region = explode("\n", $t->region());
        expect($region)->toHaveCount(4)
            ->and($region[0])->toContain('╭ build')->toContain('╭ test')->toContain('╭ deploy')
            ->and($t->region())->not->toContain('events')->not->toContain('release notes')->not->toContain('deploying');
        $t->press('ctrl+q');
    });
});
