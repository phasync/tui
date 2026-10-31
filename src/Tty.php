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
    private string $queue = '';
    private bool $sending = false;
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
        $this->hyperlinks = self::hyperlinksFromEnvironment(getenv());
        $this->setColors(self::colorsFromEnvironment(getenv()));
    }

    /**
     * How many colours a terminal shows, from its environment: truecolour when `COLORTERM` is
     * `truecolor` or `24bit` (or the terminal is one known to have it), 256 when `TERM` says
     * `256color`, else 16. `setColors()` changes it for a terminal that says it wrong.
     *
     * @param array<string, string> $env
     */
    public static function colorsFromEnvironment(array $env): int
    {
        $term = $env['TERM'] ?? '';
        if (\in_array(strtolower($env['COLORTERM'] ?? ''), ['truecolor', '24bit'], true)
            || \in_array($env['TERM_PROGRAM'] ?? '', ['iTerm.app', 'WezTerm', 'vscode', 'ghostty'], true)
            || isset($env['KITTY_WINDOW_ID']) || isset($env['WT_SESSION']) || str_contains($term, 'direct')
            || \in_array($term, ['xterm-kitty', 'foot', 'wezterm', 'xterm-ghostty', 'alacritty'], true)) {
            return Color::DEPTH_TRUECOLOR;
        }
        return str_contains($term, '256') ? Color::DEPTH_256 : Color::DEPTH_16;
    }

    /**
     * The terminal in inline mode: the root shows as a live region at the bottom of the
     * terminal's normal screen, under what it shows already, and its scrollback goes into the
     * terminal's (see `TerminalDevice`). For progress lines and status displays that should not
     * take the screen over. With $keep false, detaching erases the region instead of leaving
     * the last frame standing.
     *
     *     $app->start(Tty::inline());        // the same component as with new Tty()
     *
     * @param resource|null $in
     * @param resource|null $out
     */
    public static function inline($in = null, $out = null, bool $keep = true): self
    {
        $tty = new self($in, $out);
        $tty->useInline($keep);
        return $tty;
    }

    /**
     * @internal Whether the environment says the terminal shows OSC 8 hyperlinks: true for
     * terminals known to, false inside tmux or screen (which may not pass them on) and on the
     * Linux console, null when it does not say (the terminal's XTVERSION answer decides).
     *
     * @param array<string, string> $env
     */
    public static function hyperlinksFromEnvironment(array $env): ?bool
    {
        $term = $env['TERM'] ?? '';
        if (isset($env['TMUX']) || isset($env['STY']) || str_starts_with($term, 'screen') || str_starts_with($term, 'tmux') || $term === 'linux' || $term === 'dumb') {
            return false;
        }
        if (\in_array($env['TERM_PROGRAM'] ?? '', ['iTerm.app', 'WezTerm', 'vscode', 'ghostty', 'Tabby'], true)
            || isset($env['KITTY_WINDOW_ID']) || isset($env['WT_SESSION']) || isset($env['WEZTERM_EXECUTABLE'])
            || (int) ($env['VTE_VERSION'] ?? 0) >= 5000
            || \in_array($term, ['xterm-kitty', 'foot', 'foot-extra', 'wezterm', 'xterm-ghostty', 'alacritty'], true)) {
            return true;
        }
        return null;
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

    /** The terminal's size as [cols, rows], from `stty size` (re-read on SIGWINCH, or twice a second). */
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
        // one writer at a time: bytes sent while a write waits for the terminal queue behind it,
        // so two sequences never interleave
        $this->queue .= $bytes;
        if ($this->sending) {
            return;
        }
        $this->sending = true;
        try {
            while ($this->queue !== '') {
                $n = @fwrite($this->out, $this->queue);
                if ($n === false) {
                    $this->queue = '';
                    return;
                }
                $this->queue = (string) substr($this->queue, $n);
                if ($this->queue !== '') {
                    writable($this->out);
                }
            }
        } finally {
            $this->sending = false;
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
