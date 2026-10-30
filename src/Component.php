<?php

namespace phasync\Tui;

use fiber\Deferred;
use fiber\TimeoutException;
use phasync\Tui\Internal\Signal;

use function fiber\await_all;

/**
 * A piece of user interface: a Surface of its own, a lifecycle, a coroutine, child components
 * and event handlers. Extend it and override the hooks you need:
 *
 *     final class Clock extends Component
 *     {
 *         protected function render(): ?Layout
 *         {
 *             $this->surface->write(Ansi::clearScreen() . Ansi::moveTo(0, 0) . date('H:i:s'));
 *             return null;                          // drew itself
 *         }
 *
 *         protected function run(): void
 *         {
 *             while ($this->sleep(1)) {             // false once disposed
 *                 $this->requestRender();
 *             }
 *         }
 *     }
 *
 *     fiber\run(function () {
 *         $clock = (new Clock())->start();
 *         $tty = new Tty();
 *         $tty->attach($clock->surface);            // the root component's surface is the root
 *         ...
 *     });
 *
 * **Lifecycle.** `start()` mounts a root component (a child is started by its parent's
 * `child()`): it gets its surface, `mount()` runs, the first `render()` follows, and `run()`
 * starts as a coroutine. `dispose()` ends it: children are disposed first, the `stopped()`
 * awaitable resolves and `sleep()` returns false so coroutines can end, disposal waits up to a
 * second for them, then `unmount()` runs. Coroutines are not cancelled from outside: write loops
 * as `while ($this->sleep(...))` or check `isMounted()`.
 *
 * **Rendering.** `render()` either draws into `$this->surface` with escape sequences (the
 * surface is a terminal; see `Ansi`) and returns null, or returns a layout of child components'
 * surfaces. It runs after `mount()`, after every resize, and after `requestRender()` (calls in
 * the same moment are batched into one render). Children come from `child($key, $create)`: the
 * same key gives the same component on every render; after a render that returned a layout,
 * children it did not ask for are disposed.
 *
 *     protected function render(): ?Layout
 *     {
 *         return Split::vertical()
 *             ->add($this->child('log', fn() => new Log())->surface)
 *             ->add($this->child('input', fn() => new TextInput())->surface, 1);
 *     }
 *
 * **Events.** `onKey()`, `onMouse()`, `onPaste()` and `onFocus()` receive the events that reach
 * the component's surface (see `Surface::dispatch()`): a component sees them before its focused
 * child does, and returning true keeps them from going further. `onResize()` is told the new size.
 *
 * **Errors.** An exception in a child's hooks, handlers or coroutines is contained to the child:
 * it is disposed and its rectangle shows the error, the rest of the program keeps running. An
 * exception in the root component is not caught (it ends `fiber\run()`, and `Tty` restores the
 * terminal).
 */
abstract class Component
{
    /** The component's surface: draw into it, or give it a layout of children. Set by `start()`. */
    public readonly Surface $surface;

    private ?Component $parent = null;
    /** @var array<string, Component> */
    private array $children = [];
    /** @var array<string, true>|null keys asked for by the render in progress */
    private ?array $used = null;
    private bool $mounted = false;
    private bool $renderPending = false;
    private ?Component $focusLater = null;
    private ?Deferred $stopped = null;
    private ?Signal $wake = null;
    /** @var array<int, \stdClass> running coroutines: {fiber, awaitable, done} */
    private array $tasks = [];
    private ?\Throwable $error = null;
    /** @var list<\Closure> */
    private array $offs = [];

    // ------------------------------------------------------------------------------------------
    // Hooks

    /** Called once when the component is mounted, before the first render: set up state here. */
    protected function mount(): void
    {
    }

    /**
     * Draw the component: write into `$this->surface` and return null, or return a layout of
     * child components' surfaces (see `child()`).
     */
    protected function render(): ?Layout
    {
        return null;
    }

    /** The component's coroutine, started after the first render. Return when `sleep()` says false. */
    protected function run(): void
    {
    }

    /** Called once when the component has been disposed and its coroutines have ended. */
    protected function unmount(): void
    {
    }

    /** A key reached the component's surface; return true if handled (it goes no further). */
    protected function onKey(Key $key): bool
    {
        return false;
    }

    /** A mouse event in the component's coordinates; return true if handled. */
    protected function onMouse(Mouse $mouse): bool
    {
        return false;
    }

    /** Text was pasted while the component is on the focus path; return true if handled. */
    protected function onPaste(Paste $paste): bool
    {
        return false;
    }

    /** The component gained or lost the focus (or the terminal did, while it has it). */
    protected function onFocus(Focus $focus): void
    {
    }

    /** The component's surface was resized (a render follows by itself). */
    protected function onResize(Resize $size): void
    {
    }

    // ------------------------------------------------------------------------------------------
    // Lifecycle

    /**
     * Mount this component as a root: on $surface (for example one you attach to a terminal),
     * or a new 80x24 surface that a terminal device or a layout will size. Must be called inside
     * `fiber\run()`. Child components are started by `child()`, not by this.
     *
     * @throws \LogicException when already started
     */
    public function start(?Surface $surface = null): static
    {
        if ($this->mounted || $this->stopped !== null) {
            throw new \LogicException(static::class . ' was already started');
        }
        $this->surface = $surface ?? new Surface();
        $this->mounted = true;
        $this->stopped = new Deferred();
        $this->wake = new Signal();
        $s = $this->surface;
        $this->offs = [
            $s->on('key', fn(Key $e) => $this->handle(fn() => $this->onKey($e))),
            $s->on('mouse', fn(Mouse $e) => $this->handle(fn() => $this->onMouse($e))),
            $s->on('paste', fn(Paste $e) => $this->handle(fn() => $this->onPaste($e))),
            $s->on('focus', function (Focus $e): void {
                $this->handle(fn() => $this->onFocus($e));
                $this->requestRender();
            }),
            $s->on('resize', function (Resize $e): void {
                $this->handle(fn() => $this->onResize($e));
                $this->requestRender();
            }),
        ];
        $this->guard(function (): void {
            $this->mount();
            $this->renderNow();
            $this->go($this->run(...));
        });
        return $this;
    }

    /**
     * End the component: dispose its children, resolve `stopped()`, wake `sleep()`, wait up to
     * $timeout seconds for its coroutines to return, then call `unmount()`. Its surface keeps
     * its last content. Safe to call twice. Call it on the root when the program ends; children
     * are disposed by their parent.
     */
    public function dispose(float $timeout = 1.0): void
    {
        if (!$this->mounted) {
            return;
        }
        $this->mounted = false;
        foreach (array_reverse($this->children) as $child) {
            $child->dispose($timeout);
        }
        $this->children = [];
        foreach ($this->offs as $off) {
            $off();
        }
        $this->offs = [];
        $this->stopped->settle(true, null);
        $this->wake->notify();
        // wait for the coroutines, except the one calling dispose() (it cannot wait for itself)
        $self = \Fiber::getCurrent();
        $others = [];
        foreach ($this->tasks as $entry) {
            if ($entry->fiber !== $self) {
                $others[] = $entry->awaitable;
            }
        }
        if ($others !== []) {
            try {
                await_all($others, $timeout);
            } catch (TimeoutException) {
                // a coroutine that does not listen keeps running; it is the component's to fix
            } catch (\Throwable) {
                // errors of coroutines were already handled by their guard
            }
        }
        $this->guard($this->unmount(...));
    }

    /** True between `start()` and `dispose()`. */
    public function isMounted(): bool
    {
        return $this->mounted;
    }

    /**
     * An awaitable that resolves when the component is disposed, for `fiber\await_any([$work,
     * $component->stopped()])` around a wait that should end with the component. In loops,
     * prefer `sleep()`.
     */
    public function stopped(): object
    {
        if ($this->stopped === null) {
            throw new \LogicException(static::class . ' has not been started');
        }
        return $this->stopped;
    }

    /** The component this one is a child of, or null for a root. */
    public function parent(): ?Component
    {
        return $this->parent;
    }

    /** The exception that ended this component, if one did. */
    public function error(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * Ask for a render. Calls made in the same moment are batched into one render, which runs
     * as soon as the current coroutine yields. Does nothing once disposed.
     */
    public function requestRender(): void
    {
        if (!$this->mounted || $this->renderPending) {
            return;
        }
        $this->renderPending = true;
        \fiber\go(function (): void {
            \fiber\sleep(0);
            $this->renderPending = false;
            if ($this->mounted) {
                $this->guard($this->renderNow(...));
            }
        });
    }

    /**
     * Give the focus to this component: every host from the root down to it focuses the way to
     * it, so keys and pastes come here. Called before the parent shows it (in the parent's
     * `render()`), it takes effect when the layout `render()` returns is applied.
     */
    public function focus(): void
    {
        $component = $this;
        while ($component->parent !== null) {
            $host = $component->parent->surface;
            if ($host->viewport($component->surface) !== null) {
                $host->focus($component->surface);
            } else {
                // not shown yet (asked for in render()): focus it once the parent's layout shows it
                $component->parent->focusLater = $this;
            }
            $component = $component->parent;
        }
    }

    /** True if keys go to this component: it is on the focus path from the root. */
    public function hasFocus(): bool
    {
        $component = $this;
        while ($component->parent !== null) {
            if ($component->parent->surface->focused() !== $component->surface) {
                return false;
            }
            $component = $component->parent;
        }
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // For subclasses

    /**
     * The child component under $key, made with $create (and started) the first time it is
     * asked for. Use its `surface` in the layout `render()` returns.
     *
     * @template T of Component
     * @param \Closure(): T $create
     * @return T
     */
    protected function child(string $key, \Closure $create): Component
    {
        if ($this->used !== null) {
            $this->used[$key] = true;
        }
        if (isset($this->children[$key])) {
            return $this->children[$key];
        }
        $child = $create();
        if (!$child instanceof Component) {
            throw new \UnexpectedValueException("child('$key') must create a Component");
        }
        $child->parent = $this;
        $this->children[$key] = $child;
        $child->start();
        return $child;
    }

    /**
     * Start another coroutine that belongs to the component: an exception in it is contained
     * like one in `run()`, and disposal waits for it. Make it end when `sleep()` returns false
     * or `isMounted()` is false.
     */
    protected function go(\Closure $task): void
    {
        $entry = new \stdClass();
        $entry->fiber = null;
        $entry->done = false;
        $entry->awaitable = \fiber\go(function () use ($task, $entry): void {
            $entry->fiber = \Fiber::getCurrent();
            try {
                $this->guard($task);
            } finally {
                $entry->done = true;
                unset($this->tasks[spl_object_id($entry)]);
            }
        });
        if (!$entry->done) {
            $this->tasks[spl_object_id($entry)] = $entry;
        }
    }

    /**
     * Sleep up to $seconds, less if the component is disposed meanwhile; returns `isMounted()`.
     * The loop of a component's coroutine: `while ($this->sleep(0.5)) { ... }`.
     */
    protected function sleep(float $seconds): bool
    {
        if ($this->mounted) {
            $this->wake->wait($seconds);
        }
        return $this->mounted;
    }

    // ------------------------------------------------------------------------------------------

    private function renderNow(): void
    {
        $this->used = [];
        try {
            $layout = $this->render();
            $used = $this->used;
        } finally {
            $this->used = null;
        }
        if ($layout !== null) {
            $this->surface->setLayout($layout);
            if ($this->focusLater !== null) {
                $later = $this->focusLater;
                $this->focusLater = null;
                $later->focus();
            }
            foreach ($this->children as $key => $child) {
                if (!isset($used[$key])) {
                    unset($this->children[$key]);
                    $child->dispose();
                }
            }
        }
    }

    /** Run an event handler; true when it handled the event. */
    private function handle(\Closure $handler): bool
    {
        if (!$this->mounted) {
            return false;
        }
        return $this->guard($handler) === true;
    }

    /**
     * Run $code; an exception ends this component and shows in its rectangle, unless it is the
     * root, where it is rethrown.
     */
    private function guard(\Closure $code): mixed
    {
        try {
            return $code();
        } catch (\Throwable $e) {
            if ($this->parent === null) {
                throw $e;
            }
            $this->fail($e);
            return null;
        }
    }

    private function fail(\Throwable $e): void
    {
        if ($this->error !== null) {
            return;
        }
        $this->error = $e;
        $s = $this->surface;
        foreach ($s->viewports() as $v) {
            $s->unhost($v->source);
        }
        $s->setLayout(null);
        $this->showError();
        $s->on('resize', fn() => $this->showError());
        if ($this->mounted) {
            \fiber\go(fn() => $this->dispose());
        }
    }

    /** Draw the error that ended the component into its rectangle. */
    private function showError(): void
    {
        $s = $this->surface;
        $message = $this->error::class . ': ' . $this->error->getMessage();
        $out = "\e[0m\e[?25l\e[2J";
        $y = 0;
        foreach (explode("\n", wordwrap($message, max(1, $s->cols() - 2), "\n", true)) as $line) {
            if ($y >= $s->rows()) {
                break;
            }
            $out .= Ansi::moveTo(0, $y++) . ($y === 1 ? "\e[1;31m✖ " : "\e[31m  ") . $line;
        }
        $s->write($out . "\e[0m");
    }
}
