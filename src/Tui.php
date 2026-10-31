<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Live;

/**
 * The runner: show a screen on the terminal, run it until it quits, give the terminal back. A
 * whole program, in one file and without a class:
 *
 *     $s = new State(['done' => 0]);
 *     fiber\run(function () use ($s) {
 *         fiber\go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { fiber\sleep(0.05); $s->done = $i; } });
 *         Tui::inline(fn() => new Column([
 *             new Text("Working… {$s->done}%"),
 *             new ProgressBar($s->done / 100),
 *         ]));
 *     });
 *
 * `$root` is what to show: an element tree (`new Column([...])`), a component (`new App()`), or a
 * closure that returns either. **A closure is a component**: it is rendered (called) when the
 * screen is shown and again whenever a `State` it read changes, so the screen follows the state
 * with no class and no `requestRender()`. A closure that reads no state is called once: the
 * component it returns is the root itself (`fn() => new App()`, for a component that starts
 * coroutines or reads the terminal in its constructor).
 *
 * **Input without a class.** `keys:` binds keys on the root, in the notation of
 * `Component::bindings()` with closures for the methods:
 *
 *     Tui::run(fn() => $board($s), keys: [
 *         'down'        => fn() => $s->sel++,
 *         'shift+right' => [fn() => $s->move(+1), 'move right'],      // listed by Help
 *         'ctrl+s'      => [fn() => $s->save(), 'save', 'always' => true],
 *     ]);
 *
 * They run before the focused component gets the key (each is marked `first`; say
 * `'first' => false` for a key the focused component should have first, as a text field's
 * letters); a bound key is taken. Elements take `onKey:` and `onMouse:` closures of their own
 * (see `Element`), and `Dialog::open()` / `Confirm::ask()` open a dialog from any of these
 * handlers, which may wait for the answer (`fiber\await(Confirm::ask('Quit', 'Really quit?'))`).
 *
 * `Tui::run()` takes the terminal over (full screen), `Tui::inline()` shows the screen as a live
 * region under the shell's output, `Tui::headless()` on a `FakeTerminal` for tests and headless
 * programs. **Headless without editing**: a program run with `--headless=COLSxROWS` (or with
 * `TUI_HEADLESS=COLSxROWS` in the environment) runs `Tui::run()`/`Tui::inline()` on a
 * `FakeTerminal` of that size instead of the terminal for a second (`COLSxROWS@SECONDS` for
 * another time), prints the screen and ends (the root quits, as with Ctrl-Q):
 * `php examples/dashboard.php --headless=100x30`. Each call wraps the program in its own `fiber\run()` (it also works inside one of
 * yours, which a program that starts coroutines with `fiber\go()` needs: `fiber\go()` must be
 * called inside `fiber\run()`). It returns when the root has ended, with the root component
 * (read what it collected from it). **Quitting**: the root quits when it is disposed
 * (`$this->dispose()` in the root, `$root->dispose()` from anywhere) or when Ctrl-Q reaches it
 * and no component on the way binds Ctrl-Q (a `Help` element lists it as "quit").
 * **Theme**: `theme:` is `'dark'`, `'light'` or `'mono'` (see `Stylesheet::theme()`); without it the
 * program is drawn in the theme for the terminal's background (it is asked when the program
 * starts: `light` on a light terminal, `dark` on a dark one and when it does not say). A
 * stylesheet of the program's (`$root->setStylesheet(Stylesheet::default()->with([...]))`)
 * refines the theme in force.
 * **Failing**: an exception anywhere in the program that no error boundary contains (the
 * root's hooks, handlers or coroutines, the closure given as the root, the handlers given with
 * `keys:` and to elements, a coroutine started with `Tui::go()`) ends the program: the tree is
 * disposed, the terminal restored, and the exception comes out of the call, as it would
 * without a terminal UI. Start the program's own coroutines with `Tui::go()`, not
 * `fiber\go()` (see `go()`).
 */
final class Tui
{
    /** The key that quits a program run by `Tui` when nothing binds it. */
    public const QUIT = 'ctrl+q';

    /**
     * The exception of a coroutine started with go(), for the runner to throw, with the root
     * it ended (null: none was running yet, the next runner throws it as it starts).
     *
     * @var array{\Throwable, ?\WeakReference<Component>}|null
     */
    private static ?array $failure = null;

    /**
     * Start a coroutine of the program, as `fiber\go()` does, except that an exception in it
     * ends the program at once: the screen is disposed, the terminal restored, and the
     * exception comes out of `Tui::run()` (and so out of your `fiber\run()`), readable on the
     * restored terminal. Started before `Tui::run()` (a job the screen shows) or from a
     * handler. Make it end with the screen as any coroutine: check a flag the program clears
     * when `Tui::run()` returns.
     *
     *     fiber\run(function () use ($s) {
     *         $running = true;
     *         Tui::go(function () use ($s, &$running) {
     *             while ($running) { fiber\sleep(0.1); $s->done = fetchProgress(); }
     *         });
     *         try { Tui::run(fn() => new ProgressBar($s->done)); } finally { $running = false; }
     *     });
     *
     * A coroutine started with `fiber\go()` itself is not seen by the library: an exception in
     * it is kept by `fiber\run()` until everything inside it has ended (the screen too), and
     * only then thrown. A coroutine of a component (`$this->go()` in a class) is contained by
     * the component's error boundary instead: the component shows the error in its rectangle
     * and the rest of the program goes on.
     */
    public static function go(\Closure $task): void
    {
        \fiber\go(static function () use ($task): void {
            try {
                $task();
            } catch (\Throwable $e) {
                $root = Component::runningRoot();
                self::$failure ??= [$e, $root === null ? null : \WeakReference::create($root)];
                $root?->dispose();
                throw $e;
            }
        });
    }

    /**
     * End the running program, as Ctrl-Q does: the root is disposed, the terminal given back,
     * and `Tui::run()` (`inline()`) returns. From any handler or coroutine of the program, also
     * one started with `fiber\go()`; a key action too, without a closure:
     * `Tui::run($screen, keys: ['q' => ['quit', 'quit']])` (a binding to `'quit'` on a component
     * that has no `quit()` method of its own ends the program). Nothing happens when no
     * program runs. With several roots running (nested instances), the one started last.
     */
    public static function quit(): void
    {
        Component::runningRoot()?->dispose();
    }

    /**
     * Run $root (an element, a component, or a closure returning either) full screen on the
     * terminal (`Tty`) until it quits, then restore the terminal. Returns the root component,
     * ended.
     *
     * @param Element|(\Closure(): ?Element) $root
     * @param string|null $theme `'dark'`, `'light'`, `'mono'`; null: the one for the terminal's background
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys keys bound on the root (see the class description)
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function run(Element|\Closure $root, ?string $theme = null, array $keys = []): Component
    {
        return self::until(self::headlessSize() === null ? new Tty() : null, $root, $theme, $keys);
    }

    /**
     * Run $root (as for `run()`) in inline mode (see `TerminalDevice`): a live region at the
     * bottom of the terminal's normal screen, as tall as what it shows (at most the terminal's
     * height), the rows it scrolls out going into the terminal's scrollback. The last frame
     * stays on the terminal afterwards, or is erased with $keep false. Returns the root
     * component, ended.
     *
     * @param Element|(\Closure(): ?Element) $root
     * @param string|null $theme as for `run()`
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys as for `run()`
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function inline(Element|\Closure $root, bool $keep = true, ?string $theme = null, array $keys = []): Component
    {
        return self::until(self::headlessSize() === null ? Tty::inline(keep: $keep) : null, $root, $theme, $keys, true);
    }

    /**
     * Start $root (as for `run()`) on a new `FakeTerminal` of $cols x $rows (in inline mode with
     * $inline, see `FakeTerminal::inline()`) and return the terminal: for tests and headless
     * programs, inside your own `fiber\run()`. The root quits as under `run()` (Ctrl-Q
     * included); drive it with the terminal's `press()`, `type()`, `settle()` and read it with
     * `text()`. `$terminal->detach()` ends it.
     *
     *     fiber\run(function () {
     *         $terminal = Tui::headless(new App(), 60, 20);
     *         $terminal->press('ctrl+q');
     *     });
     *
     * @param Element|(\Closure(): ?Element) $root
     * @param string|null $theme      as for `run()`
     * @param string|null $background what the terminal says its background is: `'dark'`, `'light'`, null for nothing (see `FakeTerminal`)
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys as for `run()`
     */
    public static function headless(Element|\Closure $root, int $cols = 80, int $rows = 24, bool $inline = false, ?string $theme = null, ?string $background = 'dark', array $keys = []): FakeTerminal
    {
        $terminal = $inline ? FakeTerminal::inline($cols, $rows, background: $background) : new FakeTerminal($cols, $rows, background: $background);
        self::start($root, $terminal, $theme, $keys);
        return $terminal;
    }

    /**
     * The device for $device null: headless (see headlessSize()), the program runs on a
     * `FakeTerminal` for a moment, its screen is printed, and it ends.
     *
     * @param array<string, mixed> $keys
     */
    private static function until(?TerminalDevice $device, Element|\Closure $root, ?string $theme, array $keys, bool $inline = false): Component
    {
        return \fiber\run(static function () use ($device, $root, $theme, $keys, $inline): Component {
            if ($device === null) {
                [$cols, $rows, $seconds] = self::headlessSize();
                $terminal = $inline ? FakeTerminal::inline($cols, $rows) : new FakeTerminal($cols, $rows);
                $app = self::start($root, $terminal, $theme, $keys);
                try {
                    \fiber\sleep($seconds);
                    $terminal->settle();
                    $screen = $inline ? '' : $terminal->text();
                } finally {
                    $app->dispose();
                }
                echo rtrim($inline ? $terminal->frozen() : $screen), "\n";
                self::rethrow($app);
                return $app;
            }
            $app = self::start($root, $device, $theme, $keys);
            try {
                $device->wait();
            } finally {
                $app->dispose();
            }
            self::rethrow($app);
            return $app;
        });
    }

    /**
     * A coroutine of go() failed: its exception comes out of the runner of the program it
     * ended ($app), or out of the next runner when it ended none. One that ended another
     * program (a headless one, whose exception reached `fiber\run()` through its coroutine) is
     * not thrown by a program after it.
     */
    private static function rethrow(?Component $app = null): void
    {
        if (self::$failure === null) {
            return;
        }
        [$e, $root] = self::$failure;
        $ended = $root?->get();
        if ($root !== null && $ended !== $app) {
            if ($ended === null || !$ended->isMounted()) {
                self::$failure = null;      // (another program's, ended: not this one's)
            }
            return;
        }
        self::$failure = null;
        throw $e;
    }

    /**
     * The size to run headless at, when the program was started with `--headless=COLSxROWS`
     * (or the environment has `TUI_HEADLESS=COLSxROWS`), with `@SECONDS` to run that long
     * first (one by default): [cols, rows, seconds]; null when neither says so.
     *
     * @return array{int, int, float}|null
     */
    private static function headlessSize(): ?array
    {
        $spec = null;
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (\is_string($arg) && str_starts_with($arg, '--headless=')) {
                $spec = substr($arg, 11);
            }
        }
        $spec ??= getenv('TUI_HEADLESS') ?: null;
        if ($spec === null) {
            return null;
        }
        if (!preg_match('/^(\d+)x(\d+)(?:@(\d+(?:\.\d+)?))?$/', $spec, $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            throw new \InvalidArgumentException("Headless: '$spec' is not COLSxROWS or COLSxROWS@SECONDS (as in --headless=80x24 or TUI_HEADLESS=80x24@2)");
        }
        return [(int) $m[1], (int) $m[2], isset($m[3]) ? (float) $m[3] : 1.0];
    }

    /** @param array<string, mixed> $keys */
    private static function start(Element|\Closure $root, TerminalDevice $device, ?string $theme = null, array $keys = []): Component
    {
        if ($theme !== null) {
            Stylesheet::theme($theme);      // (an unknown one fails before the terminal is taken over)
        }
        Component::normalizeBindings($keys);      // (a mistake fails before the terminal is taken over)
        self::rethrow();                          // (a coroutine of the program failed already)
        if ($root instanceof \Closure) {
            $live = new Live($root);
            $reads = State::reads();
            $first = Component::readAs($live, $root);
            if ($first !== null && !$first instanceof Element) {
                throw new \UnexpectedValueException('Tui: the closure must return an Element or a Component, got ' . get_debug_type($first));
            }
            // a closure that read no state never renders again: the component it made is the root
            if ($first instanceof Component && State::reads() === $reads) {
                $app = $first;
            } else {
                $live->prime($first);
                $app = $live;
            }
        } else {
            $app = $root instanceof Component ? $root : new Live($root);
        }
        $app->quitOn(self::QUIT);
        if ($keys !== []) {
            $app->bind($keys, first: true);
        }
        if ($theme !== null) {
            $app->setTheme($theme);
        }
        return $app->start($device);
    }
}
