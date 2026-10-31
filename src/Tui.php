<?php

namespace phasync\Tui;

/**
 * The runner: start a root component on the terminal, run it until it quits, give the terminal
 * back. The shortest whole program:
 *
 *     Tui::run(new App());               // full screen until the app quits (Ctrl-Q by default)
 *     Tui::inline(new Progress());       // as a live region under the shell's output
 *
 * Each call wraps the program in its own `fiber\run()`, so it is all a script needs (it also
 * works inside a `fiber\run()` of yours). It returns when the root component has ended, with
 * the component (read what it collected from it). **Quitting**: the root quits when it is
 * disposed (`$this->dispose()` in the root, `$root->dispose()` from anywhere) or when Ctrl-Q
 * reaches it and no component on the way binds Ctrl-Q (a `Help` element lists it as "quit").
 * **Failing**: an exception anywhere in the program that no error boundary contains (the
 * root's hooks, handlers or coroutines, or the closure that makes the root) ends the program:
 * the tree is disposed, the terminal restored, and the exception comes out of the call, as it
 * would without a terminal UI.
 *
 * `$root` is a component, or a closure that makes one (called inside the run, for a component
 * that starts coroutines or reads the terminal in its constructor). For tests and headless
 * programs, `Tui::headless()` starts the same root on a `FakeTerminal`.
 */
final class Tui
{
    /** The key that quits a program run by `Tui` when nothing binds it. */
    public const QUIT = 'ctrl+q';

    /**
     * Run $root full screen on the terminal (`Tty`) until it quits, then restore the terminal.
     * Returns the root component, ended.
     *
     * @template T of Component
     * @param T|(\Closure(): T) $root
     * @return T
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function run(Component|\Closure $root): Component
    {
        return self::until(new Tty(), $root);
    }

    /**
     * Run $root in inline mode (see `TerminalDevice`): a live region at the bottom of the
     * terminal's normal screen, the rows it scrolls out going into the terminal's scrollback.
     * The last frame stays on the terminal afterwards, or is erased with $keep false. Returns
     * the root component, ended.
     *
     * @template T of Component
     * @param T|(\Closure(): T) $root
     * @return T
     * @throws \RuntimeException when STDIN or STDOUT is not a terminal
     */
    public static function inline(Component|\Closure $root, bool $keep = true): Component
    {
        return self::until(Tty::inline(keep: $keep), $root);
    }

    /**
     * Start $root on a new `FakeTerminal` of $cols x $rows and return the terminal: for tests
     * and headless programs, inside your own `fiber\run()`. The root quits as under `run()`
     * (Ctrl-Q included); drive it with the terminal's `press()`, `type()`, `settle()` and read
     * it with `text()`. `$terminal->detach()` ends it.
     *
     *     fiber\run(function () {
     *         $terminal = Tui::headless(new App(), 60, 20);
     *         $terminal->press('ctrl+q');
     *     });
     *
     * @param Component|(\Closure(): Component) $root
     */
    public static function headless(Component|\Closure $root, int $cols = 80, int $rows = 24): FakeTerminal
    {
        $terminal = new FakeTerminal($cols, $rows);
        self::start($root, $terminal);
        return $terminal;
    }

    private static function until(TerminalDevice $device, Component|\Closure $root): Component
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

    private static function start(Component|\Closure $root, TerminalDevice $device): Component
    {
        $app = $root instanceof \Closure ? $root() : $root;
        if (!$app instanceof Component) {
            throw new \UnexpectedValueException('Tui: the closure must return a Component, got ' . get_debug_type($app));
        }
        $app->quitOn(self::QUIT);
        return $app->start($device);
    }
}
