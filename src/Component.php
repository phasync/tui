<?php

namespace phasync\Tui;

use fiber\Deferred;
use fiber\TimeoutException;
use phasync\Tui\Internal\Signal;

use function fiber\await;
use function fiber\await_all;
use function fiber\current;

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
 *         (new Clock())->start(new Tty());          // until the terminal is detached
 *     });
 *
 * **Lifecycle.** `start()` mounts a root component (a child is started by its parent's
 * `child()`): it gets its surface and `mount()` runs. Once it has its real size, `sized()` runs,
 * then the first `render()`, then `run()` starts as a coroutine. A root has its size at once (a
 * terminal device's, or that of the surface you give it); a child has it when its parent's
 * render has placed it in a layout, so a child no render shows waits for the render that does.
 * The rule for component authors: **`mount()` sets up props and state; work that depends on the
 * rectangle (starting a program that reads its terminal size once, laying out text for the
 * width) starts in `sized()`**. `dispose()` ends it: children are disposed first, the
 * `stopped()` awaitable resolves and `sleep()` returns false so coroutines can end, and
 * `unmount()` runs when the coroutines have returned and the children have ended (a second at
 * the latest); `dispose()` returns after that, except when called from one of the component's
 * own coroutines (see `dispose()`). Coroutines are not cancelled from outside: write loops as
 * `while ($this->sleep(...))` or check `isMounted()`.
 *
 * **Ending.** A component is disposed by its parent, by `dispose()`, or when its surface is
 * closed (`Surface::close()`): detaching the terminal device closes the root, so quitting (a key
 * handler calling `$tty->detach()`) disposes the whole tree, and every `Terminal` in it hangs its
 * program up. Disposing the root component closes its surface, which detaches the device.
 * `fiber\run()` returns once the device is detached and every coroutine has ended.
 *
 * **Rendering.** `render()` either draws into `$this->surface` with escape sequences (the
 * surface is a terminal; see `Ansi`) and returns null, or returns a layout of child components'
 * surfaces. It runs after `sized()`, again whenever the component's area changed (its size, or
 * where it shows on the screen, `$this->surface->area()`: the terminal resized, a layout moved
 * or resized it, a window it is in moved), and after `requestRender()` (calls in the same moment
 * are batched into one render). So a render that reads its own rectangle is never stale. Children come from `child($key, $create)`: the
 * same key gives the same component on every render; after a render that returned a layout,
 * children it did not ask for are disposed. A child renders for the first time when the layout
 * that shows it has given it its size, so it never draws for a size it does not have.
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
 * **Showing another component's state.** A component whose `render()` reads the state of
 * another (a pane title showing which lines of a `Log` are in view) listens to that component's
 * events with `on()` and asks for a render; the built-ins say which events they emit (`Log` and
 * `ListView`: `scroll`):
 *
 *     $log = $this->child('log', function () {
 *         $log = new Log();
 *         $log->on('scroll', fn() => $this->requestRender());
 *         return $log;
 *     });
 *     // in render(): 'lines ' . ($log->topLine() + 1) . '-' . ($log->bottomLine() + 1)
 *
 * **Errors.** An exception in a child's hooks, handlers or coroutines is contained to the child:
 * it is disposed with its children (their programs are hung up) and its rectangle shows the
 * error; the rest of the program keeps running. An exception in the root component ends the
 * program: the tree is disposed, the surface closed (so the terminal is detached and restored),
 * and the exception is rethrown, by `start()` when it happens there and else by `fiber\run()`.
 */
abstract class Component
{
    /** The component's surface: draw into it, or give it a layout of children. Set by `start()`. */
    public readonly Surface $surface;

    private ?Component $parent = null;
    /**
     * @var array<string, Component> by key: the children this component owns, and components
     * of another owner it shows (a container given an existing component)
     */
    private array $children = [];
    /** @var list<Component> children disposed that have not ended yet: the end waits for them */
    private array $ending = [];
    /** @var array<string, true>|null keys asked for by the render in progress */
    private ?array $used = null;
    private bool $mounted = false;
    private bool $rendered = false;
    private bool $renderPending = false;
    private ?Deferred $stopped = null;
    /** Resolves when the component has ended: disposed, coroutines and children ended, unmounted. */
    private ?Deferred $ended = null;
    /** After disposal: resolves when the last coroutine has returned. */
    private ?Deferred $idle = null;
    private ?Signal $wake = null;
    /** @var array<int, ?object> running coroutines by number: the handle `fiber\go()` gave each */
    private array $tasks = [];
    private int $taskCount = 0;
    private ?\Throwable $error = null;
    /** @var list<\Closure> */
    private array $offs = [];
    /** @var array<string, array<int, \Closure>> listeners of the component's events, by event */
    private array $listeners = [];
    /** @var \WeakMap<Surface, Component>|null the component on each surface that has one */
    private static ?\WeakMap $onSurface = null;

    // ------------------------------------------------------------------------------------------
    // Hooks

    /**
     * Called once when the component is mounted: set up props and state here, create children.
     * The surface does not have its real size yet (a child is placed by its parent's next render).
     */
    protected function mount(): void
    {
    }

    /**
     * Called once when the component has its real size (`$this->surface->cols()`/`rows()`), right
     * before the first render: start what depends on the rectangle here, such as a program that
     * reads its terminal size when it starts. Later changes of the area (size or place) are each
     * followed by a render, a change of size by `onResize()` first.
     */
    protected function sized(): void
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

    /**
     * The component's surface was resized. A render follows by itself (as it does after any
     * change of the component's area, a move included), so draw in `render()`, not here.
     */
    protected function onResize(Resize $size): void
    {
    }

    // ------------------------------------------------------------------------------------------
    // Lifecycle

    /**
     * Mount this component as the root of a program:
     *
     * - on a terminal device (`new Tty()`, or a `FakeTerminal` in tests): a new surface is
     *   attached to it, so the first render is for the terminal's size, and detaching the device
     *   disposes the component;
     * - on $surface, a surface of yours (one you host somewhere, or attach a device to);
     * - with no argument, on a new 80x24 surface that you attach or host afterwards (it renders
     *   again at the size it gets).
     *
     * Must be called inside `fiber\run()`. Child components are started by `child()`, not by this.
     * An exception in `mount()` or the first `render()` disposes what was started and is rethrown.
     *
     * @throws \LogicException when already started
     */
    public function start(Surface|TerminalDevice|null $on = null): static
    {
        if ($this->mounted || $this->stopped !== null) {
            throw new \LogicException(static::class . ' was already started');
        }
        if ($on instanceof TerminalDevice) {
            $surface = new Surface();
            $on->attach($surface);
        } else {
            $surface = $on ?? new Surface();
        }
        $this->surface = $surface;
        self::$onSurface ??= new \WeakMap();
        self::$onSurface[$surface] = $this;
        $this->mounted = true;
        $this->stopped = new Deferred();
        $this->ended = new Deferred();
        $this->wake = new Signal();
        $s = $this->surface;
        $this->offs = [
            $s->on('key', fn(Key $e) => $this->handle(fn() => $this->onKey($e)) || $this->toUnshown($e)),
            $s->on('mouse', fn(Mouse $e) => $this->handle(fn() => $this->onMouse($e))),
            $s->on('paste', fn(Paste $e) => $this->handle(fn() => $this->onPaste($e)) || $this->toUnshown($e)),
            $s->on('focus', function (Focus $e): void {
                $this->handle(fn() => $this->onFocus($e));
                $this->requestRender();
            }),
            $s->on('resize', function (Resize $e): void {
                $this->handle(fn() => $this->onResize($e));
                $this->requestRender();
            }),
            // what render() draws may depend on where the component is: draw again when it moved
            $s->on('area', fn() => $this->requestRender()),
            $s->on('close', fn() => $this->stop()),
        ];
        $this->guard($this->mount(...));
        if ($this->parent === null) {
            $this->firstRender();
        }
        // a child is sized and rendered by its parent's render that shows it
        return $this;
    }

    /** Sized, the first render, then the coroutine; once. */
    private function firstRender(): void
    {
        if ($this->rendered || !$this->mounted) {
            return;
        }
        $this->rendered = true;
        $this->guard(function (): void {
            $this->sized();
            $this->renderNow();
            $this->go($this->run(...));
        });
    }

    /**
     * End the component: dispose its children (a `Terminal` hangs its program up), resolve
     * `stopped()` and wake `sleep()`, so its coroutines know they are to end, and wait for its
     * end: `unmount()` runs when its last coroutine has returned and its children have ended
     * (each the same way), or after $timeout seconds at the latest if a coroutine does not
     * listen. When `dispose()` returns, `unmount()` has run. For a root, the surface is closed
     * first, which detaches a terminal device attached to it. The surface keeps its last
     * content. Safe to call twice; children are disposed by their parent.
     *
     * Called from one of the component's own coroutines (its `run()`, a `go()`, a `Terminal`'s
     * `$onExit`) or from one of a descendant's (a child quitting the program by disposing the
     * root), it cannot wait for the coroutine it is called from: it signals as above and
     * returns, the coroutine goes on with `isMounted()` false and `sleep()` returning false, and
     * `unmount()` runs once it has returned. So return soon after, and do not wait in it for the
     * component's end. The same happens where the runtime cannot say which coroutine is running
     * (`fiber\current()` is null, as outside `fiber\run()`). `fiber\run()` returns only after the
     * end either way.
     */
    public function dispose(float $timeout = 1.0): void
    {
        if (!$this->mounted) {
            return;
        }
        $me = current();
        $fromInside = $me === null || $this->runs($me);
        $this->stop($timeout);
        if (!$fromInside) {
            await($this->ended);    // bounded: the end comes $timeout after stop() at the latest
        }
    }

    /** True if $task is one of the coroutines of this component or of a descendant. */
    private function runs(object $task): bool
    {
        if (\in_array($task, $this->tasks, true)) {
            return true;
        }
        foreach ([...$this->owned(), ...$this->ending] as $child) {
            if ($child->runs($task)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signal the end to the component and its subtree, and arrange it: the end (end()) runs when
     * the coroutines and the children have ended, at once when nothing is pending, else in a
     * coroutine that waits for them, $timeout at most.
     */
    private function stop(float $timeout = 1.0): void
    {
        if (!$this->mounted) {
            return;
        }
        $this->mounted = false;
        foreach ($this->offs as $off) {
            $off();
        }
        $this->offs = [];
        if ($this->parent === null) {
            // the program ends: give the terminal back at once
            $this->surface->close();
        } else {
            // no longer the parent's child (child() makes a new one), but the parent's end waits for it
            $key = array_search($this, $this->parent->children, true);
            if ($key !== false) {
                unset($this->parent->children[$key]);
            }
            $this->parent->ending[] = $this;
        }
        foreach (array_reverse($this->owned()) as $child) {
            $child->stop($timeout);
        }
        $this->children = [];     // (those of another owner stay theirs)
        $this->stopped->resolve();
        $this->wake->notify();
        // the end: once the coroutines (told to stop just now) and the children have ended
        $waits = [];
        if ($this->tasks !== []) {
            $this->idle = new Deferred();
            $waits[] = $this->idle;
        }
        foreach ($this->ending as $child) {
            $waits[] = $child->ended;
        }
        if ($waits === []) {
            $this->end();
            return;
        }
        \fiber\go(function () use ($waits, $timeout): void {
            try {
                await_all($waits, $timeout);
            } catch (TimeoutException) {
                // a coroutine that does not listen keeps running; it is the component's to fix
            }
            $this->end();
        });
    }

    /** @return list<Component> the children this component owns (not those it only shows) */
    private function owned(): array
    {
        return array_values(array_filter($this->children, fn(Component $c) => $c->parent === $this));
    }

    /** The end of a disposed component: unmount(), then its parent may end. */
    private function end(): void
    {
        try {
            $this->guard($this->unmount(...));
        } finally {
            $this->ended->resolve();
            if ($this->parent !== null) {
                $this->parent->ending = array_values(array_filter($this->parent->ending, fn(Component $c) => $c !== $this));
            }
        }
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
     * Listen to an event of this component: $listener is called with what the component passes
     * (the built-ins pass themselves) every time it emits $event. Returns a function that removes
     * the listener. Which events there are is up to the component; the built-ins document theirs
     * (`Log` and `ListView` emit `scroll` when the part of their content in view changed). The
     * use: a component that shows another's state in its `render()` listens and calls
     * `requestRender()`. Input events are not component events: override `onKey()` and the
     * other hooks, or listen on `$component->surface`.
     *
     * @param \Closure(mixed ...$data): mixed $listener
     */
    public function on(string $event, \Closure $listener): \Closure
    {
        $id = spl_object_id($listener);
        $this->listeners[$event][$id] = $listener;
        return function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);
        };
    }

    /**
     * Call the listeners of $event with $data (see `on()`): how a component tells others that
     * something they may show changed. Document the events a component emits.
     */
    protected function emit(string $event, mixed ...$data): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$data);
        }
    }

    /** True if something listens to $event: skip work that only a listener would need. */
    protected function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /**
     * Ask for a render. Calls made in the same moment are batched into one render, which runs
     * as soon as the current coroutine yields. Does nothing once disposed.
     */
    public function requestRender(): void
    {
        if (!$this->mounted || $this->renderPending || !$this->rendered) {
            return;     // (before the first render: that one is coming)
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
     * Give the focus to this component: keys and pastes come here, and on to the descendant it
     * focused (a dialog that focused its text field in `mount()` passes them to the field).
     * Every host from the root down focuses the way to it, through any hosts in between,
     * components or plain surfaces (see `Surface::focus()`). The latest `focus()` wins, and the
     * focus stays with it through every re-render.
     *
     * It takes effect at once, for the very next key, also when the component is not shown yet
     * (a dialog created and focused in a key handler, shown by the render that follows): until a
     * render shows it, keys reach it through its nearest shown ancestor, exactly as if it were
     * on the focus path, and go on from it to the same descendant they will reach once it is
     * shown. Nothing needs repeating in `render()`.
     */
    public function focus(): void
    {
        // not shown yet: the nearest ancestor that is joins the focus path and passes keys down
        $c = $this;
        while ($c->parent !== null && $c->surface->viewport() === null) {
            $c = $c->parent;
        }
        if ($c !== $this) {
            $c->surface->focus();
        }
        $this->surface->focus();
    }

    /**
     * True if keys go to this component (or through it to a descendant): it is on the focus path
     * from the root, also while it is not shown yet (see `focus()`). It reads the very path keys
     * take, so it is true exactly when the next key would reach the component.
     */
    public function hasFocus(): bool
    {
        if (!$this->mounted) {
            return false;
        }
        // keys come down from the root of the tree the nearest shown component is in
        $c = $this;
        while ($c->surface->viewport() === null && $c->parent !== null) {
            $c = $c->parent;
        }
        // and take the way dispatch() gives them: at a component, to the child not shown yet it
        // passes them to (see toUnshown()), else to the window its surface focuses
        for ($s = $c->surface->root(); $s !== null; $s = $next?->surface ?? $s->focused()) {
            if ($s === $this->surface) {
                return true;
            }
            $on = self::$onSurface[$s] ?? null;
            $next = $on !== null && $on->mounted ? $on->unshownFocus() : null;
        }
        return false;
    }

    /**
     * The child not shown yet that keys reaching this component go on to: the one whose subtree
     * holds a focus request later than any in the windows this surface shows (and than a
     * `takeFocus()` of the surface). Before this component's first render, when nothing below
     * it was focused, its first child: the window its host would focus first.
     */
    private function unshownFocus(): ?Component
    {
        $best = null;
        $bestRank = 0;
        $first = null;
        foreach ($this->children as $child) {
            if (!$child->mounted || $child->surface->viewport() !== null) {
                continue;
            }
            $first ??= $child;
            $rank = $child->focusRank();
            if ($rank > $bestRank) {
                $best = $child;
                $bestRank = $rank;
            }
        }
        if ($best === null) {
            return $this->rendered || $this->surface->latestFocusBelow() > 0 ? null : $first;
        }
        return $bestRank > $this->surface->latestFocusBelow() ? $best : null;
    }

    /** The latest focus request in this component's subtree, children not shown yet included. */
    private function focusRank(): int
    {
        $rank = $this->surface->latestFocus();
        foreach ($this->children as $child) {
            if ($child->mounted && $child->surface->viewport() === null) {
                $rank = max($rank, $child->focusRank());
            }
        }
        return $rank;
    }

    /** Pass a key or paste on to the focused child not shown yet; false when there is none. */
    private function toUnshown(Key|Paste $event): bool
    {
        $child = $this->unshownFocus();
        if ($child === null) {
            return false;
        }
        $child->surface->dispatch($event);
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // For subclasses

    /**
     * The child component under $key, made with $create (and started) the first time it is
     * asked for. Use its `surface` in the layout `render()` returns. Without $create, the child
     * of that key if there is one, else null: to reach a child from a handler
     * (`$this->child('dialog')?->dispose()`) or to show it only while it exists. A child that
     * was disposed is gone at once: `child($key)` gives null, and with $create a new one.
     *
     * After a render that returned a layout, the children it neither asked for (either way) nor
     * shows are disposed; a child whose surface the layout shows is kept however it got there
     * (`->add($this->dialog->surface)` keeps the dialog as `->add($this->child('dialog')->surface)`
     * does).
     *
     * $create may also return a component that is started already, typically another
     * component's child given to a container (`new Box($this->child('log', fn() => new Log()))`,
     * whose `mount()` calls `child('content', fn() => $this->content)`): it is shown here and
     * gets keys through here, but stays its owner's. This component does not dispose it, and its
     * end does not wait for it.
     *
     * @template T of Component
     * @param (\Closure(): T)|null $create
     * @return ($create is null ? Component|null : T)
     */
    protected function child(string $key, ?\Closure $create = null): ?Component
    {
        if ($this->used !== null) {
            $this->used[$key] = true;
        }
        if (isset($this->children[$key])) {
            if ($this->children[$key]->mounted) {
                return $this->children[$key];
            }
            unset($this->children[$key]);     // one of another owner's, disposed by it
        }
        if ($create === null) {
            return null;
        }
        $child = $create();
        if (!$child instanceof Component) {
            throw new \UnexpectedValueException("child('$key') must create a Component");
        }
        if ($child->stopped !== null) {
            if (!$child->mounted) {
                throw new \LogicException("child('$key'): " . $child::class . ' was disposed');
            }
            // started already (by its owner): shown here, and its owner's still
            $this->children[$key] = $child;
            return $child;
        }
        $child->parent = $this;
        $this->children[$key] = $child;
        $child->start();
        return $child;
    }

    /**
     * Start another coroutine that belongs to the component: an exception in it is contained
     * like one in `run()`, and the component's end (`unmount()`) waits for it. Make it end when
     * `sleep()` returns false or `isMounted()` is false.
     */
    protected function go(\Closure $task): void
    {
        $id = ++$this->taskCount;
        $this->tasks[$id] = null;
        $handle = \fiber\go(function () use ($id, $task): void {
            // known here already when the runtime starts the task before go() returns
            $this->tasks[$id] ??= current();
            try {
                $this->guard($task);
            } finally {
                unset($this->tasks[$id]);
                if ($this->tasks === [] && $this->idle !== null) {
                    $this->idle->resolve();
                }
            }
        });
        if (\array_key_exists($id, $this->tasks)) {
            $this->tasks[$id] ??= $handle;
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
        }
        // children shown for the first time draw now, at the size the layout gave them
        foreach ($this->children as $child) {
            if (!$child->rendered && $child->surface->viewport() !== null) {
                $child->firstRender();
            }
        }
        if ($layout !== null) {
            foreach ($this->children as $key => $child) {
                if (!isset($used[$key]) && !$child->isShownIn($this->surface)) {
                    if ($child->parent === $this) {
                        $child->stop();     // a render does not wait for its coroutines
                    } else {
                        unset($this->children[$key]);     // another owner's: no longer shown here
                    }
                }
            }
        }
    }

    /** True if this component's surface shows on $surface, directly or through windows in between. */
    private function isShownIn(Surface $surface): bool
    {
        for ($s = $this->surface->parent(); $s !== null; $s = $s->parent()) {
            if ($s === $surface) {
                return true;
            }
        }
        return false;
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
     * root: then the program ends (the tree is disposed, the surface closed) and it is rethrown.
     */
    private function guard(\Closure $code): mixed
    {
        try {
            return $code();
        } catch (\Throwable $e) {
            if ($this->parent === null) {
                $this->error ??= $e;
                $this->dispose();
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
        // its children end with it; whoever ran into the failure does not wait for the end
        $this->stop();
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
