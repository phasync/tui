<?php

namespace phasync\Tui;

use fiber\TimeoutException;

use function fiber\go;
use function fiber\readable;
use function fiber\sleep;
use function fiber\writable;

/**
 * The real terminal: the program's STDIN and STDOUT, as a `TerminalDevice` that shows a root
 * Surface (see `TerminalDevice` for what attaching does).
 *
 *     $tty = new Tty();
 *     if (!$tty->isTerminal()) { exit("Run me in a terminal\n"); }
 *     $tty->attach($root);
 *
 * Attaching saves the terminal settings and switches to raw mode with `stty`; detaching restores
 * them. They are also restored when the program ends any other way: an uncaught exception, a
 * fatal error, `exit()`, or SIGTERM/SIGHUP/SIGINT/SIGQUIT (with ext-pcntl; the program then exits
 * with status 128 + signal). The size is re-read on SIGWINCH with ext-pcntl, else polled with
 * `stty size` twice a second. Text copied in a window goes to the clipboard with OSC 52, and also
 * through `wl-copy`, `xclip` or `pbcopy` when one is installed and a display is available.
 */
final class Tty extends TerminalDevice
{
    /** @var resource */
    private $in;
    /** @var resource */
    private $out;
    private ?string $saved = null;
    private string $restore = '';
    /** @var array{int,int}|null */
    private ?array $size = null;
    private float $sizeChecked = 0.0;
    private bool $resized = false;
    private bool $signals = false;
    private static bool $shutdownRegistered = false;
    /** @var list<Tty> */
    private static array $openTerminals = [];

    /**
     * @param resource|null $in  the terminal to read keys from, default STDIN
     * @param resource|null $out the terminal to draw on, default STDOUT
     */
    public function __construct($in = null, $out = null)
    {
        $this->in = $in ?? \STDIN;
        $this->out = $out ?? \STDOUT;
    }

    /** True if both streams are terminals (false when input or output is redirected). */
    public function isTerminal(): bool
    {
        return stream_isatty($this->in) && stream_isatty($this->out);
    }

    /** @throws \RuntimeException when not connected to a terminal */
    protected function open(string $restore): void
    {
        if ($this->saved !== null) {
            return;
        }
        if (!$this->isTerminal()) {
            throw new \RuntimeException('Not a terminal: STDIN and STDOUT must be connected to a terminal');
        }
        $this->restore = $restore;
        $this->saved = trim($this->stty('-g'));
        $this->stty('raw', '-echo');
        stream_set_blocking($this->in, false);
        stream_set_blocking($this->out, false);
        self::$openTerminals[spl_object_id($this)] = $this;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (self::$openTerminals as $tty) {
                    $tty->close();
                }
            });
        }
        if (\function_exists('pcntl_signal') && !$this->signals) {
            $this->signals = true;
            pcntl_async_signals(true);
            pcntl_signal(\SIGWINCH, function (): void {
                $this->resized = true;
            });
            foreach ([\SIGTERM, \SIGHUP, \SIGINT, \SIGQUIT] as $sig) {
                pcntl_signal($sig, function (int $signal): void {
                    $this->close();
                    exit(128 + $signal);
                });
            }
        }
    }

    protected function close(): void
    {
        if ($this->saved === null) {
            return;
        }
        stream_set_blocking($this->out, true);
        if ($this->restore !== '') {
            @fwrite($this->out, $this->restore);
        }
        $this->stty($this->saved);
        stream_set_blocking($this->in, true);
        $this->saved = null;
        unset(self::$openTerminals[spl_object_id($this)]);
        if ($this->signals) {
            $this->signals = false;
            foreach ([\SIGWINCH, \SIGTERM, \SIGHUP, \SIGINT, \SIGQUIT] as $sig) {
                pcntl_signal($sig, \SIG_DFL);
            }
        }
    }

    public function size(): array
    {
        $now = microtime(true);
        if ($this->size === null || $this->resized || (!$this->signals && $now - $this->sizeChecked > 0.5)) {
            $this->resized = false;
            $this->sizeChecked = $now;
            if (preg_match('/^(\d+) (\d+)/', trim($this->stty('size')), $m) && (int) $m[2] > 0) {
                $this->size = [(int) $m[2], (int) $m[1]];
            } else {
                $this->size ??= [(int) (getenv('COLUMNS') ?: 80), (int) (getenv('LINES') ?: 24)];
            }
        }
        return $this->size;
    }

    protected function send(string $bytes): void
    {
        while ($bytes !== '') {
            $n = @fwrite($this->out, $bytes);
            if ($n === false) {
                return;
            }
            $bytes = (string) substr($bytes, $n);
            if ($bytes !== '') {
                writable($this->out);
            }
        }
    }

    protected function read(float $timeout): string
    {
        try {
            readable($this->in, $timeout);
        } catch (TimeoutException) {
            return '';
        }
        $data = @fread($this->in, 65536);
        return \is_string($data) ? $data : '';
    }

    protected function clipboard(string $text): void
    {
        parent::clipboard($text);
        $command = match (true) {
            \PHP_OS_FAMILY === 'Darwin' => ['pbcopy'],
            getenv('WAYLAND_DISPLAY') !== false => ['wl-copy'],
            getenv('DISPLAY') !== false => ['xclip', '-selection', 'clipboard'],
            default => null,
        };
        if ($command === null || trim((string) shell_exec('command -v ' . $command[0] . ' 2>/dev/null')) === '') {
            return;
        }
        $p = @proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($p)) {
            return;
        }
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        go(function () use ($p): void {
            // xclip stays until another program owns the clipboard: do not wait for it
            for ($i = 0; $i < 100 && proc_get_status($p)['running']; $i++) {
                sleep(0.02);
            }
        });
    }

    /** Run stty on this terminal and return its output. */
    private function stty(string ...$args): string
    {
        $p = proc_open(['stty', ...$args], [0 => $this->in, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($p)) {
            return '';
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        return $out;
    }
}
