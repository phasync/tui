<?php

/*
 * The README's programs, as written there: the one-file program first, then the one with a class,
 * right at any terminal size, and quitting ends everything (its shell hung up, fiber\run()
 * returning).
 */

use phasync\Tui\Component\Log;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\TextInput;
use phasync\Tui\Color;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Style;
use phasync\Tui\Tui;

use function fiber\run;
use function fiber\sleep;

it('runs the README\'s first code block, the one-file program, on an in-memory terminal', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n(.*?)```/s', $readme, $m);
    expect($m[1])->toContain('Tui::inline(fn() => new Column([')->toContain('fiber\go(');
    // as written, but faster, and headless: the screen is read, then Ctrl-Q
    $code = str_replace(['fiber\sleep(0.05)', 'Tui::inline(', "]));\n});"], ['fiber\sleep(0.002)', '$t = Tui::headless(', "]), 60, 8, inline: true);\n    readmeWorking(\$t);\n});"], $m[1], $count);
    expect($count)->toBe(3);
    eval($code);
});

it('runs the README\'s styled screen: utility classes and markup, side by side when wide, stacked when narrow', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n((?:(?!```).)*lg:flex-row.*?)```/s', $readme, $m);
    $code = str_replace('Tui::run(', '$GLOBALS[\'readmeStyled\'] = (', $m[1], $count);
    expect($count)->toBe(1);
    eval($code);
    run(function () {
        $t = Tui::headless($GLOBALS['readmeStyled'], 110, 12);
        $t->settle();
        $lines = explode("\n", $t->text());
        $screen = $t->root();
        expect($lines[1])->toStartWith('  deploy v1.4')->toEndWith('● online')
            ->and(mb_strlen($lines[1]))->toBe(108)                  // p-1 outside, px-1 inside the bar
            ->and($lines[2])->toContain(' jobs ')->toContain(' errors ')
            ->and(substr_count($lines[2], '╭'))->toBe(2)
            ->and($screen->cell(2, 1)->style->has(Style::BOLD))->toBeTrue()
            ->and($screen->cell(1, 1)->style->bg)->toBe(Color::parse('slate-800'))
            ->and($screen->cell(0, 1)->style->bg)->toBe(Color::DEFAULT);
        $t->resize(50, 12);
        $t->settle();
        $lines = explode("\n", $t->text());
        expect(substr_count($lines[2], '╭'))->toBe(1)->and($t->text())->toContain('no errors')
            ->and(substr_count($t->text(), '╭'))->toBe(2);
        $t->press('ctrl+q');
        expect($t->isAttached())->toBeFalse();
    });
});

function readmeWorking(FakeTerminal $t): void
{
    expect($t->waitFor('Working… 100%', 5))->toBeTrue();
    $t->settle();
    expect(explode("\n", $t->region()))->toBe(['Working… 100%', str_repeat('█', 55) . ' 100%']);
    $t->press('ctrl+q');
    expect($t->isAttached())->toBeFalse();
}

it('runs the README\'s first program with a class: resizes keep the text, focus moves, Ctrl-Q ends everything', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n((?:(?!```).)*final class App.*?)```/s', $readme, $m);
    $code = preg_replace('/^fiber\\\\run\(.*$/m', '', $m[1]);
    expect($m[1])->toContain('fiber\run(fn() => (new App())->start(new Tty()));');
    eval($code);

    $start = microtime(true);
    run(function () use (&$app) {
        $app = (new App())->start($t = new FakeTerminal(60, 8));
        $log = null;
        $shell = null;
        foreach ((new ReflectionProperty(phasync\Tui\Component::class, 'children'))->getValue($app) as $child) {
            $log = $child instanceof Log ? $child : $log;
            $shell = $child instanceof Terminal ? $child : $shell;
        }
        $t->type("a fairly long message that will need wrapping\n");
        expect($t->text())->toContain('you said: a fairly')
            ->and($log->lines())->toBe(['you said: a fairly long message that will need wrapping']);
        $t->resize(30, 8);                 // the log's half is 14 columns now
        $text = $t->text();
        expect($text)->toContain('you said: a')->toContain('wrapping')
            ->and(preg_match_all('/\bneed\b/', $text))->toBe(1);
        $t->resize(90, 8);
        expect($t->text())->toContain('you said: a fairly long message');
        // Alt-Right goes to the shell, Alt-Left back to the input
        $t->press('alt+right');
        expect($shell->hasFocus())->toBeTrue();
        $t->type("echo from-shell\n");
        expect($t->waitFor('from-shell'))->toBeTrue();
        $t->press('alt+left');
        expect($shell->hasFocus())->toBeFalse();
        $t->press('ctrl+q');
        expect($t->isAttached())->toBeFalse()->and($shell->isRunning())->toBeFalse();
    });
    expect(microtime(true) - $start)->toBeLessThan(5.0)->and($app->isMounted())->toBeFalse();
});

it('runs the README\'s screen from elements: the form, its validation, the dropdown, the dialog, the state shown', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n((?:(?!```).)*final class Profile.*?)```/s', $readme, $m);
    expect($m[1])->toContain('fiber\run(fn() => $app->start(new Tty()));');
    eval(preg_replace('/^fiber\\\\run\(.*$/m', '', $m[1]));
    run(function () use (&$app) {
        $app->start($t = new FakeTerminal(60, 12));
        $t->run(readmeProfile(...));
    });
    expect($app->isMounted())->toBeFalse();
});

function readmeProfile(FakeTerminal $t): void
{
    $t->settle();
    expect($t->text())->toContain('Profile')->toContain('not saved yet')->toContain('ctrl+q quit')
        ->toContain('Developer')->toContain('[ ] Send me the newsletter')->toContain('[ Save ]  [ Quit ]');
    $t->press('enter');                         // no name yet
    $t->settle();
    expect($t->text())->toContain('Your name, please.')->not->toContain('Save the profile?');
    $t->type('Ada');
    $t->press('tab', 'down');                   // the role: its list opens
    $t->settle();
    expect($t->text())->toContain('Operations');
    $t->press('down', 'enter', 'tab', 'space', 'tab', 'enter');     // Operations, the newsletter, Save
    expect($t->waitFor('Save the profile?', 2))->toBeTrue();
    $t->press('enter');
    expect($t->waitFor('saved: Ada, ops, newsletter', 2))->toBeTrue()->and($t->text())->not->toContain('Your name, please.');
    $t->press('ctrl+q');
    expect($t->isAttached())->toBeFalse();
}

/** The README's php block that holds $needle, with its `fiber\run(...)` or `Tui::run(...)` line taken out. */
function readmeBlock(string $needle): string
{
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n((?:(?!```).)*' . preg_quote($needle, '/') . '.*?)```/s', $readme, $m);
    expect($m)->not->toBeEmpty();
    return 'use phasync\Tui\{Component, Element, TerminalDevice, Tty, Tui};
        use phasync\Tui\Component\{Markdown, ProgressBar, Tabs, Terminal, Tree};
        use phasync\Tui\Element\{Box, Row, Text};
        ' . preg_replace('/^(\$tty = |fiber\\\\run\(|Tui::run\().*$/m', '', $m[1]);
}

it('runs the README\'s Markdown pane: the answer arrives in chunks and renders whole', function () {
    eval(readmeBlock('final class Answer'));
    run(function () {
        $app = (new Answer())->start($t = new FakeTerminal(50, 8));
        $t->run(function (FakeTerminal $t) {
            expect($t->waitFor('(https://phasync.dev).', 3))->toBeTrue()
                ->and($t->text())->toContain('Hello')->toContain('This arrives in chunks: code, a link')
                ->not->toContain('*')->not->toContain('`')
                ->and($t->dump())->toContain('[italic]arrives[/]');
        });
    });
});

it('runs the README\'s inline example: lines printed above a progress bar that stays', function () {
    eval(readmeBlock('final class Copy'));
    run(function () {
        $t = FakeTerminal::inline(40, 10);
        $t->surface->write("$ php copy.php\r\n");
        (new Copy())->start($t);
        $t->run(function (FakeTerminal $t) {
            expect($t->waitFor('copied d.txt', 3))->toBeTrue();
            $t->wait();                                 // until the program ends
        });
        expect($t->isAttached())->toBeFalse();
        $lines = explode("\n", rtrim($t->text()));
        expect(\array_slice($lines, 0, 5))->toBe(['$ php copy.php', 'copied a.txt', 'copied b.txt', 'copied c.txt', 'copied d.txt'])
            ->and($lines[5])->toStartWith('copying')->toEndWith('100%')
            ->and($t->surface->cursor()->y)->toBe(6);
    });
});

it('runs the README\'s tabs: the shell keeps running in a hidden tab, Ctrl-Q quits from inside it', function () {
    eval(readmeBlock('final class Workspace'));
    run(function () {
        $t = Tui::headless($app = new Workspace(), 60, 10);
        $t->run(function (FakeTerminal $t) use ($app) {
            $t->settle();
            expect($t->text())->toContain('Shell')->toContain('Notes');
            $t->press('enter');                         // into the shell
            $t->type("echo from-tab\n");
            expect($t->waitFor('from-tab', 3))->toBeTrue();
            $t->press('ctrl+tab');                      // from inside the shell
            $t->settle();
            expect($t->text())->toContain('The shell keeps running.')->not->toContain('from-tab');
            $t->press('ctrl+tab');
            $t->settle();
            expect($t->text())->toContain('from-tab');  // the same shell, its screen kept
            $t->press('ctrl+q');                        // the shell has the focus: the first binding quits
            expect($t->isAttached())->toBeFalse()->and($app->isMounted())->toBeFalse();
        });
    });
});

it('runs the README\'s tree of 100,000 nodes: it opens fast, moves to the end, closes a section', function () {
    eval(readmeBlock('final class Outline'));
    run(function () {
        $start = microtime(true);
        $t = Tui::headless($app = new Outline(), 50, 12);
        $t->run(function (FakeTerminal $t) use ($start) {
            $t->settle();
            expect($t->text())->toContain('outline')->toContain('section 1')->toContain('item 1.1');
            $t->press('end');
            $t->settle();
            expect($t->text())->toContain('item 1000.99');
            $t->press('enter');
            $t->settle();
            expect($t->text())->toContain('section 1000/item 1000.99');     // the key, in the title
            $t->press('home', 'left');
            $t->settle();
            expect($t->text())->not->toContain('item 1.1')->toContain('section 2')
                ->and(microtime(true) - $start)->toBeLessThan(10.0);
            $t->press('ctrl+q');
        });
        expect($app->isMounted())->toBeFalse();
    });
});
