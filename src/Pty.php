<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Signal;

use function fiber\go;
use function fiber\readable;
use function fiber\sleep;
use function fiber\writable;

/**
 * A program running in a pseudo-terminal, producing into a Surface.
 *
 *     fiber\run(function () {
 *         $surface = new Surface(80, 24);
 *         $pty = Pty::start(['ls', '--color=auto'], $surface);
 *         $code = $pty->wait();
 *         echo $surface->text();
 *     });
 *
 * The program sees a real terminal of the surface's size: `TERM=xterm-256color`,
 * `COLORTERM=truecolor`, a controlling terminal (so Ctrl-C, job control and SIGWINCH work) and
 * the surface's size from the start. Its output is written into the surface as it arrives, and
 * everything written to the surface's `input` (keys from `Surface::sendKey()`, pastes, the
 * terminal's replies to queries) goes to the program. Resizing the surface resizes the
 * terminal, which sends SIGWINCH to the program.
 *
 * Everything runs in coroutines (`fiber\go()`), so `start()` and the other waiting methods must
 * be called inside `fiber\run()`; nothing blocks other coroutines.
 */
final class Pty
{
    /** @var resource */
    private $process;
    /** @var resource */
    private $master;
    private int $pid;
    private ?string $slave = null;
    private ?int $exitCode = null;
    private bool $ended = false;
    private string $outBuffer = '';
    private bool $writing = false;
    /** @var array{int,int}|null */
    private ?array $wantedSize = null;
    private bool $resizing = false;
    private Signal $done;
    private \Closure $offResize;

    private function __construct()
    {
        $this->done = new Signal();
    }

    /**
     * Start $command in a pty attached to $surface.
     *
     * @param string|list<string> $command a shell command line (run with /bin/sh -c) or an
     *                                     argument vector (run directly, no shell)
     * @param string|null         $cwd     working directory, default the current one
     * @param array<string,string> $env    variables added to the inherited environment
     *
     * @throws \RuntimeException when the process cannot be started
     */
    public static function start(string|array $command, Surface $surface, ?string $cwd = null, array $env = []): Pty
    {
        $argv = \is_string($command) ? ['/bin/sh', '-c', $command] : array_values($command);
        if ($argv === []) {
            throw new \InvalidArgumentException('Empty command');
        }
        $cols = $surface->cols();
        $rows = $surface->rows();
        // set the size before the program starts, then exec it in place
        $wrapper = ['/bin/sh', '-c', "stty cols $cols rows $rows 2>/dev/null; exec \"\$@\"", 'sh', ...$argv];
        if (self::hasSetsid()) {
            // a new session with the pty as controlling terminal
            $wrapper = ['setsid', '-c', ...$wrapper];
        }
        $inherited = getenv();
        unset($inherited['COLUMNS'], $inherited['LINES']);
        $environment = $env + ['TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor'] + $inherited;

        $process = @proc_open($wrapper, [['pty'], ['pty'], ['pty']], $pipes, $cwd, $environment);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start ' . implode(' ', $argv) . ': ' . (error_get_last()['message'] ?? 'proc_open failed'));
        }
        $pty = new Pty();
        $pty->process = $process;
        $pty->master = $pipes[1];
        fclose($pipes[0]);
        fclose($pipes[2]);
        stream_set_blocking($pty->master, false);
        stream_set_read_buffer($pty->master, 0);
        stream_set_write_buffer($pty->master, 0);
        $pty->pid = proc_get_status($process)['pid'];

        $surface->input->pipe(fn(string $bytes) => $pty->write($bytes));
        $pty->offResize = $surface->on('resize', fn(array $size) => $pty->resize($size[0], $size[1]));
        go(fn() => $pty->pump($surface));
        return $pty;
    }

    private static function hasSetsid(): bool
    {
        static $has = null;
        return $has ??= \PHP_OS_FAMILY === 'Linux' && trim((string) shell_exec('command -v setsid 2>/dev/null')) !== '';
    }

    /** Copy the program's output into the surface until the terminal closes. */
    private function pump(Surface $surface): void
    {
        try {
            while (true) {
                readable($this->master);
                // when the program exits the master reads fail with EIO: that is the end, not an error
                set_error_handler(static fn(): bool => true);
                try {
                    $data = fread($this->master, 65536);
                } finally {
                    restore_error_handler();
                }
                if ($data === false || $data === '') {
                    if (!\is_resource($this->master) || feof($this->master) || $data === false) {
                        break;
                    }
                    continue;
                }
                $surface->write($data);
            }
        } finally {
            $surface->input->pipe(null);
            ($this->offResize)();
            if (\is_resource($this->master)) {
                fclose($this->master);
            }
            // the terminal is closed; collect the exit status
            while (($status = proc_get_status($this->process))['running']) {
                sleep(0.01);
            }
            $this->exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            proc_close($this->process);
            $this->ended = true;
            $this->done->notify();
        }
    }

    /** Process id of the program. */
    public function pid(): int
    {
        return $this->pid;
    }

    /** True until the program has exited and its terminal has closed. */
    public function isRunning(): bool
    {
        return !$this->ended;
    }

    /**
     * The exit status once the program has ended, else null. A program killed by a signal
     * reports 128 + the signal number, as a shell does.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Wait until the program has ended and all its output is in the surface; returns the exit
     * status, or null if $timeout seconds pass first.
     */
    public function wait(float $timeout = \PHP_FLOAT_MAX): ?int
    {
        $deadline = $timeout === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : microtime(true) + $timeout;
        while (!$this->ended) {
            $left = $deadline === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : $deadline - microtime(true);
            if ($left <= 0 || !$this->done->wait($left)) {
                return null;
            }
        }
        return $this->exitCode;
    }

    /**
     * Send a signal to the program (default SIGTERM). Use SIGHUP (1) for "the terminal went
     * away", SIGKILL (9) for a program that does not listen.
     */
    public function kill(int $signal = 15): void
    {
        if ($this->ended) {
            return;
        }
        if (\function_exists('posix_kill')) {
            // the program leads its own session and process group: signal all of it
            @posix_kill(-$this->pid, $signal) || @posix_kill($this->pid, $signal);
        } else {
            proc_terminate($this->process, $signal);
        }
    }

    /**
     * Send bytes to the program as if typed. Normally input arrives through the surface's
     * `input`; use this to bypass the surface.
     */
    public function write(string $bytes): void
    {
        if ($this->ended || $bytes === '') {
            return;
        }
        $this->outBuffer .= $bytes;
        if (!$this->writing) {
            $this->writing = true;
            go(function (): void {
                try {
                    while ($this->outBuffer !== '' && \is_resource($this->master)) {
                        writable($this->master);
                        if (!\is_resource($this->master)) {
                            break;
                        }
                        $n = @fwrite($this->master, $this->outBuffer);
                        if ($n === false) {
                            break;
                        }
                        $this->outBuffer = (string) substr($this->outBuffer, $n);
                    }
                } finally {
                    $this->outBuffer = '';
                    $this->writing = false;
                }
            });
        }
    }

    /**
     * Set the terminal size; the program gets SIGWINCH. Happens automatically when the surface
     * is resized, so call it only for a pty whose surface you size differently.
     */
    public function resize(int $cols, int $rows): void
    {
        $this->wantedSize = [max(1, $cols), max(1, $rows)];
        if ($this->resizing || $this->ended) {
            return;
        }
        $this->resizing = true;
        go(function (): void {
            try {
                $applied = null;
                while (!$this->ended && $this->wantedSize !== $applied) {
                    $applied = $this->wantedSize;
                    $this->runStty($applied[0], $applied[1]);
                }
            } finally {
                $this->resizing = false;
            }
        });
    }

    /** The pty's slave device, such as /dev/pts/3. */
    public function device(): ?string
    {
        if ($this->slave === null && !$this->ended) {
            $link = @readlink("/proc/{$this->pid}/fd/0");
            if ($link === false) {
                $tty = trim((string) shell_exec('ps -o tty= -p ' . $this->pid . ' 2>/dev/null'));
                $link = $tty !== '' && $tty !== '?' ? '/dev/' . $tty : false;
            }
            $this->slave = $link === false ? null : $link;
        }
        return $this->slave;
    }

    /** stty -F <slave> cols C rows R in a helper process, waited for without blocking. */
    private function runStty(int $cols, int $rows): void
    {
        $device = $this->device();
        if ($device === null) {
            return;
        }
        $flag = \PHP_OS_FAMILY === 'Linux' ? '-F' : '-f';
        $p = @proc_open(['stty', $flag, $device, 'cols', (string) $cols, 'rows', (string) $rows], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($p)) {
            return;
        }
        while (proc_get_status($p)['running']) {
            sleep(0.005);
        }
        proc_close($p);
    }
}
