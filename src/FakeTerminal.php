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
 *
 * **Inline mode**: `FakeTerminal::inline(80, 24)` shows the root as a live region at the bottom
 * of the normal screen (see `TerminalDevice`), under whatever was written into `$surface`
 * before (a shell prompt, for a test); `frozen()` is the text above the region, scrollback
 * included, and `region()` the region's.
 *
 * **Reading the screen after input**: call `settle()` after the input and before `text()`.
 * `text()` draws what is pending (what a handler changed is there right after `press()`), but
 * a coroutine a handler started changes the screen in later frames, which `settle()` waits for. Never `usleep()` in a coroutine: it blocks the loop, so nothing
 * renders while it sleeps. Wrap a test in `run()`, which detaches the terminal afterwards also
 * when the test throws, so a failing test fails instead of hanging `fiber\run()`.
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

    /**
     * A terminal of $cols x $rows (resize it later with `resize()`); with $hyperlinks false,
     * one that does not show hyperlinks (the device then writes the text of links alone); with
     * $colors 256 or 16, one of that many colours (the device then draws truecolour cells in
     * the nearest colour the terminal has; see `colors()`). $background is what it answers
     * when asked for its background colour (OSC 11): `'dark'` (black), `'light'` (white), or
     * null for a terminal that does not answer; the theme follows it (see `background()`).
     */
    public function __construct(int $cols = 80, int $rows = 24, bool $hyperlinks = true, int $colors = Color::DEPTH_TRUECOLOR, ?string $background = 'dark')
    {
        if ($background !== null && $background !== 'dark' && $background !== 'light') {
            throw new \InvalidArgumentException("A FakeTerminal's background is 'dark', 'light' or null, not '$background'");
        }
        $this->hyperlinks = $hyperlinks;
        $this->untilHandled = true;
        $this->setColors($colors);
        $this->surface = new Surface($cols, $rows);
        if ($background === 'light') {
            $this->surface->setDefaultColors(Color::rgb(0, 0, 0), Color::rgb(255, 255, 255));
        }
        $this->idle = new Signal();
        $this->surface->input->pipe(fn(string $bytes) => $this->receive(
            $background === null ? (string) preg_replace('/\e\]11;[^\e\x07]*(?:\e\\\\|\x07)/', '', $bytes) : $bytes,
            true,
        ));
        $this->surface->on('clipboard', function (string $text): void {
            $this->copied = $text;
        });
    }

    /**
     * A terminal of $cols x $rows that shows its root inline: a live region at the bottom of
     * its normal screen, the root's scrollback going into its own (see `TerminalDevice`). With
     * $keep false, detaching erases the region instead of leaving the last frame.
     */
    public static function inline(int $cols = 80, int $rows = 24, bool $keep = true, bool $hyperlinks = true, int $colors = Color::DEPTH_TRUECOLOR, ?string $background = 'dark'): self
    {
        $terminal = new self($cols, $rows, $hyperlinks, $colors, $background);
        $terminal->useInline($keep);
        return $terminal;
    }

    /**
     * Inline mode: the text above the live region, the terminal's scrollback first, a line per
     * row (trailing blanks cut), joined with "\n"; everything the terminal shows when the
     * region is gone (detached). The lines written above the region as the root scrolled, and
     * by `println()`, are here as they were then.
     */
    public function frozen(): string
    {
        $this->refresh();
        $s = $this->surface;
        $place = $this->regionPlace();
        $top = $place === null ? $s->rows() : max(0, $s->cursor()->y - $place[0]);
        $lines = [];
        for ($y = -$s->scrollbackSize(); $y < $top; $y++) {
            $lines[] = $s->line($y);
        }
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        return implode("\n", $lines);
    }

    /** Inline mode: the text of the live region, a line per row, joined with "\n"; '' when not attached inline. */
    public function region(): string
    {
        $this->refresh();
        $place = $this->regionPlace();
        if ($place === null) {
            return '';
        }
        $s = $this->surface;
        $top = $s->cursor()->y - $place[0];
        $lines = [];
        for ($y = $top; $y < $top + $place[1]; $y++) {
            $lines[] = $s->line($y);
        }
        return implode("\n", $lines);
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

    /** The screen as text (see `Surface::text()`), after drawing what is pending (pending renders included, see `refresh()`). */
    public function text(): string
    {
        $this->refresh();
        return $this->surface->text();
    }

    /**
     * The screen with styles marked up, as it looks (see `Surface::dump()` with `$looks`: a
     * blank in the style it shows, its background), after drawing what is pending. The device
     * draws what looks different, so the terminal's blanks may carry a foreground left from the
     * text before them; this is the same for every way of drawing the same screen. Compare it
     * with the root's `dump(true)`.
     */
    public function dump(): string
    {
        $this->refresh();
        return $this->surface->dump(true);
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

    /**
     * Run $code with this terminal attached and detach it afterwards, also when $code throws:
     * the program on it ends (the root is closed, the component tree disposed), so the
     * exception reaches `fiber\run()` instead of the terminal's coroutines keeping it running.
     * The way to write a headless test or a scripted session; returns what $code returned.
     *
     *     fiber\run(function () {
     *         $terminal = new FakeTerminal(40, 10);
     *         $app = (new App())->start($terminal);
     *         $terminal->run(function (FakeTerminal $t) use ($app) {
     *             $t->type('hello');
     *             $t->settle();
     *             assert(str_contains($t->text(), 'hello'));      // a failure ends the run
     *         });
     *     });
     *
     * @template T
     * @param \Closure(FakeTerminal): T $code
     * @return T
     */
    public function run(\Closure $code): mixed
    {
        try {
            return $code($this);
        } finally {
            $this->detach();
        }
    }

    /**
     * Wait until the program has drawn what the input so far makes it draw: the renders that
     * the last key, click or state change asked for have run and the screen is up to date. Read
     * the screen after it, not right after `type()` or `press()` (which return once the input was
     * dispatched; renders come in the next frame). Waits cooperatively (never `usleep()` in a
     * coroutine: it blocks every coroutine, the program's included), at most $timeout seconds.
     * For output of programs in ptys, which come later still, use `waitFor()`.
     */
    public function settle(float $timeout = 1.0): void
    {
        $deadline = microtime(true) + $timeout;
        $quiet = 0;
        $last = null;
        while ($quiet < 3 && microtime(true) < $deadline) {
            sleep(0);
            $this->refresh();
            $now = [\strlen($this->output), $this->surface->version()];
            $quiet = $now === $last ? $quiet + 1 : 0;
            $last = $now;
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
