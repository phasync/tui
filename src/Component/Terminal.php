<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Pty;

/**
 * A command running in a pseudo-terminal, as a component: a shell, an editor, a build, `ssh`.
 *
 *     $shell = $this->child('shell', fn() => new Terminal($_SERVER['SHELL'] ?? 'sh'));
 *
 * The program gets the component's surface as its terminal: its size (and every resize, with
 * SIGWINCH), its keys and pastes when the component has the focus, the answers to its queries.
 * When the program exits, `$onExit` is called with its exit status and the component stays as
 * it is, showing the last output; disposing the component hangs the program up (SIGHUP, then
 * SIGKILL if it is still there after a second).
 */
final class Terminal extends Component
{
    private ?Pty $pty = null;

    /**
     * @param string|list<string>        $command a shell command line or an argument vector (see `Pty::start()`)
     * @param string|null                $cwd     working directory, default the current one
     * @param array<string,string>       $env     variables added to the inherited environment
     * @param (\Closure(int $code): void)|null $onExit called when the program has ended
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
        $this->pty = Pty::start($this->command, $this->surface, $this->cwd, $this->env);
    }

    protected function run(): void
    {
        $pty = $this->pty;
        // on dispose: hang the program up, and kill it if it does not listen
        $this->go(function () use ($pty): void {
            while ($this->sleep(3600)) {
            }
            if ($pty->isRunning()) {
                $pty->kill(1);
                if ($pty->wait(0.5) === null) {
                    $pty->kill(9);
                }
            }
        });
        $code = $pty->wait();
        if ($this->onExit !== null && $code !== null && $this->isMounted()) {
            ($this->onExit)($code);
        }
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
