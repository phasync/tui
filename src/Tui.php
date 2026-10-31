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
 * with no class and no `requestRender()`. It has no keys of its own. A closure that reads no
 * state is called once: the component it returns is the root itself (`fn() => new App()`, for a
 * component that starts coroutines or reads the terminal in its constructor).
 *
 * `Tui::run()` takes the terminal over (full screen), `Tui::inline()` shows the screen as a live
 * region under the shell's output, `Tui::headless()` on a `FakeTerminal` for tests and headless
 * programs. Each call wraps the program in its own `fiber\run()` (it also works inside one of
 * yours, which a program that starts coroutines with `fiber\go()` needs: `fiber\go()` must be
 * called inside `fiber\run()`). It returns when the root has ended, with the root component
 * (read what it collected from it). **Quitting**: the root quits when it is disposed
 * (`$this->dispose()` in the root, `$root->dispose()` from anywhere) or when Ctrl-Q reaches it
 * and no component on the way binds Ctrl-Q (a `Help` element lists it as "quit").
 * **Failing**: an exception anywhere in the program that no error boundary contains (the
 * root's hooks, handlers or coroutines, the closure given as the root) ends the program: the
 * tree is disposed, the terminal restored, and the exception comes out of the call, as it
 * would without a terminal UI.
 */
final class Tui
{
    /** The key that quits a program run by `Tui` when nothing binds it. */
    public const QUIT = 'ctrl+q';

    /**
     * Run $root (an element, a component, or a closure returning either) full screen on the
     * terminal (`Tty`) until it quits, then restore the terminal. Returns the root component,
     * ended.
     *
     * @param Element|(\Closure(): ?Element) $root
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function run(Element|\Closure $root): Component
    {
        return self::until(new Tty(), $root);
    }

    /**
     * Run $root (as for `run()`) in inline mode (see `TerminalDevice`): a live region at the
     * bottom of the terminal's normal screen, as tall as what it shows (at most the terminal's
     * height), the rows it scrolls out going into the terminal's scrollback. The last frame
     * stays on the terminal afterwards, or is erased with $keep false. Returns the root
     * component, ended.
     *
     * @param Element|(\Closure(): ?Element) $root
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function inline(Element|\Closure $root, bool $keep = true): Component
    {
        return self::until(Tty::inline(keep: $keep), $root);
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
     */
    public static function headless(Element|\Closure $root, int $cols = 80, int $rows = 24, bool $inline = false): FakeTerminal
    {
        $terminal = $inline ? FakeTerminal::inline($cols, $rows) : new FakeTerminal($cols, $rows);
        self::start($root, $terminal);
        return $terminal;
    }

    private static function until(TerminalDevice $device, Element|\Closure $root): Component
    {
        return \fiber\run(static function () use ($device, $root): Component {
            $app = self::start($root, $device);
            try {
                $device->wait();
            } finally {
                $app->dispose();
            }
            return $app;
        });
    }

    private static function start(Element|\Closure $root, TerminalDevice $device): Component
    {
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
        return $app->start($device);
    }
}
