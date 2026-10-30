<?php

/*
 * Programs living in a pane: full-screen programs start at the size of their pane and end with
 * it, and quitting ends everything that runs in the panes, also a foreground job of a shell and
 * a nested instance of the library with its own shells.
 */

use phasync\Tui\Component;
use phasync\Tui\Component\Terminal;
use phasync\Tui\FakeTerminal;
use phasync\Tui\Layout;
use phasync\Tui\Split;
use phasync\Tui\Surface;

use function fiber\run;
use function fiber\sleep;

/** A 20-column blank pane on the left, and the programs side by side on the right. */
final class Panes extends Component
{
    /** @var list<Terminal> */
    public array $terminals = [];

    /** @param list<string|list<string>> $commands */
    public function __construct(private readonly array $commands, private readonly array $env = [])
    {
    }

    protected function render(): ?Layout
    {
        $split = Split::horizontal()->add(new Surface(), 20);
        foreach ($this->commands as $i => $command) {
            $this->terminals[$i] = $this->child("t$i", fn() => new Terminal($command, cwd: sys_get_temp_dir(), env: $this->env));
            $split->add($this->terminals[$i]->surface);
        }
        return $split;
    }
}

/** True while any process is left in the session $sid. */
function paneSessionAlive(int $sid): bool
{
    return trim((string) shell_exec('ps -o pid= -s ' . $sid . ' 2>/dev/null')) !== '';
}

/** Run $commands in panes of an 80x20 terminal, give them to $test, then quit; returns how long quitting took. */
function inPanes(array $commands, Closure $test, array $env = []): float
{
    $home = sys_get_temp_dir() . '/tui-panes-' . getmypid();
    @mkdir($home);
    $env += ['HOME' => $home, 'XDG_CONFIG_HOME' => $home, 'LESSHISTFILE' => '-', 'PS1' => '$ ', 'ENV' => ''];
    $sessions = [];
    $quit = 0.0;
    run(function () use ($commands, $test, $env, &$sessions, &$quit) {
        $app = (new Panes($commands, $env))->start($t = new FakeTerminal(80, 20));
        try {
            $test($t, $app);
        } finally {
            $sessions = array_map(fn(Terminal $term) => $term->pty()?->pid(), $app->terminals);
            $quit = microtime(true);
            $t->detach();                       // quitting
        }
    });
    $quit = microtime(true) - $quit;            // fiber\run() has returned: nothing is left running
    foreach ($sessions as $pid) {
        expect($pid)->not->toBeNull()->and(paneSessionAlive($pid))->toBeFalse();
    }
    exec('rm -rf ' . escapeshellarg($home));
    return $quit;
}

/** The text of row $y of the right-hand pane of an 80x20 terminal with one program. */
function paneRow(FakeTerminal $t, int $y): string
{
    $t->refresh();
    return rtrim(mb_substr(str_pad($t->surface->line($y), 80), 20));
}

it('starts vim at the size of its pane and ends it with the pane', function () {
    $file = sys_get_temp_dir() . '/tui-vim-' . getmypid() . '.txt';
    file_put_contents($file, str_repeat('a', 70) . "\nsecond\n");
    $took = inPanes([['vim', '-u', 'NONE', '-i', 'NONE', '-N', '-n', $file]], function (FakeTerminal $t) {
        expect($t->waitFor(fn() => str_contains(paneRow($t, 19), '2L'), 10))->toBeTrue()
            ->and(paneRow($t, 0))->toBe(str_repeat('a', 60))       // 60 columns wide
            ->and(paneRow($t, 1))->toBe(str_repeat('a', 10))
            ->and(paneRow($t, 2))->toBe('second')
            ->and(paneRow($t, 18))->toBe('~');                     // 20 rows high
    });
    unlink($file);
    expect($took)->toBeLessThan(2.0);
})->skip(!is_executable('/usr/bin/vim'), 'vim is not installed');

it('starts htop at the size of its pane and ends it with the pane', function () {
    $took = inPanes([['htop']], function (FakeTerminal $t) {
        expect($t->waitFor(fn() => str_starts_with(paneRow($t, 19), 'F1'), 10))->toBeTrue();
    });
    expect($took)->toBeLessThan(2.0);
})->skip(trim((string) shell_exec('command -v htop')) === '', 'htop is not installed');

it('starts less at the size of its pane and ends it with the pane', function () {
    $file = sys_get_temp_dir() . '/tui-less-' . getmypid() . '.txt';
    file_put_contents($file, implode("\n", array_map(fn($i) => "row $i " . str_repeat('-', 70), range(1, 100))) . "\n");
    $took = inPanes([['less', '-S', $file]], function (FakeTerminal $t) use ($file) {
        expect($t->waitFor(fn() => str_contains(paneRow($t, 19), basename($file)), 10))->toBeTrue()
            ->and(paneRow($t, 18))->toStartWith('row 19 ')
            ->and(mb_strlen(paneRow($t, 0)))->toBe(60);
    });
    unlink($file);
    expect($took)->toBeLessThan(2.0);
})->skip(trim((string) shell_exec('command -v less')) === '', 'less is not installed');

it('quits while the shells run a foreground program, with the command as a string or a vector', function () {
    $took = inPanes(['sh', ['sh']], function (FakeTerminal $t, Panes $app) {
        foreach ($app->terminals as $term) {
            expect($t->waitFor(fn() => str_contains($term->surface->text(), '$'), 5))->toBeTrue();
            $term->surface->input->write("sleep 1000\r");
        }
        foreach ($app->terminals as $term) {
            expect($t->waitFor(fn() => str_contains((string) shell_exec('ps -o comm= -s ' . $term->pty()->pid()), 'sleep'), 5))->toBeTrue();
        }
    });
    expect($took)->toBeLessThan(2.0);
})->skip(PHP_OS_FAMILY !== 'Linux', 'reads the sessions with ps -s');

it('quits while a nested split-shells runs a foreground program in its shells', function () {
    $marker = (string) (100000 + getmypid() % 100000);      // sleep's argument tells its processes apart
    $took = inPanes([['php', __DIR__ . '/../../examples/split-shells.php']], function (FakeTerminal $t, Panes $app) use ($marker) {
        $app->terminals[0]->focus();
        expect($t->waitFor(fn() => str_contains($t->text(), 'shell 2'), 10))->toBeTrue();
        sleep(0.3);
        $t->type("sleep $marker\r");
        expect($t->waitFor(fn() => trim((string) shell_exec("pgrep -f '^sleep $marker\$'")) !== '', 5))->toBeTrue();
    }, ['SHELL' => '/bin/sh']);
    expect($took)->toBeLessThan(2.0);
    $deadline = microtime(true) + 1.0;
    while (trim((string) shell_exec("pgrep -f '^sleep $marker\$'")) !== '' && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect(trim((string) shell_exec("pgrep -f '^sleep $marker\$'")))->toBe('');
})->skip(PHP_OS_FAMILY !== 'Linux', 'reads the sessions with ps -s');
