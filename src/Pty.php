<?php

namespace phasync\Tui;

use fiber\Deferred;
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
 * everything written to the surface's `input` (keys and pastes from `Surface::dispatch()`, the
 * terminal's replies to queries) goes to the program. Resizing the surface resizes the
 * terminal, which sends SIGWINCH to the program.
 *
 * The end of the program is announced three ways: `$onExit` (given to `start()`) is called with
 * the exit status, `exited()` is an awaitable that resolves with it, and `wait()` waits for it.
 * Either way the program's last output is in the surface by then. A Pty you start is yours to
 * end: it runs until the program exits or you end it, and while it runs it keeps `fiber\run()`
 * from returning.
 *
 * **Ending it ends everything in it.** The program leads a session of its own on the pty, as a
 * login shell does on a real terminal, and everything it starts belongs to that session: the
 * jobs of a shell, an editor it runs, another multiplexer. `hangUp()` does what closing a
 * terminal window does to all of them (SIGHUP, then SIGKILL for what is still there after a
 * grace period); `kill()` sends a signal to all of them. So a shell that is running `sleep`,
 * `vim` or a build in the foreground ends with its pty, whether the command was given as a
 * string or as an argument vector. Ptys still running when the PHP process exits are hung up
 * on the way out. (A program that starts a session of its own, as a daemon or a nested `Pty`
 * does, is not part of this one; it is ended by whoever started it.)
 *
 * Everything runs in coroutines (`fiber\go()`), so `start()` and the other waiting methods must
 * be called inside `fiber\run()`; nothing blocks other coroutines.
 */
final class Pty
{
    /** An OSC the wrapper prints once it has set the size, before the program starts. */
    private const READY = '7701;phasync-tui-ready';

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
    /** The wrapper has set the initial size: later sizes can be applied. */
    private bool $ready = false;
    /** The program has written something (after the wrapper's marker). */
    private bool $spoke = false;
    /** The size changed before the program wrote anything: tell it again once it does. */
    private bool $resizedEarly = false;
    /** @var array<int, Pty> the ptys that are running, hung up when PHP exits */
    private static array $running = [];
    private static bool $shutdownRegistered = false;
    /** @var array<int, true> process groups of the session seen so far */
    private array $groups = [];
    private Signal $done;
    private Deferred $exited;
    private ?\Closure $onExit = null;
    private \Closure $offResize;

    private function __construct()
    {
        $this->done = new Signal();
        $this->exited = new Deferred();
    }

    /**
     * Start $command in a pty attached to $surface.
     *
     * @param string|list<string> $command a shell command line (run with /bin/sh -c) or an
     *                                     argument vector (run directly, no shell)
     * @param string|null         $cwd     working directory, default the current one
     * @param array<string,string> $env    variables added to the inherited environment
     * @param (\Closure(int $code, Pty $pty): void)|null $onExit called once when the program has
     *                                     ended and its output is in the surface, with its exit status
     *
     * @throws \RuntimeException when the process cannot be started
     */
    public static function start(string|array $command, Surface $surface, ?string $cwd = null, array $env = [], ?\Closure $onExit = null): Pty
    {
        $argv = \is_string($command) ? ['/bin/sh', '-c', $command] : array_values($command);
        if ($argv === []) {
            throw new \InvalidArgumentException('Empty command');
        }
        $cols = $surface->cols();
        $rows = $surface->rows();
        // set the size before the program starts, then exec it in place
        // PHP's proc_open() passes every descriptor of this process on to the child, among them the
        // masters of other ptys: a program holding them would keep those terminals from ever
        // hanging up. The wrapper closes them, sets the size, and runs the program in its place.
        // bash can close any descriptor; POSIX sh only 3 to 9.
        $bash = self::bash();
        $close = 'for f in /proc/$$/fd/* /dev/fd/*; do n=${f##*/}; case $n in ' . ($bash !== null ? '*[!0-9]*|0|1|2) ;; *)' : '[3-9])')
            . ' eval "exec $n>&-";; esac; done; ';
        $wrapper = [$bash ?? '/bin/sh', '-c', $close . "stty cols $cols rows $rows 2>/dev/null; printf '\\033]" . self::READY . "\\007'; exec \"\$@\"", 'sh', ...$argv];
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
        $pty->onExit = $onExit;
        $pty->process = $process;
        $pty->master = $pipes[1];
        fclose($pipes[0]);
        fclose($pipes[2]);
        stream_set_blocking($pty->master, false);
        stream_set_read_buffer($pty->master, 0);
        stream_set_write_buffer($pty->master, 0);
        $pty->pid = proc_get_status($process)['pid'];
        self::$running[spl_object_id($pty)] = $pty;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                // the process is ending without having ended these: do it now, without coroutines
                foreach (self::$running as $pty) {
                    $pty->signal(1);
                }
                $deadline = microtime(true) + 0.5;
                while (self::$running !== [] && microtime(true) < $deadline) {
                    foreach (self::$running as $id => $pty) {
                        if (!proc_get_status($pty->process)['running'] && !$pty->anyLeft()) {
                            unset(self::$running[$id]);
                        }
                    }
                    usleep(10000);
                }
                foreach (self::$running as $pty) {
                    $pty->signal(9);
                }
            });
        }

        $surface->input->pipe(fn(string $bytes) => $pty->write($bytes));
        $pty->offResize = $surface->on('resize', fn(Resize $size) => $pty->resize($size->cols, $size->rows));
        go(fn() => $pty->pump($surface));
        return $pty;
    }

    private static function bash(): ?string
    {
        static $bash = false;
        if ($bash === false) {
            $bash = null;
            foreach (['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash', '/opt/homebrew/bin/bash'] as $path) {
                if (is_executable($path)) {
                    $bash = $path;
                    break;
                }
            }
        }
        return $bash;
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
                if (!$this->ready) {
                    $marker = "\e]" . self::READY . "\x07";
                    $head = ($head ?? '') . $data;
                    $at = strpos($head, $marker);
                    if ($at === false) {
                        continue;   // nothing comes before the marker but a piece of it
                    }
                    $data = substr($head, 0, $at) . substr($head, $at + \strlen($marker));
                    $this->ready = true;
                    if ($this->wantedSize !== null) {
                        $this->resize(...$this->wantedSize);
                    }
                    if ($data === '') {
                        continue;
                    }
                }
                if (!$this->spoke) {
                    $this->spoke = true;
                    if ($this->resizedEarly) {
                        // the size changed while the program was starting, maybe before it was
                        // listening for SIGWINCH (vim installs its handler late): tell it again
                        go(function (): void {
                            while ($this->resizing && !$this->ended) {
                                sleep(0.005);
                            }
                            $this->winch();
                        });
                    }
                }
                $surface->write($data);
            }
        } finally {
            unset(self::$running[spl_object_id($this)]);
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
            $this->exited->settle(true, $this->exitCode);
            if ($this->onExit !== null) {
                ($this->onExit)($this->exitCode, $this);
            }
        }
    }

    /**
     * An awaitable that resolves with the exit status when the program has ended and all its
     * output is in the surface: `$code = fiber\await($pty->exited())`, or race it with other
     * work in `fiber\await_any()`.
     */
    public function exited(): object
    {
        return $this->exited;
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
     * Send a signal (default SIGTERM) to the program and every process it started on this
     * terminal: each process group of its session, so a shell's foreground job gets it too.
     * SIGKILL (9) ends all of them for certain; to end the program the way a closed terminal
     * window does, use `hangUp()`.
     */
    public function kill(int $signal = 15): void
    {
        if (!$this->ended) {
            $this->signal($signal);
        }
    }

    /**
     * End the program and everything it started, as closing its terminal window would: SIGHUP
     * (and SIGCONT, to wake stopped jobs) to every process group of its session, then, for what
     * is still running after $grace seconds, SIGKILL. Returns the exit status once the terminal
     * has closed (also when it had ended before), or null if even that did not end it within a
     * second (a process that left the session holding the terminal open). Must be called inside
     * a coroutine; other coroutines run while it waits.
     */
    public function hangUp(float $grace = 0.5): ?int
    {
        if ($this->ended) {
            return $this->exitCode;
        }
        $this->signal(1);
        $this->signal(\defined('SIGCONT') ? \SIGCONT : 18);
        if ($this->wait($grace) === null) {
            $this->signal(9);
            $this->wait(1.0);
        }
        return $this->ended ? $this->exitCode : null;
    }

    /**
     * Signal every process group of the program's session: those of the processes below the
     * program now, and those seen before (a job whose shell has died is still in them).
     */
    private function signal(int $signal): void
    {
        if (!\function_exists('posix_kill')) {
            proc_terminate($this->process, $signal);
            return;
        }
        foreach ($this->processGroups() as $group) {
            $this->groups[$group] = true;
        }
        foreach (array_keys($this->groups) as $group) {
            @posix_kill(-$group, $signal);
        }
        @posix_kill($this->pid, $signal);
    }

    /** True while a process is left in a process group of the program's session. */
    private function anyLeft(): bool
    {
        foreach (array_keys($this->groups) as $group) {
            if (@posix_kill(-$group, 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The process groups of the program and the processes below it that are in its session
     * (Linux, from /proc; elsewhere only the program's own group, which it leads).
     *
     * @return list<int>
     */
    private function processGroups(): array
    {
        $groups = [$this->pid => true];
        $todo = [$this->pid];
        // processes end while we look: a file that is gone is not an error
        set_error_handler(static fn(): bool => true);
        try {
            while (($pid = array_pop($todo)) !== null) {
                foreach (glob("/proc/$pid/task/*/children", \GLOB_NOSORT) ?: [] as $file) {
                    foreach (preg_split('/\s+/', (string) file_get_contents($file), -1, \PREG_SPLIT_NO_EMPTY) as $child) {
                        $stat = file_get_contents("/proc/$child/stat");
                        if ($stat === false || ($end = strrpos($stat, ')')) === false) {
                            continue;
                        }
                        // after "pid (comm)": state ppid pgrp session ...
                        $fields = explode(' ', substr($stat, $end + 2), 5);
                        if ((int) ($fields[3] ?? 0) === $this->pid) {
                            $groups[(int) $fields[2]] = true;
                            $todo[] = (int) $child;
                        }
                    }
                }
            }
        } finally {
            restore_error_handler();
        }
        return array_keys($groups);
    }

    /** Send SIGWINCH to the terminal's foreground job, so it reads the size again. */
    private function winch(): void
    {
        if ($this->ended || !\function_exists('posix_kill')) {
            return;
        }
        $foreground = $this->pid;
        set_error_handler(static fn(): bool => true);
        try {
            $stat = file_get_contents("/proc/{$this->pid}/stat");
        } finally {
            restore_error_handler();
        }
        if ($stat !== false && ($end = strrpos($stat, ')')) !== false) {
            // state ppid pgrp session tty_nr tpgid
            $tpgid = (int) (explode(' ', substr($stat, $end + 2), 7)[5] ?? 0);
            if ($tpgid > 0) {
                $foreground = $tpgid;
            }
        }
        @posix_kill(-$foreground, \defined('SIGWINCH') ? \SIGWINCH : 28);
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
     * is resized, so call it only for a pty whose surface you size differently. A size set while
     * the program is still starting (before its first output) is announced again with a second
     * SIGWINCH when it first writes, for programs that install their handler late.
     */
    public function resize(int $cols, int $rows): void
    {
        $this->wantedSize = [max(1, $cols), max(1, $rows)];
        if (!$this->spoke) {
            $this->resizedEarly = true;
        }
        if ($this->resizing || $this->ended || !$this->ready) {
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
            set_error_handler(static fn(): bool => true);
            try {
                $link = readlink("/proc/{$this->pid}/fd/0");
            } finally {
                restore_error_handler();
            }
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
