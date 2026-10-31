<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Live;

/**
 * The runner: show a screen on the terminal, run it until it quits, give the terminal back. A
 * whole program, in one file and without a class:
 *
 *     $s = new State(['done' => 0]);
 *     fiber\run(function () use ($s) {
 *         Tui::go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { Tui::sleep(0.05); $s->done = $i; } });
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
 * program is drawn in the theme the person running it asked for with `--theme=NAME` or
 * `TUI_THEME=NAME` (`run()` and `inline()`, with or without `--headless`), else in the theme for
 * the terminal's background (it is asked when the program starts: `light` on a light terminal,
 * `dark` on a dark one and when it does not say). A stylesheet of the program's (`$root->setStylesheet(Stylesheet::default()->with([...]))`)
 * refines the theme in force.
 * **Failing**: an exception anywhere in the program that no error boundary contains (the
 * root's hooks, handlers or coroutines, the closure given as the root, the handlers given with
 * `keys:` and to elements, a coroutine started with `Tui::go()`) ends the program: the tree is
 * disposed, the terminal restored, and the exception comes out of the call, as it would
 * without a terminal UI. Start the program's own coroutines with `Tui::go()`, not
 * `fiber\go()`, and let them wait with `Tui::sleep()`: they stop when the program ends (see
 * `go()`).
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

    /** @var \WeakMap<object, Internal\Job>|null the coroutines of go(), by their task's fiber\current() */
    private static ?\WeakMap $jobs = null;
    /** @var list<Internal\Job> coroutines of go() started while no program ran: the next program's */
    private static array $unclaimed = [];

    /**
     * Start a coroutine of the program, as `fiber\go()` does, except that it belongs to the
     * program (the one running, or the next one to start when none runs yet: start your jobs,
     * then call `Tui::run()`):
     *
     * - **It stops when the program ends.** Wait in it with `Tui::sleep()`: once the program has
     *   ended (Ctrl-Q, `Tui::quit()`), the sleep throws `Quit` at once, which ends the coroutine
     *   (or is caught, to clean up). So `fiber\run()` returns when the screen is gone, without a
     *   flag of yours. (`fiber\sleep()` cannot be cut short: fiber-interop/api has no
     *   cancellation yet. A coroutine that waits with it runs on until it returns, and
     *   `fiber\run()` waits for it.)
     * - **Its exception ends the program** at once: the screen is disposed, the terminal
     *   restored, and the exception comes out of `Tui::run()` (and so out of your
     *   `fiber\run()`), readable on the restored terminal.
     *
     *     fiber\run(function () use ($s) {
     *         Tui::go(function () use ($s) {
     *             while (true) { Tui::sleep(0.1); $s->done = fetchProgress(); }
     *         });
     *         Tui::run(fn() => new ProgressBar($s->done));     // Ctrl-Q: the loop ends with it
     *     });
     *
     * A coroutine started with `fiber\go()` itself is not seen by the library: an exception in
     * it is kept by `fiber\run()` until everything inside it has ended (the screen too), and
     * only then thrown, and it is not stopped (end it with a flag the program clears when
     * `Tui::run()` returns). A coroutine of a component (`$this->go()` in a class) is contained
     * by the component's error boundary instead: the component shows the error in its rectangle
     * and the rest of the program goes on, and it ends with the component.
     */
    public static function go(\Closure $task): void
    {
        $job = new Internal\Job();
        $root = Component::runningRoot();
        if ($root !== null) {
            self::claim($job, $root);
        } else {
            self::$unclaimed[] = $job;
        }
        self::$jobs ??= new \WeakMap();
        \fiber\go(static function () use ($task, $job): void {
            // which job Tui::sleep() is asked from: known by the task's own fiber\current()
            // (on a runtime that tells it only after the task's first suspension, after one)
            if (\fiber\current() === null) {
                \fiber\sleep(0);
            }
            $me = \fiber\current();
            if ($me !== null) {
                self::$jobs[$me] = $job;
            }
            try {
                $task();
            } catch (Quit) {
                // (its program ended: the coroutine's end)
            } catch (\Throwable $e) {
                $root = $job->program?->get() ?? Component::runningRoot();
                self::$failure ??= [$e, $root === null ? null : \WeakReference::create($root)];
                $root?->dispose();
                throw $e;
            }
        });
    }

    /**
     * Sleep $seconds, as `fiber\sleep()` does, but end at once when the program ends. In a
     * coroutine started with `Tui::go()` it ends with a `Quit` exception, which `Tui::go()`
     * takes as the coroutine's end, so the coroutine stops with its program (see there); one
     * whose program has ended gets `Quit` from every call. Elsewhere (the code that started a
     * `Tui::headless()` program, a handler, a coroutine of `fiber\go()`), where nothing would
     * take the exception, it returns early when the program running at the call ends during
     * the sleep, and sleeps plainly when none runs.
     *
     * @throws Quit in a `Tui::go()` coroutine, when its program has ended
     */
    public static function sleep(float $seconds): void
    {
        $me = \fiber\current();
        $job = $me === null ? null : (self::$jobs[$me] ?? null);
        if ($job === null) {
            // (not a Tui::go() coroutine: nothing takes a Quit here, so the sleep ends early)
            $root = Component::runningRoot();
            if ($root === null) {
                \fiber\sleep($seconds);
                return;
            }
            $wait = new Internal\Job();
            self::claim($wait, $root);
            if (!$wait->ended) {
                $wait->wake->wait($seconds);
            }
            return;
        }
        if (!$job->ended) {
            $job->wake->wait($seconds);
        }
        if ($job->ended) {
            throw new Quit();
        }
    }

    /** $job belongs to the program $root: it ends when the root is disposed. */
    private static function claim(Internal\Job $job, Component $root): void
    {
        $job->program = \WeakReference::create($root);
        if (!$root->isMounted()) {
            $job->end();
            return;
        }
        $root->stopped()->then(static fn() => $job->end());
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
     * Inline mode: keep what $component shows now above the live region, in the terminal's
     * scrollback, never to change again (a finished job's progress row), as
     * `TerminalDevice::freeze()` does with its surface; the program then leaves it out of its
     * next render. The instance a render made anew (`new ProgressBar(...)` in a closure) is
     * frozen as the one running in its place. Nothing happens for a component that is not on a
     * terminal.
     *
     *     Tui::go(function () use ($s, $bar, $i) {
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
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function run(Element|\Closure $root, ?string $theme = null, array $keys = []): Component
    {
        return self::starting(fn() => self::until(self::headlessSize() === null ? new Tty() : null, $root, $theme ?? self::themeAsked(), $keys));
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
        return self::starting(fn() => self::until(self::headlessSize() === null ? Tty::inline(keep: $keep) : null, $root, $theme ?? self::themeAsked(), $keys, true));
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
     * @param string|null $theme      `'dark'`, `'light'`, `'mono'`; null: the one for the terminal's background (`TUI_THEME` is for `run()` and `inline()`)
     * @param string|null $background what the terminal says its background is: `'dark'`, `'light'`, null for nothing (see `FakeTerminal`)
     * @param array<string, \Closure|string|array<int|string, mixed>> $keys as for `run()`
     */
    public static function headless(Element|\Closure $root, int $cols = 80, int $rows = 24, bool $inline = false, ?string $theme = null, ?string $background = 'dark', array $keys = []): FakeTerminal
    {
        $terminal = $inline ? FakeTerminal::inline($cols, $rows, background: $background) : new FakeTerminal($cols, $rows, background: $background);
        self::starting(fn() => self::start($root, $terminal, $theme, $keys));
        return $terminal;
    }

    /**
     * Run $runner, a runner starting a program; when it fails before the program started (a
     * wrong theme, a bad `--headless=`, no terminal), the coroutines of go() waiting for that
     * program end with `Quit` as when it ends, so they do not keep `fiber\run()` waiting.
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
            [$jobs, self::$unclaimed] = [self::$unclaimed, []];
            foreach ($jobs as $job) {
                $job->end();
            }
            throw $e;
        }
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
            // (this coroutine waits for the program and disposes it whatever happens: it owns it)
            if ($device === null) {
                [$cols, $rows, $seconds] = self::headlessSize();
                $terminal = $inline ? FakeTerminal::inline($cols, $rows) : new FakeTerminal($cols, $rows);
                $app = Internal\Runtime::owned(fn() => self::start($root, $terminal, $theme, $keys));
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
            $app = Internal\Runtime::owned(fn() => self::start($root, $device, $theme, $keys));
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
        if ($root !== null) {
            $ended = $root->get();
            if ($app === null || $ended !== $app) {
                if ($ended === null || !$ended->isMounted()) {
                    self::$failure = null;      // (another program's, ended: not this one's)
                }
                return;
            }
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
        $app->start($device);
        // the coroutines of go() started before it are this program's
        [$jobs, self::$unclaimed] = [self::$unclaimed, []];
        foreach ($jobs as $job) {
            self::claim($job, $app);
        }
        return $app;
    }
}
