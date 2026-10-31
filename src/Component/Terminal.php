<?php

namespace phasync\Tui\Component;

use fiber\Deferred;
use phasync\Tui\Component;
use phasync\Tui\Pty;

/**
 * A command running in a pseudo-terminal, as a component: a shell, an editor, a build, `ssh`.
 *
 *     $shell = $this->child('shell', fn() => new Terminal(
 *         $_SERVER['SHELL'] ?? 'sh',
 *         onExit: fn(int $code, Terminal $t) => $this->status->set("shell exited ($code), Enter restarts"),
 *     ));
 *     ...
 *     $code = fiber\await($shell->exited());     // or wait for it
 *
 * The program gets the component's surface as its terminal: its size (and every resize, with
 * SIGWINCH), its keys and pastes when the component has the focus, the answers to its queries.
 * It starts when the component has its real size (`sized()`: the render of the parent that shows
 * it), so full-screen programs that read their size once at start (vim, htop, less) start at the
 * size of their pane; a Terminal that no render shows has not started its program yet.
 *
 * When the program exits, the component stays as it is, showing the last output, and announces
 * it: `$onExit` is called with the exit status (in a coroutine of the component), the event
 * `exit` is emitted with the status and the component (`$terminal->on('exit', fn(int $code) =>
 * ...)`), and the `exited()` awaitable resolves with it. The host can then close the pane, `restart()` the
 * program or show the status. Disposing the component hangs the program up (SIGHUP, then
 * SIGKILL if it is still there after half a second); so does quitting, since detaching the
 * terminal device disposes the component tree.
 *
 * As an element: `new Terminal('htop', onExit: fn(int $code) => $s->status = "htop: $code",
 * class: 'grow border-round')` takes `key:`, `id:`, `class:` and `style:` like every widget.
 *
 * **Keys.** While it has the focus, its program gets every key: a shell needs Ctrl-D, Ctrl-C,
 * Ctrl-R, Ctrl-A/E/K/U/W, Tab, Escape and the arrows, an editor Ctrl-S, Ctrl-Q and the function
 * keys, so the plain bindings of the components around it (and their elements' `onKey:`) do
 * not take keys from it. A key the application needs while a shell has the focus is bound
 * `'first' => true` or `'always' => true` (it is then not the shell's): pick ones a shell does
 * not use, as tmux does (Ctrl-Tab, Alt-number, a prefix key such as Ctrl-B or Ctrl-Space,
 * F-keys a program does not read). The runner's Ctrl-Q (`Tui::run()`) still ends the program
 * when nothing binds it.
 */
final class Terminal extends Component
{
    private ?Pty $pty = null;
    private ?Deferred $exited = null;

    /**
     * @param string|list<string>        $command a shell command line or an argument vector (see `Pty::start()`)
     * @param string|null                $cwd     working directory, default the current one
     * @param array<string,string>       $env     variables added to the inherited environment
     * @param (\Closure(int $code, Terminal $terminal): void)|null $onExit called when the program has ended
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        private readonly string|array $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        private readonly ?\Closure $onExit = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
    }

    protected function mount(): void
    {
        $this->exited = new Deferred();
    }

    protected function sized(): void
    {
        $this->launch();
    }

    private function launch(): void
    {
        $exited = $this->exited;
        $this->pty = Pty::start($this->command, $this->surface, $this->cwd, $this->env, function (int $code, Pty $pty) use ($exited): void {
            $exited->resolve($code);
            // a program ended by restart() is not announced
            if ($pty === $this->pty && $this->isMounted()) {
                $this->go(function () use ($code): void {
                    $this->call($this->onExit, $code, $this);
                    $this->emit('exit', $code, $this);
                });
            }
        });
    }

    /** It takes the keys (they go to its program), so it is focused as itself. */
    protected function canFocus(): bool
    {
        return true;
    }

    /** Its program reads every key: the plain bindings of the components around it do not take them (see `Component::takesEveryKey()`). */
    protected function takesEveryKey(): bool
    {
        return true;
    }

    protected function unmount(): void
    {
        // disposed: hang the program up, and kill it if it does not listen
        $this->pty?->hangUp();
    }

    /**
     * Run the command again, in the same window: a program still running is hung up first (and
     * not announced as ended), the terminal is reset, and the new program starts at the window's
     * size. `exited()` then belongs to the new program. Must be called inside a coroutine (it
     * waits for the old program to end).
     *
     * @throws \LogicException when the component is not mounted
     */
    public function restart(): void
    {
        if (!$this->isMounted()) {
            throw new \LogicException('Only a mounted Terminal can restart its program');
        }
        $old = $this->pty;
        if ($old === null) {
            return;     // not started yet: it starts when it is sized
        }
        $this->pty = null;
        $old?->hangUp();
        $this->surface->write("\ec");       // RIS: a fresh terminal for the new program
        $this->exited = new Deferred();
        $this->launch();
    }

    /**
     * An awaitable that resolves with the exit status when the program ends (a killed program
     * reports 128 + the signal): `$code = fiber\await($terminal->exited())`.
     *
     * @throws \LogicException before the component is mounted
     */
    public function exited(): object
    {
        return $this->exited ?? throw new \LogicException('The Terminal has not been started');
    }

    /** The pty the program runs in (for its pid, `kill()`, `write()`), or null before it started (see `sized()`). */
    public function pty(): ?Pty
    {
        return $this->pty;
    }

    /** True while the program runs. */
    public function isRunning(): bool
    {
        return $this->pty !== null && $this->pty->isRunning();
    }

    /** The program's exit status once it has ended, else null. */
    public function exitCode(): ?int
    {
        return $this->pty?->exitCode();
    }
}
