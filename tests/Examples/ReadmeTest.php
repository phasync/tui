<?php

/*
 * The README's first program, as written there: right at any terminal size, and quitting ends
 * everything (its shell hung up, fiber\run() returning).
 */

use phasync\Tui\Component\Log;
use phasync\Tui\Component\Terminal;
use phasync\Tui\Component\TextInput;
use phasync\Tui\FakeTerminal;

use function fiber\run;
use function fiber\sleep;

it('runs the README\'s first program: resizes keep the text, focus moves, Ctrl-Q ends everything', function () {
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    preg_match('/```php\n(.*?)```/s', $readme, $m);
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
