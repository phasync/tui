<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Signal;

use function fiber\sleep;

/**
 * An in-memory terminal for tests and headless programs: a `TerminalDevice` that draws on a
 * Surface instead of a tty, and takes its input from your calls.
 *
 *     fiber\run(function () {
 *         $root = new Surface();
 *         $terminal = new FakeTerminal(40, 10);
 *         $terminal->attach($root);
 *         $terminal->press('ctrl+c');          // input, encoded as a terminal sends it
 *         echo $terminal->text();              // what the terminal shows
 *         $terminal->output();                 // every byte the device sent
 *         $terminal->detach();
 *     });
 *
 * The terminal is itself a `Surface` (`$terminal->surface`): the device's output is parsed by the
 * same emulator, which answers the device's queries (so the kitty keyboard protocol is in use),
 * and input is encoded by it for the modes the device enabled (bracketed paste, SGR mouse, kitty
 * keyboard), the way a real xterm-class terminal would. So what the terminal shows must equal the
 * root's cells: the library's own output is checked by its own emulator.
 *
 * Input, three ways, each delivered at once (when the call returns, the root has dispatched
 * it; programs in ptys answer later, and `waitFor()` waits until the screen shows what you
 * expect):
 *
 *     $terminal->type("ls -l\n");                 // text, one key per character
 *     $terminal->press('ctrl+c', 'up', 'f5');     // keys by name
 *     $terminal->feed("\e[1;5A\e[<0;3;2M");        // raw bytes, exactly as a terminal sends them
 *
 * `paste()`, `mouse()`, `click()`, `drag()`, `scroll()` and `focus()` send the other events.
 */
final class FakeTerminal extends TerminalDevice
{
    /** What the terminal shows; replies and input it sends go to the device. */
    public readonly Surface $surface;
    private string $output = '';
    private int $taken = 0;
    private bool $open = false;
    private string $restore = '';
    private string $copied = '';
    private Signal $idle;

    /** A terminal of $cols x $rows (resize it later with `resize()`). */
    public function __construct(int $cols = 80, int $rows = 24)
    {
        $this->surface = new Surface($cols, $rows, 0);
        $this->idle = new Signal();
        $this->surface->input->pipe(fn(string $bytes) => $this->receive($bytes, true));
        $this->surface->on('clipboard', function (string $text): void {
            $this->copied = $text;
        });
    }

    protected function open(string $restore): void
    {
        $this->open = true;
        $this->restore = $restore;
    }

    protected function close(): void
    {
        if ($this->open) {
            $this->open = false;
            $this->send($this->restore);
            $this->idle->notify();
        }
    }

    /** The terminal's size as [cols, rows]. */
    public function size(): array
    {
        return [$this->surface->cols(), $this->surface->rows()];
    }

    protected function send(string $bytes): void
    {
        $this->output .= $bytes;
        $this->surface->write($bytes);
    }

    protected function read(float $timeout): string
    {
        // input arrives through the surface's input pipe, synchronously; this only idles
        $this->idle->wait($timeout);
        return '';
    }

    /** Everything the device sent, from the start. */
    public function output(): string
    {
        return $this->output;
    }

    /** What the device sent since the previous call. */
    public function takeOutput(): string
    {
        $out = substr($this->output, $this->taken);
        $this->taken = \strlen($this->output);
        return $out;
    }

    /** The screen as text (see `Surface::text()`), after drawing what is pending. */
    public function text(): string
    {
        $this->refresh();
        return $this->surface->text();
    }

    /** The screen with styles marked up (see `Surface::dump()`), after drawing what is pending. */
    public function dump(): string
    {
        $this->refresh();
        return $this->surface->dump();
    }

    /** The last text a program copied to the clipboard (OSC 52), as a real terminal would receive it. */
    public function clipboardText(): string
    {
        return $this->copied;
    }

    /**
     * Wait until the screen contains $text (or $condition returns true), drawing as it goes;
     * false if $timeout seconds pass first. For output of programs in ptys, which comes later.
     *
     * @param string|\Closure(FakeTerminal): bool $condition
     */
    public function waitFor(string|\Closure $condition, float $timeout = 5.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $ok = \is_string($condition) ? str_contains($this->text(), $condition) : $condition($this);
            if ($ok) {
                return true;
            }
            if (microtime(true) > $deadline) {
                return false;
            }
            sleep(0.01);
        }
    }

    /** Change the terminal size, as when the user resizes the window. */
    public function resize(int $cols, int $rows): void
    {
        $this->surface->resize($cols, $rows);
        $this->resized();
    }

    /** Press keys, given as `Key` objects or notations like `'ctrl+c'`, `'enter'`, `'a'`. */
    public function press(Key|string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->surface->dispatch(\is_string($key) ? Key::parse($key) : $key);
        }
    }

    /**
     * Type text: one key per character, as the user typing it; "\n" (or "\r") is Enter, "\t" Tab.
     * For text only: arrows, function keys and keys with modifiers go through `press()`
     * (`$t->press('up', 'ctrl+c', 'f5')`), and escape sequences as a terminal sends them through
     * `feed()`. A control character other than those three (an ESC starting a sequence, a
     * Ctrl-key byte) is refused, since it would arrive as a stray key rather than what was meant.
     *
     * @throws \InvalidArgumentException for a control character other than "\n", "\r" and "\t"
     */
    public function type(string $text): void
    {
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text, $m)) {
            throw new \InvalidArgumentException(sprintf(
                'type() is for text; control character 0x%02x: use press() for keys (\'up\', \'ctrl+c\') or feed() for escape sequences',
                \ord($m[0]),
            ));
        }
        foreach (mb_str_split($text) as $ch) {
            $this->surface->dispatch(match ($ch) {
                "\n", "\r" => new Key('enter'),
                "\t" => new Key('tab'),
                ' ' => new Key('space'),
                default => new Key($ch),
            });
        }
    }

    /** Paste text (bracketed, since the device enables bracketed paste). */
    public function paste(string $text): void
    {
        $this->surface->dispatch(new Paste($text));
    }

    /** Send a mouse event at terminal coordinates. */
    public function mouse(Mouse $event): void
    {
        $this->surface->dispatch($event);
    }

    /** Click at column $x, row $y: press and release. */
    public function click(int $x, int $y, int $button = Mouse::LEFT): void
    {
        $this->mouse(new Mouse(Mouse::PRESS, $x, $y, $button));
        $this->mouse(new Mouse(Mouse::RELEASE, $x, $y, $button));
    }

    /** Press at ($x1, $y1), drag to ($x2, $y2) and release there. */
    public function drag(int $x1, int $y1, int $x2, int $y2, int $button = Mouse::LEFT): void
    {
        $this->mouse(new Mouse(Mouse::PRESS, $x1, $y1, $button));
        $this->mouse(new Mouse(Mouse::DRAG, $x2, $y2, $button));
        $this->mouse(new Mouse(Mouse::RELEASE, $x2, $y2, $button));
    }

    /** Turn the wheel at ($x, $y): negative $clicks up, positive down. */
    public function scroll(int $x, int $y, int $clicks): void
    {
        for ($i = 0; $i < abs($clicks); $i++) {
            $this->mouse(new Mouse($clicks < 0 ? Mouse::WHEEL_UP : Mouse::WHEEL_DOWN, $x, $y));
        }
    }

    /** The terminal window gains or loses focus (reported, since the device enables focus events). */
    public function focus(bool $focused): void
    {
        $this->surface->dispatch(new Focus($focused));
    }

    /**
     * Raw bytes as input, as the terminal would send them: text in UTF-8 and escape sequences
     * (CSI and SS3 keys, kitty CSI-u keys, SGR mouse reports, bracketed pastes, focus reports),
     * decoded by the same decoder as a real terminal's input. For input that `type()` and
     * `press()` cannot express, or to replay bytes captured from a real terminal.
     *
     * Each call is one read from the terminal followed by a pause, as a real device sees it: a
     * sequence must be whole within the call (an incomplete one is dropped, as after the escape
     * timeout), except a bracketed paste, which may span calls. A lone ESC at the end is the
     * Escape key, ESC and a character Alt and that character.
     */
    public function feed(string $bytes): void
    {
        $this->receive($bytes, true);
    }
}
