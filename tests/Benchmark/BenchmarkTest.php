<?php

/*
 * The acceptance tests of SPEC.md: a demanding program in a window, and the library hosting
 * itself. Both run real programs in ptys under a root attached to a FakeTerminal.
 */

use phasync\Tui\Component\Terminal;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Mouse;
use phasync\Tui\Split;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** The text of a rectangle of the terminal's screen, rows joined with "\n", right-trimmed. */
function region(FakeTerminal $t, int $x, int $y, int $w, int $h): string
{
    $t->refresh();
    $out = [];
    for ($r = $y; $r < $y + $h; $r++) {
        $line = '';
        for ($c = $x; $c < $x + $w; $c++) {
            $line .= $t->surface->cell($c, $r)->char;   // '' for the second half of a wide character
        }
        $out[] = rtrim($line);
    }
    return implode("\n", $out);
}

it('hosts itself: split-shells inside split-shells inside split-shells', function () {
    run(function () {
        $env = ['SHELL' => '/bin/sh', 'PS1' => '$ ', 'ENV' => ''];
        $app = (new Terminal(['php', __DIR__ . '/../../examples/split-shells.php'], env: $env))->start();
        $t = new FakeTerminal(100, 30);
        $t->attach($app->surface);
        try {
            $prompts = fn(int $n) => fn(FakeTerminal $t) => substr_count($t->text(), '$ ') + substr_count($t->text(), "$\n") >= $n;
            expect($t->waitFor(fn($t) => substr_count($t->text(), "\$") >= 2, 10))->toBeTrue();
            $t->type('php ' . __DIR__ . "/../../examples/split-shells.php\n");
            expect($t->waitFor(fn($t) => substr_count($t->text(), 'shell 2') >= 2 && substr_count($t->text(), '$') >= 3, 10))->toBeTrue();
            $t->type('php ' . __DIR__ . "/../../examples/split-shells.php\n");
            expect($t->waitFor(fn($t) => substr_count($t->text(), 'shell 2') >= 3 && substr_count($t->text(), '$') >= 4, 10))->toBeTrue();
            sleep(0.3);

            // the innermost shell (9x21: each level is two boxed panes and a status bar) gets the keys
            $inner = fn() => region($t, 3, 3, 9, 21);
            $t->type("stty size\n");
            expect($t->waitFor(fn() => str_contains($inner(), '21 9'), 5))->toBeTrue();
            expect(seen($t->surface))->toBe(seen($app->surface));

            // resizing goes down every level to the innermost pty
            $t->resize(120, 34);
            sleep(1.0);
            $t->type("clear; stty size\n");
            $inner = fn() => region($t, 3, 3, 12, 25);
            expect($t->waitFor(fn() => str_contains($inner(), '25 12'), 5))->toBeTrue();

            // bracketed paste reaches the innermost shell
            $t->paste("echo pasted\n");
            expect($t->waitFor(fn() => str_contains($inner(), "pasted\n"), 5))->toBeTrue();

            // scrollback with the wheel, handled by the innermost host
            $t->type("clear; seq 1 60\n");
            $ok = $t->waitFor(fn() => str_contains($inner(), "60\n\$"), 5);
            expect($ok)->toBeTrue($inner());
            for ($i = 0; $i < 5; $i++) {
                $t->scroll(5, 10, -1);
            }
            expect($t->waitFor(fn() => !str_contains($inner(), '60') && str_contains($inner(), '30'), 5))->toBeTrue();

            // select and copy in the innermost window: OSC 52 travels up through every level
            $t->drag(3, 3, 4, 3);
            expect($t->waitFor(fn(FakeTerminal $t) => $t->clipboardText() !== '', 5))->toBeTrue()
                ->and($t->clipboardText())->toMatch('/^\d\d$/');

            // focus at the innermost level: Ctrl-B Ctrl-B reaches the middle, twice more the innermost
            $t->press('ctrl+b', 'ctrl+b', 'ctrl+b', 'ctrl+b', 'tab');
            sleep(0.3);
            $t->type("echo r3\n");
            expect($t->waitFor(fn() => str_contains(region($t, 15, 3, 8, 21), 'r3'), 5))->toBeTrue();
            expect(seen($t->surface))->toBe(seen($app->surface));
        } finally {
            $app->dispose();
            $t->detach();
        }
    });
})->skip(fn() => trim((string) shell_exec('command -v php')) === '', 'php not on PATH');

it('hosts Claude Code in a pane next to a shell', function () {
    run(function () {
        $root = new Surface();
        $claude = (new Terminal(['claude']))->start();
        $shell = (new Terminal('/bin/sh', env: ['PS1' => '$ ', 'ENV' => '']))->start();
        $root->setLayout(Split::horizontal(separators: true)->add($claude->surface, '70%')->add($shell->surface));
        $t = new FakeTerminal(120, 30);
        $t->attach($root);
        try {
            // its first screen (the trust prompt for an unknown folder, or its prompt box)
            $s = $claude->surface;
            expect($t->waitFor(fn() => $s->mode(2004) && trim($s->text()) !== '', 20))->toBeTrue();
            sleep(0.5);
            // it negotiated what a modern terminal offers, and the Surface answered as one
            expect($s->mode(2004))->toBeTrue()->and($s->mode(1004))->toBeTrue()
                ->and($s->keyboardFlags() > 0 || $s->modifyOtherKeys() > 0)->toBeTrue();
            expect(seen($t->surface))->toBe(seen($root));
            if (str_contains($t->text(), 'trust this folder')) {
                // arrow keys move the selection; "No, exit" ends it without touching any settings
                $t->press('down');
                expect($t->waitFor(fn() => preg_match('/❯ \d?\.? ?Yes/u', $t->text()) === 1, 5))->toBeTrue();
                $t->press('up');
                expect($t->waitFor(fn() => preg_match('/❯ \d?\.? ?No/u', $t->text()) === 1, 5))->toBeTrue();
                $t->press('enter');
                expect($claude->pty()->wait(10))->not->toBeNull();
            }
            $shell->focus();
            $t->type("echo shell-ok\n");
            expect($t->waitFor('shell-ok'))->toBeTrue();
        } finally {
            $claude->dispose();
            $shell->dispose();
            $t->detach();
        }
    });
})->skip(fn() => trim((string) shell_exec('command -v claude')) === '', 'claude is not installed');

it('hosts Claude Code\'s first-run screens: keys, redraws, colours and resizes, in a home of its own', function () {
    // a fresh home, so Claude Code starts as on a new machine and writes nothing of the user's
    $home = sys_get_temp_dir() . '/phasync-tui-claude-' . getmypid() . '-' . mt_rand();
    mkdir($home, 0700);
    try {
        run(function () use ($home) {
            $env = ['HOME' => $home, 'CLAUDE_CONFIG_DIR' => "$home/.claude", 'XDG_CONFIG_HOME' => "$home/.config", 'BROWSER' => 'true'];
            $claude = (new Terminal([trim((string) shell_exec('command -v claude'))], cwd: $home, env: $env))->start();
            $shell = (new Terminal('/bin/sh', env: ['PS1' => '$ ', 'ENV' => '']))->start();
            $root = new Surface();
            $root->setLayout(Split::horizontal(separators: true)->add($claude->surface, '75%')->add($shell->surface));
            $t = new FakeTerminal(120, 32);
            $t->attach($root);
            $s = $claude->surface;
            $selected = fn() => preg_match('/❯\s+(?:✔\s+)?(\S.*?)\s*$/mu', $s->text(), $m) ? $m[1] : null;
            try {
                // the theme picker: a menu with a live, syntax-highlighted preview
                expect($t->waitFor(fn() => str_contains($s->text(), 'Choose the text style') && str_contains($s->text(), 'Syntax theme'), 20))->toBeTrue();
                $first = $selected();
                $theme = fn() => preg_match('/Syntax theme: (.+?) \(/u', $s->text(), $m) ? $m[1] : null;
                $before = $theme();
                expect($first)->not->toBeNull()
                    ->and($s->dump())->toMatch('/fg=#[0-9a-f]{6}/');          // truecolor, as COLORTERM promises
                // arrow keys (kitty keyboard protocol or modifyOtherKeys, as it negotiated) move the
                // selection, and it redraws the preview for the theme under the cursor
                $t->press('down');
                expect($t->waitFor(fn() => $selected() !== $first && $theme() !== $before, 5))->toBeTrue();
                $t->press('up');
                expect($t->waitFor(fn() => $selected() === $first && $theme() === $before, 5))->toBeTrue();
                $t->refresh();
                expect(seen($t->surface))->toBe(seen($root));   // drawn now, then compared (no output in between)
                // a resize reaches it (SIGWINCH) and it redraws its rules for the new width
                $t->resize(100, 32);
                $rule = fn() => preg_match('/^ (╌+)$/mu', $s->text(), $m) ? mb_strlen($m[1]) : 0;
                expect($t->waitFor(fn() => $s->cols() === 74 && $rule() > 0 && $rule() <= 74, 5))->toBeTrue();
                // Enter takes it to the next screen
                $t->press('enter');
                expect($t->waitFor(fn() => str_contains($s->text(), 'Select login method'), 10))->toBeTrue();
                $t->press('down');
                expect($t->waitFor(fn() => str_starts_with((string) $selected(), '2.'), 5))->toBeTrue();
                $t->refresh();
                expect(seen($t->surface))->toBe(seen($root));   // drawn now, then compared (no output in between)
                // the shell next to it still works
                $shell->focus();
                $t->type("echo shell-ok\n");
                expect($t->waitFor('shell-ok'))->toBeTrue();
            } finally {
                $claude->dispose();     // hung up: its first run never finished, nothing was saved
                $shell->dispose();
                $t->detach();
            }
        });
    } finally {
        exec('rm -rf ' . escapeshellarg($home));
    }
})->skip(fn() => trim((string) shell_exec('command -v claude')) === '', 'claude is not installed');
