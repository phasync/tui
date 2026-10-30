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
 *
 * When the program exits, the component stays as it is, showing the last output, and announces
 * it: `$onExit` is called with the exit status (in a coroutine of the component), and the
 * `exited()` awaitable resolves with it. The host can then close the pane, `restart()` the
 * program or show the status. Disposing the component hangs the program up (SIGHUP, then
 * SIGKILL if it is still there after half a second); so does quitting, since detaching the
 * terminal device disposes the component tree.
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
     */
    public function __construct(
        private readonly string|array $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        private readonly ?\Closure $onExit = null,
    ) {
    }

    protected function mount(): void
    {
        $this->launch();
    }

    private function launch(): void
    {
        $exited = $this->exited = new Deferred();
        $this->pty = Pty::start($this->command, $this->surface, $this->cwd, $this->env, function (int $code, Pty $pty) use ($exited): void {
            $exited->settle(true, $code);
            // a program ended by restart() is not announced
            if ($pty === $this->pty && $this->onExit !== null && $this->isMounted()) {
                $this->go(fn() => ($this->onExit)($code, $this));
            }
        });
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
        $this->pty = null;
        $old?->hangUp();
        $this->surface->write("\ec");       // RIS: a fresh terminal for the new program
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

    /** The pty the program runs in (for its pid, `kill()`, `write()`), or null before mounting. */
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
