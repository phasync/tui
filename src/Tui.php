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
 * another time), prints the screen and ends (the root quits, as `Tui::quit()` does):
 * `php examples/dashboard.php --headless=100x30`.
 *
 * **The program is the `fiber\run()` it is in.** `Tui::run()` and `Tui::inline()` called inside
 * your `fiber\run()` run the screen there: the coroutines you start with `fiber\go()` before or
 * beside it are the program's, and when the screen ends (its quit key, `Tui::quit()`, the root
 * disposed) the whole run is cancelled (`fiber\cancel(fiber\frame())`): every wait they are in
 * throws `fiber\Cancelled`, which ends them (or is caught, to clean up without waiting), and
 * `fiber\run()` returns. Called from `{main}`, they make a `fiber\run()` of their own. They return
 * the root component, ended (read what it collected from it); the code after the call runs
 * until its next wait. **Quitting** is the program's own: the root quits when it is disposed
 * (`$this->dispose()` in the root, `$root->dispose()` from anywhere, `Tui::quit()`), and a key
 * does it when the program binds one, `keys: ['ctrl+q' => ['quit', 'quit']]` (listed by `Help`
 * as "quit") or a component's `bindings()` to `'quit'`. The library binds no key of its own.
 * **Theme**: `theme:` is `'dark'`, `'light'` or `'mono'` (see `Stylesheet::theme()`); without it the
 * program is drawn in the theme the person running it asked for with `--theme=NAME` or
 * `TUI_THEME=NAME` (`run()` and `inline()`, with or without `--headless`), else in the theme for
 * the terminal's background (it is asked when the program starts: `light` on a light terminal,
 * `dark` on a dark one and when it does not say). A stylesheet of the program's (`$root->setStylesheet(Stylesheet::default()->with([...]))`)
 * refines the theme in force.
 * **Failing**: an exception anywhere in the program that no error boundary contains (the
 * root's hooks, handlers or coroutines, the closure given as the root, the handlers given with
 * `keys:` and to elements, a coroutine of the run that nobody awaits: `fiber\on_failure()`)
 * ends the program: the tree is disposed, the terminal restored, the run cancelled, and the
 * exception comes out of `fiber\run()`, readable on the restored terminal, as it would without a
 * terminal UI. A coroutine of a component (`$this->go()` in a class) is contained by the
 * component's error boundary instead: the component shows the error in its rectangle and the
 * rest of the program goes on. Needs an adapter with cancellation (`fiber\cancel()`); on one
 * without, the screen still ends but the program's other coroutines are left running.
 */
final class Tui
{
    /**
     * Wait for the next frame of the running program: returns once the frame has been drawn
     * and sent to the terminal, with the seconds since the frame before it (the `dt` of a game
     * loop). A frame comes at the frame rate (`fps:` of `Tui::run()`) also when nothing changed,
     * so a loop paced by it runs once per frame the terminal really gets: as fast as a fast
     * terminal takes frames, and no faster than a slow one (a Sixel terminal decoding a large
     * picture), where sleeping a fixed time would run ahead of what is shown.
     *
     *     fiber\go(function () use ($game, $s) {
     *         while (true) {
     *             $game->update(Tui::nextFrame());    // one step per frame, by the real time between them
     *             $s->tick++;                         // (what the canvas reads: it draws in the next frame)
     *         }
     *     });
     *
     * When the program ends, the run is cancelled and the wait throws `fiber\Cancelled`, as every
     * wait of the program's coroutines does. Without a running program it sleeps a sixtieth of
     * a second and returns that.
     */
    public static function nextFrame(): float
    {
        $device = Component::runningRoot()?->device();
        if ($device === null) {
            $t = microtime(true);
            \fiber\sleep(1 / 60);
            return microtime(true) - $t;
        }
        return $device->nextFrame();
    }

    /**
     * End the running program: the root is disposed, the terminal given back,
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
     * Inline mode: keep what $component shows now above the live region, in the terminal's
     * scrollback, never to change again (a finished job's progress row), as
     * `TerminalDevice::freeze()` does with its surface; the program then leaves it out of its
     * next render. The instance a render made anew (`new ProgressBar(...)` in a closure) is
     * frozen as the one running in its place. Nothing happens for a component that is not on a
     * terminal.
     *
     *     fiber\go(function () use ($s, $bar, $i) {
     *         ... $s->done[$i] = true;
     *         Tui::freeze($bar);      // its row stays as it is now, above the region
     *     });
     */
    public static function freeze(Component $component): void
    {
        $running = $component->running() ?? $component;
        $running->device()?->freeze($running->surface);
    }

    /**
     * Run $root (an element, a component, or a closure returning either) full screen on the
     * terminal (`Tty`) until it quits, then restore the terminal. Returns the root component,
     * ended.
     *
     * @param Element|(\Closure(): ?Element) $root
     * @param string|null $theme `'dark'`, `'light'`, `'mono'`; null: the one asked with `--theme=` or `TUI_THEME`, else the one for the terminal's background
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys keys bound on the root (see the class description)
     * @param int $fps the most frames a second drawn (see `TerminalDevice::setFrameRate()`)
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function run(Element|\Closure $root, ?string $theme = null, array $keys = [], int $fps = 60): Component
    {
        return self::starting(fn() => self::until(self::headlessSize() === null ? new Tty() : null, $root, $theme ?? self::themeAsked(), $keys, false, $fps));
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
    public static function inline(Element|\Closure $root, bool $keep = true, ?string $theme = null, array $keys = [], int $fps = 60): Component
    {
        return self::starting(fn() => self::until(self::headlessSize() === null ? Tty::inline(keep: $keep) : null, $root, $theme ?? self::themeAsked(), $keys, true, $fps));
    }

    /**
     * Start $root (as for `run()`) on a new `FakeTerminal` of $cols x $rows (in inline mode with
     * $inline, see `FakeTerminal::inline()`) and return the terminal: for tests and headless
     * programs, inside your own `fiber\run()`, which is the program's run as for `run()`: its
     * end cancels that run (a test that goes on after the program ended, or runs several, puts
     * each in a nested `fiber\run()`). The root quits as under `run()` (`keys:` binds its quit
     * key); drive it with the terminal's `press()`, `type()`, `settle()` and read it with `text()`.
     * `$terminal->detach()` ends it. A failure of the program comes out of your `fiber\run()`.
     *
     *     fiber\run(function () {
     *         $terminal = Tui::headless(new App(), 60, 20, keys: ['ctrl+q' => 'quit']);
     *         $terminal->press('ctrl+q');
     *     });
     *
     * @param Element|(\Closure(): ?Element) $root
     * @param string|null $theme      `'dark'`, `'light'`, `'mono'`; null: the one for the terminal's background (`TUI_THEME` is for `run()` and `inline()`)
     * @param string|null $background what the terminal says its background is: `'dark'`, `'light'`, null for nothing (see `FakeTerminal`)
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys as for `run()`
     * @param int    $fps    as for `run()`
     * @param string $images the image protocol the terminal has: `'none'`, `'kitty'`, `'iterm2'`, `'sixel'` (see `FakeTerminal`)
     */
    public static function headless(Element|\Closure $root, int $cols = 80, int $rows = 24, bool $inline = false, ?string $theme = null, ?string $background = 'dark', array $keys = [], int $fps = 60, string $images = 'none'): FakeTerminal
    {
        $terminal = $inline ? FakeTerminal::inline($cols, $rows, background: $background, images: $images) : new FakeTerminal($cols, $rows, background: $background, images: $images);
        $terminal->setFrameRate($fps);
        // (not owned: the caller's failure ends the program, see Runtime::watch())
        $app = self::starting(fn() => self::start($root, $terminal, $theme, $keys));
        \fiber\go(static fn() => self::serve($app, $terminal));
        return $terminal;
    }

    /**
     * Run $runner, which starts a program; when it fails before the program runs (a wrong
     * theme, a bad `--headless=`, no terminal), the run the program would have been is cancelled
     * as when a program ends, so the coroutines started for it do not keep `fiber\run()` waiting.
     *
     * @template T
     * @param \Closure(): T $runner
     * @return T
     */
    private static function starting(\Closure $runner): mixed
    {
        try {
            return $runner();
        } catch (\Throwable $e) {
            if (self::inRun()) {
                try {
                    \fiber\cancel(\fiber\frame());
                } catch (\fiber\NotSupportedException) {
                }
            }
            throw $e;
        }
    }

    /**
     * The program is on its device: wait for its end, then end its run. A failure of a coroutine
     * of the run that nobody awaits ends the program (the exception is rethrown by the run, once
     * everything has unwound); the end of the program cancels the run, so every coroutine of it
     * stops and the run returns. On an adapter without cancellation the coroutines run on.
     */
    private static function serve(Component $app, TerminalDevice $device): void
    {
        \fiber\on_failure(static fn() => $app->dispose());
        try {
            $device->wait();
        } finally {
            $app->dispose();
            try {
                \fiber\cancel(\fiber\frame());
            } catch (\fiber\NotSupportedException) {
            }
        }
    }

    /** True inside a `fiber\run()`: the program runs there; else it gets one of its own. */
    private static function inRun(): bool
    {
        try {
            \fiber\frame();
            return true;
        } catch (\fiber\NotInRunException) {
            return false;
        }
    }

    /**
     * The device for $device null: headless (see headlessSize()), the program runs on a
     * `FakeTerminal` for a moment, its screen is printed, and it ends.
     *
     * @param array<string, mixed> $keys
     */
    private static function until(?TerminalDevice $device, Element|\Closure $root, ?string $theme, array $keys, bool $inline = false, int $fps = 60): Component
    {
        $device?->setFrameRate($fps);
        $program = static function () use ($device, $root, $theme, $keys, $inline, $fps): Component {
            // (this coroutine waits for the program and disposes it whatever happens: it owns it)
            if ($device === null) {
                [$cols, $rows, $seconds] = self::headlessSize();
                $terminal = $inline ? FakeTerminal::inline($cols, $rows) : new FakeTerminal($cols, $rows);
                $terminal->setFrameRate($fps);
                $app = Internal\Runtime::owned(fn() => self::start($root, $terminal, $theme, $keys));
                \fiber\on_failure(static fn() => $app->dispose());
                try {
                    \fiber\sleep($seconds);
                    $terminal->settle();
                    $screen = $inline ? '' : $terminal->text();
                } finally {
                    $app->dispose();
                    try {
                        \fiber\cancel(\fiber\frame());
                    } catch (\fiber\NotSupportedException) {
                    }
                }
                echo rtrim($inline ? $terminal->frozen() : $screen), "\n";
                return $app;
            }
            $app = Internal\Runtime::owned(fn() => self::start($root, $device, $theme, $keys));
            self::serve($app, $device);
            return $app;
        };
        // inside a run(): the program is that run; from {main}: a run of its own
        return self::inRun() ? $program() : \fiber\run($program);
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

    /**
     * The theme the person running the program asked for, when the program gives none:
     * `--theme=NAME` on the command line or `TUI_THEME=NAME` in the environment (`dark`,
     * `light`, `mono`); null when neither says. For a screenshot of a program in another theme
     * (`TUI_THEME=light php examples/dashboard.php --headless=100x30`).
     */
    private static function themeAsked(): ?string
    {
        $theme = null;
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (\is_string($arg) && str_starts_with($arg, '--theme=')) {
                $theme = substr($arg, 8);
            }
        }
        return $theme ?? (getenv('TUI_THEME') ?: null);
    }

    /** @param array<string, mixed> $keys */
    private static function start(Element|\Closure $root, TerminalDevice $device, ?string $theme = null, array $keys = []): Component
    {
        if ($theme !== null) {
            Stylesheet::theme($theme);      // (an unknown one fails before the terminal is taken over)
        }
        Component::normalizeBindings($keys);      // (a mistake fails before the terminal is taken over)
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
        if ($keys !== []) {
            $app->bind($keys, first: true);
        }
        if ($theme !== null) {
            $app->setTheme($theme);
        }
        $app->start($device);
        return $app;
    }
}
