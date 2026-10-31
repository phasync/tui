<?php

namespace phasync\Tui;

use fiber\Deferred;
use fiber\TimeoutException;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Element\Layers;
use phasync\Tui\Internal\Runtime;
use phasync\Tui\Internal\Signal;
use phasync\Tui\Internal\Tree;

use function fiber\await;
use function fiber\await_all;
use function fiber\current;

/**
 * A piece of user interface: a Surface of its own, a lifecycle, a coroutine, child components
 * and event handlers. Extend it and override the hooks you need:
 *
 *     final class Clock extends Component
 *     {
 *         protected function render(): Element
 *         {
 *             return new Text(date('H:i:s'), style: ['bold' => true]);
 *         }
 *
 *         protected function run(): void
 *         {
 *             while ($this->sleep(1)) {             // false once disposed; each step renders
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
 * **Rendering.** `render()` returns an element tree (`Column`, `Row`, `Layers`, `Box`, `Text`,
 * `Help` and components, see `Element`), or a layout of child components' surfaces, or draws
 * into `$this->surface` with escape sequences (see `Ansi`) and returns null. Children come
 * from `child($key, $create)` (the same key gives the same component on every render) or are
 * placed in the tree with `new` (matched with the one at the same place, or of the same `key`,
 * which keeps its state and takes the new props). After a render, children it neither asked
 * for nor shows are disposed. A child renders for the first time when the layout that shows
 * it has given it its size, so it never draws for a size it does not have.
 *
 *     protected function render(): Element
 *     {
 *         return new Column([
 *             new Box($this->child('log', fn() => new Log()), 'log'),
 *             $this->child('input', fn() => new Editor(maxLines: 1)),
 *         ]);
 *     }
 *
 * **When it renders: by itself.** A component renders again in the next frame after its state
 * changed (`$this->state`, see `State`), after one of its handlers took an event (`onKey()`
 * and the others returning true, a bound key), after a step of one of its coroutines (each
 * `sleep()`, and the end of a `go()` that waited), after a callback or listener it made ran (a
 * closure of the component given to a child: `onSubmit`, `on('scroll', ...)`), when a parent's
 * render gave it new props, and when its area changed (size or place). Everything that happens
 * in one moment is one render, parents before their children. There are no signals or
 * dependency tracking: computed values are methods that `render()` calls. `requestRender()`
 * is for the rest (a change the component cannot see, such as data outside it).
 *
 * **Style.** Elements and components take `class:`, `id:` and `style:`; the stylesheet in force
 * (`setStylesheet()` on the root, default `Stylesheet::default()`) gives each its colours,
 * border and padding; `style()` gives a component that draws itself its own and its parts'.
 *
 * **Keys.** `bindings()` maps keys to public methods (`['ctrl+s' => 'save']`); the
 * application changes them with `bind()`, and a `Help` element lists them. A key goes down the
 * focus path: each component's `onKey()` on the way sees it first (the root's first); at the
 * component it ends at (the focused one) its own bindings come first, then its `onKey()`, then
 * the bindings of its parents, nearest first. A key nobody takes goes to the focused surface's
 * program (a `Terminal`'s shell). So a text field types the letters an application binds, and
 * an application's Ctrl-Q works wherever the focus is.
 *
 * **Events.** `onKey()`, `onMouse()`, `onPaste()` and `onFocus()` receive the events that reach
 * the component's surface (see `Surface::dispatch()`): a component sees them before its focused
 * child does, and returning true keeps them from going further. `onResize()` is told the new size.
 * `dialog()` opens a modal `Dialog` over the component and gives its answer as an awaitable.
 *
 * **Showing another component's state.** A component whose `render()` reads the state of
 * another (a pane title showing which lines of a `Log` are in view) listens to that component's
 * events with `on()` and asks for a render; the built-ins say which events they emit (`Log` and
 * `ListView`: `scroll`):
 *
 *     $log = $this->child('log', function () {
 *         $log = new Log();
 *         $log->on('scroll', fn() => $this->markDirty());
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
abstract class Component extends Element
{
    /** The component's surface: draw into it, or give it a layout of children. Set by `start()`. */
    public readonly Surface $surface;

    /**
     * The component's state (see `State`): every write renders the component again. Assign
     * one of your own class in the constructor or `mount()` for typed properties
     * (`$this->state = new CounterState()`, with `@var CounterState` for your editor), or give
     * several components the same object to share it; a component that assigns none has a
     * plain `State` from `start()` on.
     */
    protected State $state;

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
    /** True while render() and the layout of what it returned run: its own state writes render nothing. */
    private bool $rendering = false;
    private ?Tree $tree = null;
    private ?Stylesheet $stylesheet = null;
    /** The style a parent's element tree gives this component to inherit. */
    private ?ComputedStyle $inherited = null;
    /** @var array{array{int,int}, ?array}|null the room a parent's layout measured this component in, and the answer */
    private ?array $measured = null;
    /** @var array<string, mixed> bindings of this instance over its class's (`bind()`) */
    private array $bound = [];
    /** @var array<string, array<string, mixed>> bindings for components of a class in the subtree */
    private array $boundFor = [];
    /** @var array<string, array{Dialog, ?Surface}> open dialogs by child key, with the surface focused before */
    private array $dialogs = [];
    private static int $dialogCount = 0;
    /** The component whose render() runs now (focus() remembers what renders ask for). */
    private static ?Component $inRender = null;
    /** @var array<int, true> the components this component's last render focused, and the one in progress */
    private array $focusAsked = [];
    private array $focusAskedBefore = [];
    /** The coming render was asked for with requestRender(): its focus() calls take effect again. */
    private bool $asked = false;
    /** @var array<int, Component> components to render in the coming frame */
    private static array $dirty = [];
    /** @var \WeakMap<Component, true>|null components whose last render showed a `Help` element */
    private static ?\WeakMap $helpShown = null;
    /** @var \WeakMap<Component, bool>|null components with `:hover` rules, and whether the mouse was over them */
    private static ?\WeakMap $hoverAware = null;
    /** @var array{\WeakReference<Surface>, int, int}|null where the mouse was last: the root and the cell on it */
    private static ?array $pointer = null;

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
     * Draw the component. Three ways, by what it returns:
     *
     * - an element tree (`Column`, `Row`, `Layers`, `Box`, `Text`, `Help`, components): the
     *   library lays it out into the surface and paints it (see `Element`);
     * - a layout (`Split`, `Stack`) of child components' surfaces;
     * - null, after drawing into `$this->surface` with escape sequences (see `Ansi`).
     *
     * It may also draw into the surface and return a tree or a layout: what it draws shows
     * where no window covers it. It runs after `sized()`, after every change of the area, and
     * in the next frame after the state or the props changed or a handler of the component ran
     * (see the class description); it should only read, not change, what it shows.
     */
    protected function render(): Layout|Element|null
    {
        return null;
    }

    /**
     * Called when a render of the parent placed this component anew with other props: a
     * component element made with `new` in the parent's tree (`new Counter(label: 'A')`) is
     * matched with the one shown there before, which keeps its state and takes the new one's
     * props (its public properties) and attributes; this runs after they were taken, before the
     * render that follows. Derive state from the new props here.
     */
    protected function propsChanged(): void
    {
    }

    /** The component's coroutine, started after the first render. Return when `sleep()` says false. */
    protected function run(): void
    {
    }

    /** Called once when the component has been disposed and its coroutines have ended. */
    protected function unmount(): void
    {
    }

    /**
     * A key reached the component's surface; return true if handled (it goes no further, and the
     * component renders again). Keys that are actions are better declared in `bindings()`.
     */
    protected function onKey(Key $key): bool
    {
        return false;
    }

    /** A mouse event in the component's coordinates; return true if handled (a render follows). */
    protected function onMouse(Mouse $mouse): bool
    {
        return false;
    }

    /** Text was pasted while the component is on the focus path; return true if handled (a render follows). */
    protected function onPaste(Paste $paste): bool
    {
        return false;
    }

    /**
     * The keys of this component and the public methods they run: `['ctrl+s' => ['save', 'Save'],
     * 'enter' => 'submit']`, a key in `Key::parse()` notation and a method name, or `[method,
     * description]` for a key that `Help` lists. The application changes them with `bind()`. A bound method runs when the key
     * reaches the component (see the class description on the order); one that returns false
     * did not take the key, which goes on. A render follows.
     *
     * @return array<string, string|array{string, string}>
     */
    protected function bindings(): array
    {
        return [];
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
        Runtime::check();
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
        if (!isset($this->state)) {
            $this->state = new State();
        }
        $this->state->watchFor($this);
        $s = $this->surface;
        $this->offs = [
            $s->on('key', fn(Key $e) => $this->key($e)),
            $s->on('mouse', fn(Mouse $e) => $this->pointer($e) || $this->modal($e) || $this->handle(fn() => $this->onMouse($e))),
            $s->on('paste', fn(Paste $e) => ($this->dialogs === [] && !$this->attribute('disabled') && $this->handle(fn() => $this->onPaste($e))) || $this->toUnshown($e)),
            $s->on('focus', function (Focus $e): void {
                $this->handle(fn() => $this->onFocus($e));
                $this->markDirty();
                // a Help element shows the keys of the focused component
                foreach (self::$helpShown ?? [] as $component => $_) {
                    $component->markDirty();
                }
            }),
            $s->on('resize', function (Resize $e): void {
                $this->handle(fn() => $this->onResize($e));
                $this->markDirty();
            }),
            // what render() draws may depend on where the component is: draw again when it moved
            $s->on('area', fn() => $this->markDirty()),
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
            self::touch($listener);
        }
    }

    /**
     * Call $callback with $args, if there is one: how a component calls the callbacks it was
     * given (`onSubmit`, `onSelect`). The component that made the callback (the closure's
     * `$this`) renders again afterwards, as after one of its own handlers. Returns what the
     * callback returned.
     */
    protected function call(?\Closure $callback, mixed ...$args): mixed
    {
        if ($callback === null) {
            return null;
        }
        try {
            return $callback(...$args);
        } finally {
            self::touch($callback);
        }
    }

    /** A closure of a component ran: that component renders again. */
    private static function touch(\Closure $fn): void
    {
        $that = (new \ReflectionFunction($fn))->getClosureThis();
        if ($that instanceof Component) {
            $that->markDirty();
        }
    }

    /** True if something listens to $event: skip work that only a listener would need. */
    protected function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /**
     * Ask for a render in the next frame. Rarely needed: a component renders again by itself
     * after its state changed, after its handlers and bound keys, after a step of its
     * coroutines and when a component it listens to emitted an event. Call it when what
     * `render()` shows changed some other way (a property of yours, data outside the
     * component). Calls in the same moment are one render; does nothing once disposed.
     */
    public function requestRender(): void
    {
        $this->asked = true;
        $this->markDirty();
    }

    /**
     * @internal A render in the next frame that the library makes by itself (a state write, a
     * handler, a coroutine step): as `requestRender()`, except that the `focus()` calls of the
     * render that follows take effect only where they are new (see `focus()`).
     */
    final public function markDirty(): void
    {
        if (!$this->mounted || $this->renderPending || !$this->rendered || $this->rendering) {
            return;     // (before the first render: that one is coming)
        }
        $this->renderPending = true;
        self::$dirty[spl_object_id($this)] = $this;
        \fiber\go(static function (): void {
            \fiber\sleep(0);
            self::renderFrame();
        });
    }

    /**
     * Render every component that asked for it, parents before their children (so a child
     * whose props a parent's render changed renders once), each at most once per frame.
     */
    private static function renderFrame(): void
    {
        $done = [];
        $later = [];
        $error = null;
        while (self::$dirty !== []) {
            $batch = self::$dirty;
            self::$dirty = [];
            uasort($batch, fn(Component $a, Component $b) => $a->depth() <=> $b->depth());
            foreach ($batch as $id => $component) {
                if (isset($done[$id])) {
                    $later[$id] = $component;   // asked again by a render of this frame: the next one
                    continue;
                }
                $done[$id] = true;
                $component->renderPending = false;
                if ($component->mounted) {
                    try {
                        $component->guard($component->renderNow(...));
                    } catch (\Throwable $e) {
                        $error ??= $e;      // (a root's failure: the others still render)
                    }
                }
            }
        }
        foreach ($later as $component) {
            $component->renderPending = false;
            $component->markDirty();
        }
        if ($error !== null) {
            throw $error;
        }
    }

    private function depth(): int
    {
        $n = 0;
        for ($c = $this->parent; $c !== null; $c = $c->parent) {
            $n++;
        }
        return $n;
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
     *
     * Called in `render()` (the input a screen starts in), it takes effect on the first render
     * that asks for it and on renders asked for with `requestRender()`, but not on the renders
     * the library makes by itself (after a key, a state write, a coroutine step): the user can
     * move the focus away, and the render after that key does not take it back. Prefer
     * `mount()` for the first focus.
     */
    public function focus(): void
    {
        if (($r = self::$inRender) !== null) {
            // asked for by a render: once, not again by every render that follows
            $id = spl_object_id($this);
            $r->focusAsked[$id] = true;
            if (isset($r->focusAskedBefore[$id])) {
                return;
            }
        }
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
     * `sleep()` returns false or `isMounted()` is false. When it ends after it waited (a fetch,
     * an awaited dialog), the component renders.
     */
    protected function go(\Closure $task): void
    {
        $id = ++$this->taskCount;
        $this->tasks[$id] = null;
        $started = false;
        $handle = \fiber\go(function () use ($id, $task, &$started): void {
            // known here already when the runtime starts the task before go() returns
            $this->tasks[$id] ??= current();
            try {
                $this->guard($task);
            } finally {
                if ($started) {
                    $this->markDirty();     // it waited and went on: what it did shows
                }
                unset($this->tasks[$id]);
                if ($this->tasks === [] && $this->idle !== null) {
                    $this->idle->resolve();
                }
            }
        });
        $started = true;
        if (\array_key_exists($id, $this->tasks)) {
            $this->tasks[$id] ??= $handle;
        }
    }

    /**
     * Sleep up to $seconds, less if the component is disposed meanwhile; returns `isMounted()`.
     * The loop of a component's coroutine: `while ($this->sleep(0.5)) { ... }`. What the
     * coroutine changed before it sleeps shows in the next frame (a render follows).
     */
    protected function sleep(float $seconds): bool
    {
        if ($this->mounted) {
            $this->markDirty();     // what the coroutine did until now shows in the next frame
            $this->wake->wait($seconds);
        }
        return $this->mounted;
    }

    // ------------------------------------------------------------------------------------------

    private function renderNow(): void
    {
        $this->used = [];
        $this->rendering = true;
        $outer = self::$inRender;
        self::$inRender = $this;
        $this->focusAskedBefore = $this->asked ? [] : $this->focusAsked;
        $this->focusAsked = [];
        $this->asked = false;
        try {
            $result = $this->render();
            $layout = $this->layoutOf($result);
            $used = $this->used;
        } finally {
            self::$inRender = $outer;
            $this->used = null;
            $this->rendering = false;
        }
        if ($layout !== null) {
            $this->surface->setLayout($layout);
        } elseif ($this->dialogs !== []) {
            $this->hostDialogs();
        }
        if ($this->measured !== null && $this->measure(...$this->measured[0]) !== $this->measured[1]) {
            $this->measured = null;
            $this->parent?->markDirty();     // it needs another size: the parent lays it out again
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

    /** The layout for what render() returned (an element tree laid out), with the open dialogs over it. */
    private function layoutOf(Layout|Element|null $result): ?Layout
    {
        foreach ($this->dialogs as $key => [$dialog]) {
            $this->child($key);         // (keeps them)
        }
        if ($result instanceof Element) {
            if ($this->dialogs !== []) {
                $result = new Layers([$result, ...array_column($this->dialogs, 0)]);
            }
            $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...));
            $layout = $this->tree->build($result, $this->stylesheet(), $this->style());
            self::$helpShown ??= new \WeakMap();
            if ($this->tree->showsHelp) {
                self::$helpShown[$this] = true;
            } else {
                unset(self::$helpShown[$this]);
            }
            return $layout;
        }
        if ($result instanceof Layout && $this->dialogs !== []) {
            $stack = (new Stack())->add($result);
            foreach ($this->dialogs as [$dialog]) {
                [$w, $h] = $this->dialogSize($dialog);
                $stack->add($dialog->surface, $w, $h);
            }
            $result = $stack;
        }
        if ($result instanceof Layout) {
            foreach ($this->dialogs as [$dialog]) {
                $this->frameDialog($dialog);
            }
        }
        return $result;
    }

    /** Dialogs over a component that draws itself: windows of its surface, centered. */
    private function hostDialogs(): void
    {
        $s = $this->surface;
        foreach ($this->dialogs as [$dialog]) {
            [$w, $h] = $this->dialogSize($dialog);
            $s->host($dialog->surface, intdiv($s->cols() - $w, 2), intdiv($s->rows() - $h, 2), $w, $h)->setFit(true);
            $s->raise($dialog->surface);
            $this->frameDialog($dialog);
        }
    }

    /** @return array{int,int} the outer size of $dialog's window */
    private function dialogSize(Dialog $dialog): array
    {
        $style = $dialog->style();
        $b = $style->border === false ? 0 : 2;
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        [$w, $h] = $dialog->measure(max(1, $cols - $b), max(1, $rows - $b)) ?? [$cols - $b, $rows - $b];
        return [min($cols, $w + $b), min($rows, $h + $b)];
    }

    private function frameDialog(Dialog $dialog): void
    {
        $style = $dialog->style();
        $view = $this->surface->viewport($dialog->surface);
        if ($view !== null && $style->border !== false) {
            $view->setBorder($style->border, $style->borderStyle, $style->borderStyle);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Element trees, props, styles

    /** The child for a component element made anew by a render: the one at its place, with the new props. */
    private function place(string $key, Component $fresh): Component
    {
        $existing = $this->child($key);
        if ($existing !== null && $existing::class === $fresh::class) {
            $existing->receive($fresh);
            return $existing;
        }
        if ($existing !== null) {
            $existing->stop();      // another kind of component at this place now
        }
        return $this->child($key, fn() => $fresh);
    }

    /** Take the props (public properties) and attributes of $fresh, a new instance of this class. */
    private function receive(Component $fresh): void
    {
        $changed = $this->adoptAttributes($fresh);
        foreach (self::props(static::class) as $name) {
            $value = $fresh->$name;
            if ($value !== $this->$name) {
                $this->$name = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $this->guard($this->propsChanged(...));
            $this->markDirty();
        }
    }

    /**
     * @return list<string> the props of $class: its public properties that a render can change
     * (not static, not readonly, declared below Component)
     */
    private static function props(string $class): array
    {
        static $props = [];
        if (!isset($props[$class])) {
            $props[$class] = [];
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if (!$p->isStatic() && !$p->isReadOnly() && !\in_array($p->getDeclaringClass()->getName(), [self::class, Element::class], true)) {
                    $props[$class][] = $p->getName();
                }
            }
        }
        return $props[$class];
    }

    /**
     * The stylesheet in force for this component: the one set on it with `setStylesheet()`,
     * else its parent's, up to the root; `Stylesheet::default()` when none was set.
     */
    public function stylesheet(): Stylesheet
    {
        return $this->stylesheet ?? $this->parent?->stylesheet() ?? Stylesheet::default();
    }

    /**
     * Use $stylesheet for this component and its descendants (set it on the root component for
     * the whole program; build it on the default theme with `Stylesheet::default()->with()`).
     * Everything renders again with it.
     */
    public function setStylesheet(Stylesheet $stylesheet): void
    {
        $this->stylesheet = $stylesheet;
        $this->renderSubtree();
    }

    private function renderSubtree(): void
    {
        $this->markDirty();
        foreach ($this->owned() as $child) {
            $child->renderSubtree();
        }
    }

    /**
     * What the stylesheet says about this component in its current states (`:focused`,
     * `:disabled`, `:hover`), or about one of its parts in the given part states: how a widget
     * that draws itself finds its colours. Each widget documents its parts:
     *
     *     $this->style()                       // the component: ->text, ->border, ->padding ...
     *     $this->style('row', 'current')       // the part `Select::row:current`
     */
    public function style(?string $part = null, string ...$states): ComputedStyle
    {
        $sheet = $this->stylesheet();
        $own = $this->states();
        $style = $sheet->compute($this, $own, $this->inherited ?? $this->parent?->style(), $part, array_values($states));
        if ($style->hoverAware && $this->mounted) {
            self::$hoverAware ??= new \WeakMap();
            if (!isset(self::$hoverAware[$this])) {
                // motion reports without a button held, so the mouse is followed (the device asks for them)
                $this->surface->write("\e[?1003h");
            }
            self::$hoverAware[$this] = \in_array('hover', $own, true);
        }
        return $style;
    }

    /**
     * @internal `measure()` for a parent's layout: the answer is remembered, so that when it
     * changes (an editor that grew a line) the parent lays its tree out again.
     *
     * @return array{int,int}|null
     */
    public function measureFor(int $cols, int $rows): ?array
    {
        $size = $this->measure($cols, $rows);
        $this->measured = [[$cols, $rows], $size];
        return $size;
    }

    /** @internal The style the parent's element tree around this component gives it to inherit. */
    public function inheritStyle(ComputedStyle $parent): void
    {
        if ($parent != $this->inherited) {
            $this->inherited = $parent;
            $this->markDirty();
        }
    }

    /**
     * A mouse event reached this component: remember where the mouse is, and render the
     * components whose `:hover` changed. Takes nothing (false).
     */
    private function pointer(Mouse $mouse): bool
    {
        $area = $this->surface->area();
        $root = $this->surface->root();
        $x = $area->x + $mouse->x;
        $y = $area->y + $mouse->y;
        if (self::$pointer !== null && self::$pointer[0]->get() === $root && self::$pointer[1] === $x && self::$pointer[2] === $y) {
            return false;
        }
        self::$pointer = [\WeakReference::create($root), $x, $y];
        foreach (self::$hoverAware ?? [] as $component => $was) {
            if ($component->isHovered() !== $was) {
                $component->markDirty();
            }
        }
        return false;
    }

    /** True if the mouse is over this component (as last reported). */
    private function isHovered(): bool
    {
        if (self::$pointer === null || !$this->mounted) {
            return false;
        }
        [$root, $x, $y] = self::$pointer;
        return $root->get() === $this->surface->root() && $this->surface->area()->contains($x, $y);
    }

    /** @return list<string> the states of this component for the stylesheet */
    private function states(): array
    {
        $states = [];
        if ($this->isHovered()) {
            $states[] = 'hover';
        }
        if ($this->mounted && $this->hasFocus()) {
            $states[] = 'focused';
        }
        if ($this->attribute('disabled')) {
            $states[] = 'disabled';
        }
        return $states;
    }

    // ------------------------------------------------------------------------------------------
    // Keys

    /**
     * A key on its way down the focus path. On the way, each component's `onKey()` may take it
     * (the root's first). At the component it ends at (nothing focused further down): its own
     * bindings, its `onKey()`, then its parents' bindings, nearest first. A component with an
     * open dialog passes keys on to it untouched.
     */
    private function key(Key $key): bool
    {
        if (!$this->mounted) {
            return false;
        }
        $mine = $this->dialogs === [] && !$this->attribute('disabled');
        if (!$this->isKeyEnd()) {
            return ($mine && $this->handle(fn() => $this->onKey($key))) || $this->toUnshown($key);
        }
        if ($mine && ($this->runBinding($key) || $this->handle(fn() => $this->onKey($key)))) {
            return true;
        }
        for ($c = $this->parent; $c !== null; $c = $c->parent) {
            if ($c->dialogs === [] && !$c->attribute('disabled') && $c->runBinding($key)) {
                return true;
            }
        }
        return false;
    }

    /** True if no other component is on the key path below this one. */
    private function isKeyEnd(): bool
    {
        if ($this->unshownFocus() !== null) {
            return false;
        }
        for ($s = $this->surface->focused(); $s !== null; $s = $s->focused()) {
            if ((self::$onSurface[$s] ?? null)?->mounted) {
                return false;
            }
        }
        return true;
    }

    /** Run the method bound to $key; true if it took the key. */
    private function runBinding(Key $key): bool
    {
        $method = $this->bindingsInForce()[(string) $key][0] ?? null;
        if ($method === null) {
            return false;
        }
        if (!method_exists($this, $method) || !(new \ReflectionMethod($this, $method))->isPublic()) {
            throw new \LogicException(static::class . ": the key '$key' is bound to '$method', which is not a public method");
        }
        if ($this->guard(fn() => $this->$method()) === false) {
            return false;
        }
        $this->markDirty();
        return true;
    }

    /**
     * Change the keys: without $for, of this component (over its class's `bindings()`); with a
     * class name, of every component of that class below this one, which is how an application
     * sets its keys for a widget (`$app->bind(['ctrl+enter' => 'submit'], Editor::class)`). A
     * method name or `[method, description]` binds a key, null unbinds it. The nearest setting
     * wins: an instance's own, then those of its nearest parent, up to the root. Returns $this.
     *
     * @param array<string, string|array{string, string}|null> $bindings
     */
    public function bind(array $bindings, ?string $for = null): static
    {
        $normal = self::normalize($bindings);
        if ($for === null) {
            $this->bound = [...$this->bound, ...$normal];
        } else {
            $this->boundFor[$for] = [...$this->boundFor[$for] ?? [], ...$normal];
        }
        foreach (self::$helpShown ?? [] as $component => $_) {
            $component->markDirty();
        }
        return $this;
    }

    /**
     * The keys in force for this component: `bindings()` with what `bind()` changed, as
     * [method, description or null] by key notation (`ctrl+s`).
     *
     * @return array<string, array{string, ?string}>
     */
    public function bindingsInForce(): array
    {
        $map = self::normalize($this->bindings());
        $chain = [];
        for ($c = $this->parent; $c !== null; $c = $c->parent) {
            array_unshift($chain, $c);
        }
        foreach ($chain as $c) {
            foreach ($c->boundFor as $class => $over) {
                if ($this instanceof $class) {
                    $map = [...$map, ...$over];
                }
            }
        }
        return array_filter([...$map, ...$this->bound]);
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<string, array{string, ?string}|null>
     */
    private static function normalize(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $spec => $action) {
            $key = (string) Key::parse((string) $spec);
            if ($action === null) {
                $out[$key] = null;
            } elseif (\is_array($action)) {
                $out[$key] = [(string) $action[0], isset($action[1]) ? (string) $action[1] : null];
            } else {
                $out[$key] = [(string) $action, null];
            }
        }
        return $out;
    }

    /**
     * The keys a `Help` element shows: those of $for (else of the component the keys go to in
     * this component's tree) and of its parents, nearest first, each key once.
     *
     * @return list<array{string, string}>
     */
    private function helpFor(?Component $for): array
    {
        $at = $for ?? self::keyEnd($this->surface->root()) ?? $this;
        $out = [];
        for ($c = $at; $c !== null; $c = $c->parent) {
            if ($c->attribute('disabled') || ($c !== $at && $c->dialogs !== [])) {
                continue;
            }
            foreach ($c->bindingsInForce() as $key => [, $description]) {
                if ($description !== null) {
                    $out[$key] ??= $description;
                }
            }
            if ($c->dialogs !== []) {
                break;      // (a dialog's keys only: the rest are not reachable)
            }
        }
        return array_map(fn($k, $d) => [(string) $k, $d], array_keys($out), $out);
    }

    /** The component the keys go to from $root down. */
    private static function keyEnd(Surface $root): ?Component
    {
        $last = null;
        $s = $root;
        while ($s !== null) {
            $on = self::$onSurface[$s] ?? null;
            if ($on !== null && $on->mounted) {
                $last = $on;
                if (($unshown = $on->unshownFocus()) !== null) {
                    $s = $unshown->surface;
                    continue;
                }
            }
            $s = $s->focused();
        }
        return $last;
    }

    // ------------------------------------------------------------------------------------------
    // Dialogs

    /**
     * Show $dialog over this component, modal, and return an awaitable for its answer:
     *
     *     $this->go(function () {
     *         if (fiber\await($this->dialog(new Confirm('Delete the file?')))) { ... }
     *     });
     *
     * The dialog is centered over the component, at the size it needs, in its own border, and
     * gets the focus. While it is open, this component's own keys and mouse are the dialog's:
     * keys go to the dialog, clicks outside it do nothing. When it closes (a button, Enter on
     * it, Escape for null), the awaitable resolves with the answer, the dialog is disposed, and
     * the focus goes back where it was. Await the answer in a coroutine (`go()`), not in a key
     * handler: the keys that answer it come through the handlers.
     */
    protected function dialog(Dialog $dialog): object
    {
        $before = null;
        for ($s = $this->surface->root(); ($next = $s->focused()) !== null; $s = $next) {
            $before = $next;
        }
        $key = "\0dialog" . ++self::$dialogCount;
        $this->child($key, fn() => $dialog);
        $this->dialogs[$key] = [$dialog, $before];
        $dialog->on('close', function () use ($key, $dialog): void {
            [, $before] = $this->dialogs[$key] ?? [null, null];
            unset($this->dialogs[$key]);
            $this->surface->unhost($dialog->surface);       // (at once; the render that follows draws what was under it)
            if ($before !== null && ($before->viewport() !== null || $before->parent() === null)) {
                $before->focus();
            } else {
                $this->focus();
            }
        });
        $dialog->focus();
        $this->markDirty();
        return $dialog->result();
    }

    /** While a dialog is open: mouse events outside it are taken (nothing below gets them). */
    private function modal(Mouse $mouse): bool
    {
        if ($this->dialogs === []) {
            return false;
        }
        [$dialog] = end($this->dialogs);
        $view = $this->surface->viewport($dialog->surface);
        return $view === null || !$view->contains($mouse->x, $mouse->y);
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
        if ($this->guard($handler) !== true) {
            return false;
        }
        $this->markDirty();
        return true;
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
