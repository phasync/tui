<?php

/*
 * TODO-0.0.3-beta2 item 2 (SPEC 9.5): every screen of docs/cookbook.md is a program of under
 * forty lines that runs, under a FakeTerminal at 60x20 and at 160x50, showing what it should
 * and answering its keys, with no exception.
 */

use phasync\Tui\Element;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Tui;

/** @return array<int, array{string, string}> the cookbook's programs by number: [title, code] */
function cookbookRecipes(): array
{
    $doc = (string) file_get_contents(__DIR__ . '/../../docs/cookbook.md');
    preg_match_all('/^## (\d+)\. (.+?)\n.*?```php\n(.*?)```/ms', $doc, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $n, $title, $code]) {
        $out[(int) $n] = [$title, $code];
    }
    return $out;
}

/** The runner the recipes call instead of Tui::run()/Tui::inline(): a FakeTerminal given to the check. */
function cookbookRun(bool $inline, Element|Closure $root, array $keys = []): void
{
    [$cols, $rows, $check] = $GLOBALS['cookbook'];
    \fiber\run(function () use ($inline, $root, $keys, $cols, $rows, $check) {
        $t = Tui::headless($root, $cols, $rows, inline: $inline, keys: $keys);
        $t->run(function () use ($t, $check) {
            $check($t);
            $t->press('ctrl+q');
        });
    });
}

/** Run recipe $n at $cols x $rows with $check($terminal). */
function cookbook(int $n, int $cols, int $rows, Closure $check): void
{
    $code = cookbookRecipes()[$n][1];
    $code = str_replace(['Tui::run(', 'Tui::inline('], ['cookbookRun(false, ', 'cookbookRun(true, '], $code, $count);
    expect($count)->toBe(1);
    $GLOBALS['cookbook'] = [$cols, $rows, $check];
    $cwd = getcwd();
    chdir(__DIR__ . '/../..');
    try {
        eval($code);
    } finally {
        chdir($cwd);
    }
}

it('has ten screens, each under forty lines', function () {
    $recipes = cookbookRecipes();
    expect(array_keys($recipes))->toBe(range(1, 10));
    foreach ($recipes as $n => [$title, $code]) {
        expect(substr_count($code, "\n"))->toBeLessThan(40, "recipe $n ($title)");
        expect(preg_match('/^\s*(final\s+)?class\s/m', $code))->toBe(0, "recipe $n has no class");
    }
});

$sizes = [[60, 20], [160, 50]];

it('runs 1, the progress runner, inline', function (int $cols, int $rows) {
    cookbook(1, $cols, $rows, function (FakeTerminal $t) {
        expect($t->waitFor(fn() => str_contains($t->region(), '4 of 4 done'), 5))->toBeTrue()
            ->and($t->region())->toContain('compile')->toContain('upload')->toContain('100%');
    });
})->with($sizes);

it('runs 2, the log tail with a filter', function (int $cols, int $rows) {
    cookbook(2, $cols, $rows, function (FakeTerminal $t) {
        expect($t->waitFor('served', 5))->toBeTrue()->and($t->text())->toContain('filter')->toContain('lines)');
        $t->type('error');
        $t->settle();
        \fiber\sleep(0.2);
        $t->settle();
        $lines = array_filter(screenRows($t), fn($l) => str_contains($l, 'served'));
        expect($lines)->not->toBeEmpty();
        foreach ($lines as $line) {
            expect($line)->toContain('error');
        }
    });
})->with($sizes);

it('runs 3, the form with validation in a dialog', function (int $cols, int $rows) {
    cookbook(3, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('Contacts')->toContain('none yet')->toContain('new contact');
        $t->press('ctrl+n');
        $t->settle();
        expect($t->text())->toContain('New contact');
        $t->press('enter');
        $t->settle();
        expect($t->text())->toContain('A name, please.');
        $t->type("Ada\t");
        $t->type("ada.example\n");
        $t->settle();
        expect($t->text())->toContain('An email address has an @.');
        $t->press('end');           // (the field in error has the focus)
        $t->type("@x.org\n");
        $t->settle();
        expect($t->text())->toContain('Ada')->toContain('ada.example@x.org')->not->toContain('New contact');
    });
})->with($sizes);

it('runs 4, the file list with a preview', function (int $cols, int $rows) {
    cookbook(4, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        $first = array_values(array_filter(scandir('.'), 'is_file'))[0];
        expect($t->text())->toContain('files')->toContain('README.md')->toContain("╭ $first");
        $t->press('down');
        $t->settle();
        $second = array_values(array_filter(scandir('.'), 'is_file'))[1];
        expect($t->text())->toContain("╭ $second");
    });
})->with($sizes);

it('runs 5, the tabbed shell host', function (int $cols, int $rows) {
    cookbook(5, $cols, $rows, function (FakeTerminal $t) {
        expect($t->waitFor('another shell', 5))->toBeTrue()->and($t->text())->toContain('log');
        $t->type("echo cookbook-\$((6*7))\n");
        expect($t->waitFor('cookbook-42', 5))->toBeTrue();
        $t->press('alt+3');
        expect($t->waitFor('started at', 5))->toBeTrue()->and($t->waitFor('tab log', 5))->toBeTrue();
    });
})->with($sizes);

it('runs 6, the data table with a search box', function (int $cols, int $rows) {
    cookbook(6, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('PID')->toContain('nginx')->toContain('8 processes');
        $t->type('p');
        $t->settle();
        expect($t->text())->toContain('2 processes')->toContain('php-fpm')->toContain('postgres')->not->toContain('nginx');
        $t->press('backspace');
        $t->settle();
        expect($t->text())->toContain('8 processes');
    });
})->with($sizes);

it('runs 7, the chat pane with a growing editor', function (int $cols, int $rows) {
    cookbook(7, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('Hello!')->toContain('message');
        $t->type("hi there\nsecond line");
        $t->settle();
        expect($t->text())->toContain('second line');
        $t->press('alt+enter');
        $t->settle();
        expect($t->text())->toContain('you hi there')->toContain('20 characters, thank you.');
    });
})->with($sizes);

it('runs 8, the settings with checkboxes and a select', function (int $cols, int $rows) {
    cookbook(8, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('Settings')->toContain('Notifications')->toContain('on: notifications, updates · theme dark')
            ->toContain('editing notifications');
        $t->press('tab');
        $t->settle();
        expect($t->text())->toContain('editing sounds');
        $t->press('space');
        $t->settle();
        expect($t->text())->toContain('on: notifications, sounds, updates');
    });
})->with($sizes);

it('runs 9, the live chart from a coroutine', function (int $cols, int $rows) {
    cookbook(9, $cols, $rows, function (FakeTerminal $t) {
        expect($t->waitFor(fn() => preg_match('/\b(1\d|[2-9]\d) samples/', $t->text()) === 1, 5))->toBeTrue()
            ->and(preg_match('/[▁▂▃▄▅▆▇█]/u', $t->text()))->toBe(1)->and($t->text())->toContain('signal');
    });
})->with($sizes);

it('runs 10, the Markdown viewer with a table of contents', function (int $cols, int $rows) {
    cookbook(10, $cols, $rows, function (FakeTerminal $t) {
        $t->settle();
        expect($t->text())->toContain('contents')->toContain('phasync/tui');
        $before = screenRows($t);
        $t->press('down', 'down');
        $t->settle();
        expect(screenRows($t))->not->toBe($before);
    });
})->with($sizes);
