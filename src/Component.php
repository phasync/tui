<?php

namespace phasync\Tui;

use fiber\Deferred;
use fiber\TimeoutException;
use phasync\Tui\Component\Dialog;
use phasync\Tui\Internal\Floating;
use phasync\Tui\Internal\OverlayLayout;
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
 * focus path: each component on the way sees it first, the root's first, through its bindings
 * marked `first` and its `onKey()`; at the component it ends at (the focused one) its own
 * bindings come first, then its `onKey()`, then the bindings of its parents, nearest first. A
 * key nobody takes goes to the focused surface's program (a `Terminal`'s shell). So a text
 * field types the letters an application binds, and an application's Ctrl-Q works wherever
 * the focus is. A container that wants some keys before its content (Ctrl-Tab, whatever is
 * focused) marks them `first`; the keys it wants only when the content did not take them
 * (Escape) are plain bindings:
 *
 *     protected function bindings(): array
 *     {
 *         return [
 *             'ctrl+tab' => ['nextTab', 'next tab', 'first' => true],    // before the content
 *             'escape'   => ['toBar', 'tab bar'],                        // after it, if untaken
 *         ];
 *     }
 *
 * While a modal overlay (a dialog) a component opened is open, that component's own keys wait,
 * except bindings marked `always` (`'ctrl+q' => ['quit', 'quit', 'always' => true]`); its
 * parents' bindings work.
 *
 * **Events.** `onKey()`, `onMouse()`, `onPaste()` and `onFocus()` receive the events that reach
 * the component's surface (see `Surface::dispatch()`): a component sees them before its focused
 * child does, and returning true keeps them from going further. `onResize()` is told the new size.
 * A drag goes to the component that took the press, in its coordinates, also outside it.
 * `overlay()` floats any element over everything, next to an anchor (a dropdown's list, a
 * menu); `dialog()` opens a modal `Dialog` and gives its answer as an awaitable.
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
 * So does an exception in the code that called `start()` (the closure given to `fiber\run()`,
 * or a coroutine): the program ends with it, and `fiber\run()` throws it (see `start()`).
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
    /** @var array<string, true> keys of the children made with keep(): no render disposes them */
    private array $kept = [];
    private bool $mounted = false;
    /** Counts changes of what hasFocus() reads besides the surfaces: children, mounting, overlays. */
    private static int $treeVersion = 0;
    /** @var array{int, int, bool}|null hasFocus()'s last answer, with the versions it was found at */
    private ?array $focusCache = null;
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
    /** The element tree the last render returned (null: it drew itself or returned a layout). */
    private ?Element $shown = null;
    /** @var array{Stylesheet, ComputedStyle, int}|null what the layout of $shown was made with: stylesheet, style, focus version */
    private ?array $shownWith = null;
    /** @var array<string, true> the keys of the children the last layout of $shown placed */
    private array $placed = [];
    /** The next render lays the tree out again, also when it is the same (see relayout()). */
    private bool $relayout = false;
    /** focus() was called before start(): it takes effect when the component starts. */
    private bool $focusOnStart = false;
    /** @var \WeakMap<Component, true>|null other components that show this one or keep it (see child()) */
    private ?\WeakMap $heldBy = null;
    private ?Stylesheet $stylesheet = null;
    /** The style a parent's element tree gives this component to inherit. */
    private ?ComputedStyle $inherited = null;
    /** @var array{array{int,int}, ?array}|null the room a parent's layout measured this component in, and the answer */
    private ?array $measured = null;
    /** @var array<string, array{int,int}|null> measureFor() by room, until the next markDirty() or render */
    private array $sizes = [];
    /** @var array{int, int}|null the width a parent's layout asked leastRows() for, and the answer */
    private ?array $leastAsked = null;
    /** @var array<string, true> states of its own for the stylesheet (`setStyleState()`) */
    private array $styleStates = [];
    /** @var array<string, mixed> bindings of this instance over its class's (`bind()`) */
    private array $bound = [];
    /** @var array<string, array<string, mixed>> bindings for components of a class in the subtree */
    private array $boundFor = [];
    /** On a root: the key that disposes it when nothing binds it (`Tui::run()`). */
    private ?string $quitKey = null;
    /** @var array<string, Overlay> the open overlays this component opened, by the key of their content child */
    private array $overlays = [];
    /** The overlay this component is the content of. */
    private ?Overlay $overlaid = null;
    /** @var array<string, Dialog> the dialogs opened with a key (`dialog(key:)`), the latest per key */
    private array $dialogs = [];
    /** @var list<Overlay> on a root: the open overlays of the whole tree, in the order they opened */
    private array $floating = [];
    private ?OverlayLayout $overlayLayout = null;
    private static int $overlayCount = 0;
    /** @var array<int, Component> the roots that have overlays open */
    private static array $floatingRoots = [];
    /** The component whose render() runs now (focus() remembers what renders ask for). */
    private static ?Component $inRender = null;
    /** @var array<int, true> the components this component's last render focused, and the one in progress */
    private array $focusAsked = [];
    private array $focusAskedBefore = [];
    /** The coming render was asked for with requestRender(): its focus() calls take effect again. */
    private bool $asked = false;
    /** @var array<int, Component> components to render in the coming frame */
    private static array $dirty = [];
    /** The flusher is registered with Surface (see start()). */
    private static bool $flushing = false;
    /** A frame is being rendered (a flush in it renders nothing more). */
    private static bool $framing = false;
    /** When a coroutine was started to render the coming frame (0: none is on its way). */
    private static float $frameAsked = 0.0;
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
     * description]` for a key that `Help` lists, with optional flags: `'first' => true` runs it
     * before the focused component inside this one gets the key (a container's Ctrl-Tab),
     * `'always' => true` keeps it working while a modal dialog this component opened is open.
     * The application changes them with `bind()`. A bound method runs when the key reaches the
     * component (see the class description on the order); one that returns false did not take
     * the key, which goes on. A render follows. `onKey()` is for what a map cannot say (any
     * letter, a key with a computed meaning); it runs on the way down (after the `first`
     * bindings) in a component the key passes, after all its bindings in the focused one.
     *
     * @return array<string, string|array<int|string, string|bool>>
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
     * `Tui::run()` does all of it for a program: the terminal, the run, the end.
     *
     * **When the code that called it fails** (the closure given to `fiber\run()` throws after
     * `start()`, or the coroutine that called it does), the program ends: the component is
     * disposed, the terminal detached and restored, and the exception comes out of
     * `fiber\run()` as it would without a terminal UI, instead of the program's coroutines
     * keeping `fiber\run()` running with the exception waiting behind them. This needs a
     * runtime that can await the calling task from a task started inside it:
     * fiber-interop/api newer than 0.3.1 (main from commit 4dc3d41) and, under phasync,
     * fiber-interop/phasync newer than 0.4.1 (main from commit 8445790). With older versions
     * the library does not watch the caller: use `Tui::run()`, which owns the run and always
     * sees the exception, or `FakeTerminal::run()` in tests.
     *
     * @throws \LogicException when already started
     */
    final public function start(Surface|TerminalDevice|null $on = null): static
    {
        if ($this->mounted || $this->stopped !== null) {
            throw new \LogicException(static::class . ' was already started');
        }
        Runtime::check();
        if (!self::$flushing) {
            // a terminal device's frame (and freeze(), and Surface::flush()) draws the renders asked for first
            self::$flushing = true;
            Surface::onFlush(static function (): void {
                if (self::$dirty !== [] && !self::$framing) {
                    self::renderFrame();
                }
            });
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
        self::$treeVersion++;
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
            $s->on('mouse', fn(Mouse $e) => $this->pointer($e) || $this->overlayMouse($e) || $this->handle(fn() => $this->onMouse($e))),
            $s->on('paste', fn(Paste $e) => (!$this->suspended() && !$this->attribute('disabled') && $this->handle(fn() => $this->onPaste($e))) || $this->toUnshown($e)),
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
        if ($this->focusOnStart && $this->mounted) {
            $this->focusOnStart = false;
            $this->focus();
        }
        if ($this->parent === null) {
            $this->firstRender();
            if (!$on instanceof TerminalDevice) {
                // the code that started it fails: the program ends with it (on a device, attach() sees to it)
                Runtime::watch(function (\Throwable $e): void {
                    if ($this->mounted) {
                        $this->error ??= $e;
                        $this->dispose();
                    }
                });
            }
        }
        // a child is sized and rendered by its parent's render that shows it
        return $this;
    }

    /**
     * The terminal device the component's tree is shown on (`Tty`, `FakeTerminal`), or null
     * when it is not attached to one: for a component that prints above an inline region or
     * freezes a row of it (`$this->device()?->println("copied $file")`,
     * `$this->device()?->freeze($this->surface)`), also when `Tui::run()` or `Tui::inline()`
     * made the device.
     */
    final public function device(): ?TerminalDevice
    {
        return $this->mounted ? TerminalDevice::of($this->surface->root()) : null;
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
     * listen. When `dispose()` returns, `unmount()` has run. For a root, the renders asked for
     * until now are done first (what the program changed last shows in the last frame, which
     * an inline device leaves on the terminal), then the surface is closed, which detaches a
     * terminal device attached to it. The surface keeps its last content. Safe to call twice;
     * children are disposed by their parent.
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
    final public function dispose(float $timeout = 1.0): void
    {
        if (!$this->mounted) {
            return;
        }
        if ($this->parent === null && self::$dirty !== []) {
            self::renderFrame();        // the last frame shows what was asked last
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
     * End the component without waiting for its end: what `dispose()` does, except that it
     * returns at once. Its children are stopped, `stopped()` resolves and `sleep()` returns
     * false, and `unmount()` runs when its coroutines have returned and its children have ended
     * ($timeout seconds at the latest). Use it for a child the parent no longer wants (a tab
     * that was closed, a `keep()` child) from a render or a key handler, which should not wait;
     * use `dispose()` where the end must be complete when the call returns. A child that is
     * stopped leaves its parent at once (`child($key)` gives null). Safe to call twice.
     */
    final public function stop(float $timeout = 1.0): void
    {
        if (!$this->mounted) {
            return;
        }
        $this->mounted = false;
        self::$treeVersion++;
        foreach ($this->offs as $off) {
            $off();
        }
        $this->offs = [];
        // an overlay showing it closes with it (cancelled)
        $this->overlaid?->cancel();
        if ($this->parent === null) {
            unset(self::$floatingRoots[spl_object_id($this)]);
            // the program ends: give the terminal back at once
            $this->surface->close();
        } else {
            // no longer the parent's child (child() makes a new one), but the parent's end waits for it
            $key = array_search($this, $this->parent->children, true);
            if ($key !== false) {
                unset($this->parent->children[$key], $this->parent->kept[$key]);
                self::$treeVersion++;
            }
            $this->parent->ending[] = $this;
        }
        foreach (array_reverse($this->owned()) as $child) {
            $child->stop($timeout);
        }
        $this->children = [];     // (those of another owner stay theirs)
        self::$treeVersion++;
        $this->kept = [];
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
    final public function isMounted(): bool
    {
        return $this->mounted;
    }

    /**
     * An awaitable that resolves when the component is disposed, for `fiber\await_any([$work,
     * $component->stopped()])` around a wait that should end with the component. In loops,
     * prefer `sleep()`.
     */
    final public function stopped(): object
    {
        if ($this->stopped === null) {
            throw new \LogicException(static::class . ' has not been started');
        }
        return $this->stopped;
    }

    /**
     * The child components, by key, in the order they were made: those made with `child($key)`
     * under their key, those placed in the tree with `key:` (or `with(key:)`) under that key,
     * and the rest (placed by position, the contents of overlays) under keys of the library's,
     * which begin with "\0". How a parent or a test reaches a child: `$app->children()['log']`.
     * Children that were disposed are not in it.
     *
     * @return array<string, Component>
     */
    final public function children(): array
    {
        $out = [];
        foreach ($this->children as $key => $child) {
            if ($child->mounted) {
                $out[str_starts_with($key, "\0k:") ? substr($key, 3) : $key] = $child;
            }
        }
        return $out;
    }

    /** The component this one is a child of, or null for a root. */
    final public function parent(): ?Component
    {
        return $this->parent;
    }

    /** The exception that ended this component, if one did. */
    final public function error(): ?\Throwable
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
    final public function on(string $event, \Closure $listener): \Closure
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
    final protected function emit(string $event, mixed ...$data): void
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
    final protected function call(?\Closure $callback, mixed ...$args): mixed
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
    final protected function hasListeners(string $event): bool
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
    final public function requestRender(): void
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
        $this->sizes = [];
        if (!$this->mounted || $this->renderPending || !$this->rendered || $this->rendering) {
            return;     // (before the first render: that one is coming)
        }
        $this->renderPending = true;
        self::$dirty[spl_object_id($this)] = $this;
        if (TerminalDevice::of($this->surface->root())?->drawsFrames()) {
            return;     // the terminal's next frame renders it (they are at most 60 a second)
        }
        $now = microtime(true);
        if ($now - self::$frameAsked > 0.5) {
            // one coroutine renders everything asked for in this moment (one asked for long ago
            // is gone with a runtime that ended, or the loop is blocked: another does no harm)
            self::$frameAsked = $now;
            \fiber\go(static function (): void {
                \fiber\sleep(0);
                self::$frameAsked = 0.0;
                self::renderFrame();
            });
        }
    }

    /**
     * Render every component that asked for it, parents before their children (so a child
     * whose props a parent's render changed renders once), each at most once per frame.
     */
    private static function renderFrame(): void
    {
        self::$framing = true;
        try {
            self::renderPasses();
        } finally {
            self::$framing = false;
        }
    }

    private static function renderPasses(): void
    {
        $done = [];
        $later = [];
        $error = null;
        // (passes: an overlay placed anew lays its content out before the overlays after it)
        for ($pass = 0; $pass < 16; $pass++) {
            while (self::$dirty !== []) {
                $batch = self::$dirty;
                self::$dirty = [];
                uasort($batch, fn(Component $a, Component $b) => $a->depth() <=> $b->depth());
                foreach ($batch as $id => $component) {
                    if (isset($done[$id])) {
                        $later[$id] = $component;   // asked again by a render of this pass: after it
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
            // after the layout pass, before painting: every overlay goes where its anchor is now
            $again = false;
            foreach (self::$floatingRoots as $root) {
                try {
                    $again = $root->placeOverlays() || $again;
                } catch (\Throwable $e) {
                    $error ??= $e;
                }
            }
            if ($later !== [] && $pass < 3) {
                // asked again by a render of this frame (a child's size changed its parent's
                // layout): once more in this frame, so no half-done state is drawn
                self::$dirty += $later;
                $done = array_diff_key($done, $later);
                $later = [];
                $again = true;
            }
            if (!$again) {
                break;
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
     *
     * Called on a component that is not started yet (one made with `new` and placed in a tree
     * that has not been rendered), it takes effect when the component starts, as the latest
     * request then.
     */
    final public function focus(): void
    {
        if ($this->stopped === null) {
            $this->focusOnStart = true;     // (not started yet: when it starts, see above)
            return;
        }
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
        if ($this->canFocus()) {
            $this->surface->takeFocus();    // the component itself, not what it focused inside
        } else {
            $this->surface->focus();        // on to the descendant it focused, or its first
        }
    }

    /**
     * Move the focus to the next field inside this component, in the order they show (depth
     * first through its windows, as `Surface::focusNext()`), wrapping around after the last
     * (with $wrap false: stopping there and returning false, so the key goes on to a parent
     * that moves on from this group). How a container binds Tab: `'tab' => 'focusNext'`.
     * Returns false when there is no other field to go to.
     */
    final public function focusNext(bool $wrap = true): bool
    {
        return $this->surface->focusNext(false, $wrap);
    }

    /** As `focusNext()`, backwards (Shift-Tab): `'shift+tab' => 'focusPrevious'`. */
    final public function focusPrevious(bool $wrap = true): bool
    {
        return $this->surface->focusNext(true, $wrap);
    }

    /**
     * Whether the component takes the keys itself when it is focused (`focus()`), rather than
     * passing them on to the descendant it focused last (or to its first focusable one), as a
     * container does. By default it does when it has keys of its own: `bindings()` (or keys
     * given with `bind()`) or an `onKey()`/`onPaste()` of its own class. So a board with key
     * bindings that focused its filter field takes the focus back with `$board->focus()`, and a
     * box or a dialog around a text field passes it to the field. Override it to decide.
     */
    protected function canFocus(): bool
    {
        static $own = [];
        $class = static::class;
        if (!isset($own[$class])) {
            $own[$class] = false;
            foreach (['onKey', 'onPaste'] as $hook) {
                if ((new \ReflectionMethod($this, $hook))->getDeclaringClass()->getName() !== self::class) {
                    $own[$class] = true;
                }
            }
        }
        return $own[$class] || $this->bindingsInForce() !== [];
    }

    /**
     * True if keys go to this component (or through it to a descendant): it is on the focus path
     * from the root, also while it is not shown yet (see `focus()`). It reads the very path keys
     * take, so it is true exactly when the next key would reach the component.
     */
    final public function hasFocus(): bool
    {
        if (!$this->mounted) {
            return false;
        }
        // (asked for every element of every render: kept while neither the focus nor the tree changed)
        $focus = Surface::focusVersion();
        if ($this->focusCache !== null && $this->focusCache[0] === $focus && $this->focusCache[1] === self::$treeVersion) {
            return $this->focusCache[2];
        }
        $has = $this->findFocus();
        $this->focusCache = [$focus, self::$treeVersion, $has];
        return $has;
    }

    private function findFocus(): bool
    {
        foreach ($this->overlays as $overlay) {
            if ($overlay->content->hasFocus()) {
                return true;    // (a dropdown's field while its list has the keys)
            }
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
    final protected function child(string $key, ?\Closure $create = null): ?Component
    {
        if ($this->used !== null) {
            $this->used[$key] = true;
        }
        if (isset($this->children[$key])) {
            if ($this->children[$key]->mounted) {
                return $this->children[$key];
            }
            unset($this->children[$key]);     // one of another owner's, disposed by it
            self::$treeVersion++;
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
            $child->heldBy ??= new \WeakMap();
            $child->heldBy[$this] = true;
            self::$treeVersion++;
            return $child;
        }
        $child->parent = $this;
        $this->children[$key] = $child;
        self::$treeVersion++;
        $child->start();
        return $child;
    }

    /**
     * The child under $key, made with $create the first time, that lives until it is stopped,
     * however many renders do not show it: the panes of hidden tabs, a dialog's content kept
     * for the next time, a `Terminal` that goes on running in the background. A child made
     * with `child()` is disposed by the first render that neither asks for it nor shows it; a
     * kept one is not. Show it by placing it in a render (`$this->keep('shell', fn() => new
     * Terminal('sh'))` returned in the tree, or `child('shell')` there) or not; end it with
     * `$kept->stop()` (or `dispose()`) when it is no longer wanted, or with this component. It is
     * started at once, and sized and rendered when a render first shows it (a `Terminal` starts
     * its program then). `child($key)` finds it.
     *
     * @template T of Component
     * @param \Closure(): T $create
     * @return T
     */
    final protected function keep(string $key, \Closure $create): Component
    {
        $child = $this->child($key, $create);
        if ($child->parent === $this) {
            $this->kept[$key] = true;
        }
        return $child;
    }

    /**
     * Start another coroutine that belongs to the component: an exception in it is contained
     * like one in `run()`, and the component's end (`unmount()`) waits for it. Make it end when
     * `sleep()` returns false or `isMounted()` is false. When it ends after it waited (a fetch,
     * an awaited dialog), the component renders.
     */
    final protected function go(\Closure $task): void
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
    final protected function sleep(float $seconds): bool
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
            // the pen is the component's style: what render() clears (ESC [2J) or writes plainly is in its colours
            $pen = $this->style()->text;
            if ($this->surface->pen() !== $pen) {
                $this->surface->write("\e[0m" . Ansi::style($pen));
            }
            if (!isset(self::$hoverAware[$this]) && $this->wantsPointer()) {
                $this->followPointer($this->isHovered());
            }
            $result = $this->render();
            // the same tree as the last render's, in the same style and focus: what it laid out stands
            $same = $this->showsAgain($result);
            if ($same) {
                $layout = null;
                $used = $this->used + $this->placed;
            } else {
                $layout = $this->layoutOf($result);
                $used = $this->used;
            }
        } finally {
            self::$inRender = $outer;
            $this->used = null;
            $this->rendering = false;
        }
        $this->sizes = [];      // (measured by what it rendered now)
        $returned = $same ? $this->surface->layout() : $layout;
        if (!$same) {
            if ($this->parent === null && ($this->floating !== [] || $this->overlayLayout !== null)) {
                $layout = $this->withOverlays($layout);
            }
            if ($layout !== null) {
                $this->surface->setLayout($layout);
            }
            if (($this->measured !== null && $this->measured[1] !== $this->measureFor(...$this->measured[0]))
                || ($this->leastAsked !== null && $this->leastNow($this->leastAsked[0]) !== $this->leastAsked[1])) {
                $this->measured = $this->leastAsked = null;
                $this->parent?->relayout();     // it needs another size: the parent lays it out again
            }
        }
        // children shown for the first time draw now, at the size the layout gave them
        foreach ($this->children as $child) {
            if (!$child->rendered && $child->surface->viewport() !== null) {
                $child->firstRender();
            }
        }
        if ($returned !== null) {
            foreach ($this->children as $key => $child) {
                if (!isset($used[$key]) && !isset($this->kept[$key]) && !$child->isShownIn($this->surface) && !$child->isHeld()) {
                    if ($child->parent === $this) {
                        $child->stop();     // a render does not wait for its coroutines
                    } else {
                        unset($this->children[$key]);     // another owner's: no longer shown here
                        self::$treeVersion++;
                    }
                }
            }
        }
    }

    /**
     * True if render() returned a tree that describes the same as the one shown (see
     * sameElement()), and nothing else the layout of it reads changed since: the stylesheet,
     * the component's style, the focus anywhere, a child's size or style state (relayout()).
     * Then the layout, the windows and the paint of the last render stand as they are, and a
     * render that changed nothing costs the render() call and the comparison.
     */
    private function showsAgain(Layout|Element|null $result): bool
    {
        $last = $this->shownWith;
        if (!$result instanceof Element || $this->shown === null || $last === null || $this->relayout
            || $this->overlays !== [] || $this->floating !== [] || $this->tree?->showsHelp !== false) {
            return false;
        }
        return $last[0] === $this->stylesheet() && $last[1] === $this->style() && $last[2] === Surface::focusVersion()
            && self::sameElement($result, $this->shown);
    }

    /**
     * True if $a describes what $b does: the same instance, or elements of the same class with
     * the same attributes and content, children compared the same way; a component made with
     * `new` is the same as another with the same attributes and props (what a render would hand
     * over to the one shown), a started one only as itself.
     */
    private static function sameElement(Element $a, Element $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a::class !== $b::class) {
            return false;
        }
        if ($a instanceof Component) {
            if ($a->stopped !== null || $b->stopped !== null || !$a->sameAttributes($b)) {
                return false;
            }
            foreach (self::props($a::class) as $name) {
                if (!self::sameValue($a->$name, $b->$name)) {
                    return false;
                }
            }
            return true;
        }
        $theirs = (array) $b;
        foreach ((array) $a as $name => $value) {
            if (!self::sameValue($value, $theirs[$name])) {
                return false;
            }
        }
        return true;
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a instanceof Element && $b instanceof Element) {
            return self::sameElement($a, $b);
        }
        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!\array_key_exists($k, $b) || !self::sameValue($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * @internal Something the layout of this component's tree reads changed besides the tree
     * (a child needs another size, a child's style state changed): its next render lays the
     * tree out again even when render() returns the same tree.
     */
    private function relayout(): void
    {
        $this->relayout = true;
        $this->markDirty();
    }

    /** The layout for what render() returned (an element tree laid out). */
    private function layoutOf(Layout|Element|null $result): ?Layout
    {
        foreach ($this->overlays as $key => $_) {
            $this->child($key);         // (keeps their content)
        }
        $this->shown = $result instanceof Element ? $result : null;
        $this->relayout = false;
        $this->placed = [];
        $this->shownWith = null;
        if ($result instanceof Element) {
            $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...));
            $sheet = $this->stylesheet();
            $style = $this->style();
            $layout = $this->tree->build($result, $sheet, $style);
            $this->shownWith = [$sheet, $style, Surface::focusVersion()];
            self::$helpShown ??= new \WeakMap();
            if ($this->tree->showsHelp) {
                self::$helpShown[$this] = true;
            } else {
                unset(self::$helpShown[$this]);
            }
            return $layout;
        }
        return $result;
    }

    /**
     * The size the component needs within $cols x $rows, for a parent that lays it out with
     * the size `'auto'` (what a component element in a `Column` or `Row` gets when no width or
     * height is given); null when it takes the room it is given. By default: the size of the
     * element tree its last render returned (a component rendering a `Row` of one-line things is
     * one row high, so a `Column` of them needs no `height:`), and null for one that draws itself
     * or has not rendered yet. Its first render may change the answer, and the parent then lays
     * it out again, before the frame is drawn. A component that draws itself and knows its size
     * (a one-line field) overrides it.
     *
     * @return array{int,int}|null
     */
    public function measure(int $cols, int $rows): ?array
    {
        if ($this->shown === null) {
            return null;
        }
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...));
        // (by the items its last render laid out; a tree it did not lay out last is measured dry)
        $built = $this->tree->measureBuilt($this->shown, $cols, $rows);
        return $built !== false ? $built : $this->tree->measure($this->shown, $cols, $rows, $this->stylesheet(), $this->style(), place: false);
    }

    /**
     * $element laid out as a layout of this component's, without setting it: for a component
     * that shows an element tree somewhere other than its own surface (a `ScrollView` puts it
     * on a surface taller than itself). The surfaces and child components behind the elements
     * persist across calls as they do for `render()`; call it from `render()`.
     */
    final protected function layoutElement(Element $element): Layout
    {
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...));
        return $this->tree->build($element, $this->stylesheet(), $this->style());
    }

    /**
     * The size an element tree needs within $cols x $rows, as the library would lay it out
     * (null: it takes the room it is given): for the `measure()` of a component whose size is
     * that of the tree it renders.
     *
     *     public function measure(int $cols, int $rows): ?array
     *     {
     *         return $this->measureElement($this->render(), $cols, $rows);
     *     }
     *
     * @return array{int,int}|null
     */
    final protected function measureElement(Element $element, int $cols, int $rows): ?array
    {
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...));
        return $this->tree->measure($element, $cols, $rows, $this->stylesheet(), $this->style());
    }

    // ------------------------------------------------------------------------------------------
    // Element trees, props, styles

    /**
     * The child for a component element made anew by a render: the one at its place, with the
     * new props. Without $create (a measure that changes nothing): the one at its place if it is
     * of the same class, else null.
     */
    private function place(string $key, Component $fresh, bool $create = true): ?Component
    {
        if ($create) {
            $this->placed[$key] = true;
        }
        $existing = $this->child($key);
        if (!$create) {
            return $existing !== null && $existing::class === $fresh::class ? $existing : null;
        }
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
    final public function stylesheet(): Stylesheet
    {
        return $this->stylesheet ?? $this->parent?->stylesheet() ?? Stylesheet::default();
    }

    /**
     * Use $stylesheet for this component and its descendants (set it on the root component for
     * the whole program; build it on the default theme with `Stylesheet::default()->with()`).
     * Everything renders again with it.
     */
    final public function setStylesheet(Stylesheet $stylesheet): void
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
     *     $this->style(['quote', 'link'])      // a link inside a quote: both, the inner one winning
     *
     * Every part inherits the component's own style. Parts given as a list apply together,
     * outermost first: attributes add up, and the innermost colour that is set wins (see
     * `Stylesheet::compute()`); the states apply to each part of the list. That is how a widget
     * draws several parts on one row (a marker, a label, a hint) in the row's state, without
     * merging styles itself:
     *
     *     $row    = $this->style('node', 'current');              // the bar
     *     $marker = $this->style(['node', 'marker'], 'current');  // on the bar, its own colour if any
     *
     * @param string|list<string>|null $part
     */
    final public function style(string|array|null $part = null, string ...$states): ComputedStyle
    {
        $sheet = $this->stylesheet();
        $own = $this->states();
        $style = $sheet->compute($this, $own, $this->inherited ?? $this->parent?->style(), $part, array_values($states));
        if ($style->hoverAware) {
            $this->followPointer(\in_array('hover', $own, true));
        }
        return $style;
    }

    /**
     * Whether the component follows the mouse: it gets every motion of the mouse over it in
     * `onMouse()` (`Mouse::MOVE`, no button held), and its `:hover` state is kept, so it can
     * light up what is under the pointer (a tab, a menu item, a link). By default it does when
     * the stylesheet has a `:hover` rule for it or one of its parts (`Tabs::tab:hover`).
     * Override it to return true for a component that tracks the pointer itself. While it
     * follows the mouse, its surface asks for motion reports (mode 1003), so it takes its mouse
     * events itself (a drag in it does not select text).
     */
    protected function wantsPointer(): bool
    {
        return false;
    }

    /** Ask for motion reports (once) and keep the hover state; $hovered: the mouse is over it now. */
    private function followPointer(bool $hovered): void
    {
        if (!$this->mounted) {
            return;
        }
        self::$hoverAware ??= new \WeakMap();
        if (!isset(self::$hoverAware[$this])) {
            // motion reports without a button held, so the mouse is followed (the device asks for them)
            $this->surface->write("\e[?1003h");
        }
        self::$hoverAware[$this] = $hovered;
    }

    /**
     * @internal `measure()` for a parent's layout: the answer is remembered, so that when it
     * changes (an editor that grew a line) the parent lays its tree out again. The answers stand
     * until the component is marked for a render or renders (so a parent's render does not
     * measure its fifty unchanged rows again): what `measure()` reads must be what a change of
     * goes with a render, as state, props and the style are.
     *
     * @return array{int,int}|null
     */
    final public function measureFor(int $cols, int $rows): ?array
    {
        $key = $cols . ',' . $rows;
        if (!\array_key_exists($key, $this->sizes)) {
            if (\count($this->sizes) > 16) {
                $this->sizes = [];
            }
            $this->sizes[$key] = $this->measure($cols, $rows);
        }
        $size = $this->sizes[$key];
        $this->measured = [[$cols, $rows], $size];
        return $size;
    }

    /**
     * @internal The fewest rows this component shows its element tree in at $cols wide without
     * squeezing it (the sum of what its parts need, one row for each that fills); 1 for one
     * that draws itself or takes any room.
     */
    final public function leastRows(int $cols): int
    {
        $this->leastAsked = [$cols, $least = $this->leastNow($cols)];
        return $least;
    }

    private function leastNow(int $cols): int
    {
        $least = $this->shown !== null && $this->tree !== null ? $this->tree->leastBuilt($this->shown, $cols) : false;
        return $least === false ? 1 : $least;
    }

    /** @internal The style the parent's element tree around this component gives it to inherit. */
    final public function inheritStyle(ComputedStyle $parent): void
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
        return [...$states, ...array_keys($this->styleStates)];
    }

    /**
     * Put the component in a state of its own for the stylesheet, or take it out of it: a card
     * that is `:selected`, a field that is `:invalid`, a button that is `:pressed`. Rules such as
     * `'.card:selected' => ['border' => 'double $accent']` then apply to it, in `style()` and
     * in the element tree around it; it renders again when a state changes. The built-in
     * states (`focused`, `disabled`, `hover`) are the library's.
     */
    final public function setStyleState(string $state, bool $on = true): void
    {
        if ($on === isset($this->styleStates[$state])) {
            return;
        }
        if ($on) {
            $this->styleStates[$state] = true;
        } else {
            unset($this->styleStates[$state]);
        }
        $this->parent?->relayout();       // (a Box around it in the parent's tree shows it too)
        $this->markDirty();
    }

    /** True if the component is in $state (one of its own, see `setStyleState()`, or a built-in one). */
    final public function hasStyleState(string $state): bool
    {
        return \in_array($state, $this->states(), true);
    }

    // ------------------------------------------------------------------------------------------
    // Keys

    /**
     * A key on its way down the focus path. On the way, each component's bindings marked
     * `first` and its `onKey()` may take it (the root's first). At the component it ends at
     * (nothing focused further down): its own bindings, its `onKey()`, then its parents'
     * bindings, nearest first (those marked `first` only where the key did not pass on the way
     * down); Escape closes a non-modal overlay on the way. A component with a modal overlay open
     * is suspended: only its bindings marked `always` work, its parents' do.
     */
    private function key(Key $key): bool
    {
        if (!$this->mounted) {
            return false;
        }
        $mine = !$this->suspended() && !$this->attribute('disabled');
        if (!$this->isKeyEnd()) {
            return (!$this->attribute('disabled') && $this->runBinding($key, $this->suspended(), true))
                || ($mine && $this->handle(fn() => $this->onKey($key))) || $this->toUnshown($key);
        }
        if ($mine && ($this->runBinding($key) || $this->handle(fn() => $this->onKey($key)))) {
            return true;
        }
        for ($c = $this; $c !== null; $c = $c->parent) {
            // (a parent the key passed on its way down had its `first` bindings asked then)
            if ($c !== $this && !$c->attribute('disabled') && $c->runBinding($key, $c->suspended(), $this->isShownIn($c->surface) ? false : null)) {
                return true;
            }
            if ($c === $this && $this->suspended() && $this->runBinding($key, true)) {
                return true;
            }
            if ($c->overlaid !== null && !$c->overlaid->modal && (string) $key === 'escape') {
                $c->overlaid->cancel();
                return true;
            }
        }
        $root = $this->rootComponent();
        if ($root->quitKey !== null && (string) $key === $root->quitKey) {
            $root->dispose();       // (Tui::run(): nothing bound the key)
            return true;
        }
        return false;
    }

    /**
     * @internal On a root: dispose it when $key reaches it and no component on the key path
     * binds it (`Tui::run()`'s Ctrl-Q); null for none. `Help` lists it as "quit".
     */
    final public function quitOn(?string $key): void
    {
        $this->quitKey = $key === null ? null : (string) Key::parse($key);
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

    /**
     * Run the method bound to $key (with $always, only a binding marked `always`; with $first
     * true only one marked `first`, false only one that is not); true if it took the key.
     */
    private function runBinding(Key $key, bool $always = false, ?bool $first = null): bool
    {
        $binding = $this->bindingsInForce()[(string) $key] ?? null;
        if ($binding === null || ($always && !$binding[2]) || ($first !== null && $binding[3] !== $first)) {
            return false;
        }
        $method = $binding[0];
        $public = method_exists($this, $method) ? new \ReflectionMethod($this, $method) : null;
        if ($public === null || !$public->isPublic()) {
            throw new \LogicException(static::class . ": the key '$key' is bound to '$method', which is not a public method");
        }
        // (the method of the component's class: called from here, a name that Component uses
        // privately, such as key(), would be Component's own)
        if ($this->guard(fn() => $public->invoke($this)) === false) {
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
    final public function bind(array $bindings, ?string $for = null): static
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
     * [method, description or null, always, first] by key notation (`ctrl+s`).
     *
     * @return array<string, array{string, ?string, bool, bool}>
     */
    final public function bindingsInForce(): array
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
     * @return array<string, array{string, ?string, bool, bool}|null>
     */
    private static function normalize(array $bindings): array
    {
        $out = [];
        foreach ($bindings as $spec => $action) {
            $key = (string) Key::parse((string) $spec);
            if ($action === null) {
                $out[$key] = null;
            } elseif (\is_array($action)) {
                $out[$key] = [(string) $action[0], isset($action[1]) ? (string) $action[1] : null, (bool) ($action['always'] ?? false), (bool) ($action['first'] ?? false)];
            } else {
                $out[$key] = [(string) $action, null, false, false];
            }
        }
        return $out;
    }

    /**
     * The keys a `Help` element shows: those of $for (else of the component the keys go to in
     * this component's tree) and of its parents, nearest first, each key once; with $show
     * 'focused' only its own, with 'application' only its parents'.
     *
     * @return list<array{string, string}>
     */
    private function helpFor(?Component $for, string $show = 'all'): array
    {
        $at = $for ?? self::keyEnd($this->surface->root()) ?? $this;
        $out = [];
        for ($c = $at; $c !== null; $c = $c->parent) {
            if ($c->attribute('disabled') || ($show === 'focused' && $c !== $at) || ($show === 'application' && $c === $at)) {
                continue;
            }
            foreach ($c->bindingsInForce() as $key => [, $description, $always]) {
                if ($description !== null && (!$c->suspended() || $always)) {
                    $out[$key] ??= $description;
                }
            }
            if ($c->parent === null && $c->quitKey !== null && $show !== 'focused') {
                $out[$c->quitKey] ??= 'quit';
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
    // Overlays and dialogs

    /**
     * Show $element floating over everything, outside this component's rectangle and its
     * parents' clipping: a dropdown's list, a completion popup, a context menu, a tooltip, a
     * command palette. Returns the `Overlay` (close it, await `closed()` for its result).
     *
     *     $menu = $this->overlay(new Select(['copy' => 'Copy', 'paste' => 'Paste'],
     *         onActivate: fn($key) => $menu->close($key)), place: 'below');
     *
     * - **Where**: next to `$anchor`, a rectangle in this component's coordinates (default: the
     *   whole component), by `$place`: `'below'` or `'above'` it (the other side when it does
     *   not fit there; else the side with more room, the overlay shrunk to it and its content
     *   scrolling), `'center'` of the screen, or a `Rect` (in this component's coordinates).
     *   Horizontally it is shifted to stay on the screen; nothing of it is ever outside the root
     *   (see `Placement`). It follows the anchor when this component moves or the terminal is
     *   resized (placed again after the layout of that frame), keeping its state, and is
     *   cancelled when this component is no longer shown.
     * - **Size**: the element's `width`/`height` when given (cells or a share of the screen),
     *   else what it measures (`measure()`), else the screen; with the border its style gives it.
     * - **Order**: later overlays above earlier ones.
     * - **Focus**: the overlay gets it (a component that wants to keep the keys, as an editor
     *   with a completion list, calls `$this->focus()` after); closing gives it back.
     * - **Modal** (`$modal`): it takes all input until it is closed: clicks outside it reach
     *   nothing, and this component's own keys are suspended (its bindings marked `always`
     *   and those of its parents still work: the application's Ctrl-Q quits). **Not modal**:
     *   Escape (when the overlay's content does not take it), a press outside it and this
     *   component, or the focus moving elsewhere cancels it.
     *
     * The element is shown by a child component of this one (the element itself when it is a
     * component, not started yet): disposing this component cancels the overlay, so a coroutine
     * awaiting it goes on. Cancelled, it closes with null (a `Dialog`: its `$cancel`).
     *
     * @param 'below'|'above'|'center'|Rect $place
     * @throws \LogicException when this component is not mounted or the element was started already
     */
    final protected function overlay(Element $element, ?Rect $anchor = null, string|Rect $place = 'below', bool $modal = false): Overlay
    {
        if (!$this->mounted) {
            throw new \LogicException(static::class . ': overlay() needs a mounted component');
        }
        if (!$place instanceof Rect && !\in_array($place, ['below', 'above', 'center'], true)) {
            throw new \InvalidArgumentException("Unknown placement '$place': use 'below', 'above', 'center' or a Rect");
        }
        $content = $element instanceof Component ? $element : new Floating($element);
        if ($content->stopped !== null) {
            throw new \LogicException(static::class . ': the overlay\'s ' . $content::class . ' was started already');
        }
        $root = $this->rootComponent();
        $before = null;
        for ($s = $root->surface; ($next = $s->focused()) !== null; $s = $next) {
            $before = $next;
        }
        $key = "\0o:" . ++self::$overlayCount;
        $overlay = new Overlay($content, $anchor, $place, $modal, $content instanceof Dialog ? $content->cancel : null, $before ?? $root->surface,
            fn(Overlay $o) => $this->closeOverlay($key, $o));
        $content->overlaid = $overlay;
        $this->overlays[$key] = $overlay;
        self::$treeVersion++;
        $root->floating[] = $overlay;
        self::$floatingRoots[spl_object_id($root)] = $root;
        $this->child($key, fn() => $content);
        if (!$overlay->isOpen()) {
            return $overlay;    // (its mount() failed)
        }
        $root->placeOverlays();
        if ($content->mounted) {
            $content->focus();
        }
        $this->markDirty();
        return $overlay;
    }

    /**
     * Show $dialog over the screen, modal, and return an awaitable for its answer:
     *
     *     $this->go(function () {
     *         if (fiber\await($this->dialog(new Confirm('Delete the file?')))) { ... }
     *     });
     *
     * The dialog is an overlay (see `overlay()`) in the middle of the screen, as large as it
     * needs (shrunk to the screen, its content scrolling), in its own border, and gets the
     * focus. While it is open it takes this component's keys and the mouse (its bindings
     * marked `always` and its parents' bindings still work). When it closes (a button, Enter,
     * Escape for its cancel answer), the awaitable resolves with the answer, the dialog is
     * disposed, and the focus goes back where it was; when this component is disposed while it
     * is open, it resolves with the cancel answer. Await it in a coroutine (`go()`), not in a key
     * handler: the keys that answer it come through the handlers.
     *
     * **One at a time**: with a `$key`, a dialog of that key this component opened and that is
     * still open is not opened again: it gets the focus, and its awaitable is returned (the new
     * `$dialog` is not used). For a key that opens a dialog and works while one is open (an
     * `always` binding: Ctrl-Q asking "Quit?"), so pressing it twice asks once:
     *
     *     public function quit(): void        // 'ctrl+q' => ['quit', 'quit', 'always' => true]
     *     {
     *         $this->go(function () {
     *             if (fiber\await($this->dialog(new Confirm('Quit?'), key: 'quit'))) {
     *                 $this->dispose();
     *             }
     *         });
     *     }
     *
     * (Both coroutines then get the one answer: act on it once, as `dispose()` does.)
     */
    final protected function dialog(Dialog $dialog, ?string $key = null): object
    {
        $open = $key === null ? null : ($this->dialogs[$key] ?? null);
        if ($open !== null && $open->isMounted() && $open->inOverlay()?->isOpen()) {
            $open->focus();
            return $open->result();
        }
        $this->overlay($dialog, place: 'center', modal: true);
        if ($key !== null) {
            $this->dialogs[$key] = $dialog;
        }
        return $dialog->result();
    }

    /** The overlay this component is the content of, or null when it is not shown in one. */
    final public function inOverlay(): ?Overlay
    {
        return $this->overlaid;
    }

    /** True while an overlay this component opened is modal and open: its own keys wait. */
    private function suspended(): bool
    {
        foreach ($this->overlays as $overlay) {
            if ($overlay->modal) {
                return true;
            }
        }
        return false;
    }

    private function rootComponent(): Component
    {
        $c = $this;
        while ($c->parent !== null) {
            $c = $c->parent;
        }
        return $c;
    }

    /** The opener's part of closing $overlay: it leaves the screen and the tree, the focus goes back. */
    private function closeOverlay(string $key, Overlay $overlay): void
    {
        unset($this->overlays[$key]);
        self::$treeVersion++;
        $root = $this->rootComponent();
        $root->floating = array_values(array_filter($root->floating, fn(Overlay $o) => $o !== $overlay));
        if ($root->floating === []) {
            unset(self::$floatingRoots[spl_object_id($root)]);
        }
        $content = $overlay->content;
        $hadFocus = $content->surface->hasFocus();
        if ($root->mounted) {
            $root->surface->changeTree(function () use ($root, $content, $overlay, $hadFocus): void {
                $root->surface->unhost($content->surface);      // (at once; what was under it shows again)
                if ($root->floating === [] && $root->overlayLayout !== null && $root->overlayLayout->inner === null) {
                    $root->overlayLayout = null;
                    $root->surface->setLayout(null);
                }
                if ($hadFocus || $overlay->modal) {
                    $before = $overlay->focusBefore;
                    if ($before !== null && $before !== $root->surface && ($before->viewport() !== null || $before->parent() === null)) {
                        $before->focus();
                    } elseif ($this->mounted) {
                        $this->focus();
                    }
                }
            });
        }
        if ($content->mounted) {
            $content->stop();
        }
        $this->markDirty();
    }

    /** A root's layout with the overlays of its tree over it (null: it draws itself, then only the overlays). */
    private function withOverlays(?Layout $inner): ?Layout
    {
        if ($this->floating === []) {
            if ($inner === null && $this->overlayLayout !== null) {
                $this->surface->setLayout(null);
            }
            $this->overlayLayout = null;
            return $inner;
        }
        if ($this->overlayLayout === null || $this->overlayLayout->inner !== $inner) {
            $this->overlayLayout = new OverlayLayout($inner, fn() => $this->floating);
        }
        return $this->overlayLayout;
    }

    /**
     * On a root: decide where each open overlay goes now (`Placement`), cancel those whose
     * opener is gone, not shown, or (not modal) no longer focused, and arrange them. In the
     * order they opened, each after the one before it was arranged: an overlay opened from
     * inside another (a dropdown in a dialog) is anchored where its opener is once the outer one
     * was placed. Returns true when an overlay was arranged anew and that made components
     * render (the outer overlay's content laid out for its new size): render them, then call
     * this again for the overlays after it.
     */
    private function placeOverlays(): bool
    {
        if (!$this->mounted) {
            return false;
        }
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        $origin = $this->surface->area();
        $root = new Rect(0, 0, $cols, $rows);
        $changed = false;
        $again = false;
        foreach ($this->floating as $overlay) {
            $opener = $overlay->content->parent;
            if ($opener === null || !$opener->mounted || !$overlay->content->mounted
                || ($opener !== $this && !$opener->isShownIn($this->surface)) || $opener->surface->area()->isEmpty()) {
                $overlay->cancel();     // the anchor is no longer shown
                $changed = true;
                continue;
            }
            if (!$overlay->modal) {
                $focused = $opener->hasFocus();
                if ($overlay->wasFocused && !$focused) {
                    $overlay->cancel();
                    $changed = true;
                    continue;
                }
                $overlay->wasFocused = $overlay->wasFocused || $focused;
            }
            $area = $opener->surface->area();
            $ox = $area->x - $origin->x;
            $oy = $area->y - $origin->y;
            $a = $overlay->anchor;
            $anchor = $a === null ? new Rect($ox, $oy, $area->width, $area->height) : new Rect($ox + $a->x, $oy + $a->y, $a->width, $a->height);
            $place = $overlay->place instanceof Rect
                ? new Rect($ox + $overlay->place->x, $oy + $overlay->place->y, $overlay->place->width, $overlay->place->height)
                : $overlay->place;
            if (\is_string($place) && $place !== 'center' && $overlay->rect !== null && $overlay->placedFor == [$anchor, $root]) {
                // only its size changed: it stays on the side it is on while it still fits there
                $place = $overlay->rect->y >= $anchor->y + $anchor->height ? 'below' : 'above';
            }
            $rect = Placement::for($anchor, $root, self::desired($overlay->content, $cols, $rows), $place, $opener->keepUncovered($origin));
            $overlay->placedFor = [$anchor, $root];
            if ($rect != $overlay->rect) {
                $overlay->rect = $rect;
                $this->arrangeOverlays();
                $changed = false;
                if (self::$dirty !== []) {
                    $again = true;      // its content lays out for its new place first
                    break;
                }
            }
        }
        if ($changed || ($this->floating !== [] && $this->surface->layout() !== $this->overlayLayout)) {
            $this->arrangeOverlays();
        }
        foreach ($this->floating as $overlay) {
            if ($overlay->content->mounted && !$overlay->content->rendered && $overlay->content->surface->viewport() !== null) {
                $overlay->content->firstRender();
            }
        }
        return $again;
    }

    /** On a root: put its overlays where they were last placed. */
    private function arrangeOverlays(): void
    {
        $this->surface->setLayout($this->withOverlays($this->overlayLayout?->inner ?? $this->surface->layout()));
    }

    /**
     * The rectangle an overlay this component opens should leave uncovered when it can (in the
     * coordinates of the root at $origin): the buttons of the dialog it is in, so a dropdown in
     * a dialog goes the way that keeps them in view.
     */
    private function keepUncovered(Rect $origin): ?Rect
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if ($c instanceof Dialog && $c->overlaid !== null) {
                $buttons = $c->buttonsArea();
                return $buttons === null ? null : new Rect($buttons->x - $origin->x, $buttons->y - $origin->y, $buttons->width, $buttons->height);
            }
        }
        return null;
    }

    /**
     * The outer size $content would like as an overlay on a root of $cols x $rows: its
     * `width`/`height` when given, else what it measures (measured again every frame, so an
     * overlay whose content grows or shrinks is placed again), capped by `max-width`/`max-height`.
     *
     * @return array{int,int}
     */
    private static function desired(Component $content, int $cols, int $rows): array
    {
        $style = $content->style();
        $b = $style->border === false ? 0 : 2;
        $max = [$style->maxWidth, $style->maxHeight];
        $size = $content->measureFor(max(1, min($cols, $max[0] ?? $cols) - $b), max(1, min($rows, $max[1] ?? $rows) - $b));
        $out = [];
        foreach ([0 => [$content->attribute('width'), $cols], 1 => [$content->attribute('height'), $rows]] as $i => [$spec, $total]) {
            if (\is_int($spec)) {
                $out[$i] = $spec;
            } elseif (\is_string($spec) && str_ends_with($spec, '%')) {
                $out[$i] = (int) floor($total * (float) $spec / 100);
            } else {
                $out[$i] = min($size === null ? $total : $size[$i] + $b, $max[$i] ?? \PHP_INT_MAX);
            }
        }
        return $out;
    }

    /**
     * On a root, a mouse event before anything else gets it: outside a modal overlay it is
     * taken (nothing below gets it); a press outside a non-modal overlay and its opener
     * cancels it, and goes on.
     */
    private function overlayMouse(Mouse $mouse): bool
    {
        if ($this->parent !== null || $this->floating === []) {
            return false;
        }
        $inAbove = false;
        foreach (array_reverse($this->floating) as $overlay) {
            $rect = $this->surface->viewport($overlay->content->surface)?->rect();
            $inside = $rect !== null && $mouse->x >= $rect[0] && $mouse->x < $rect[0] + $rect[2] && $mouse->y >= $rect[1] && $mouse->y < $rect[1] + $rect[3];
            if ($overlay->modal) {
                return !$inside && !$inAbove;
            }
            if (!$inside && !$inAbove && $mouse->action === Mouse::PRESS) {
                $opener = $overlay->content->parent;
                $o = $this->surface->area();
                if ($opener === null || !$opener->surface->area()->contains($mouse->x + $o->x, $mouse->y + $o->y)) {
                    $overlay->cancel();
                }
            }
            $inAbove = $inAbove || $inside;
        }
        return false;
    }

    /** True if this component's surface shows on $surface, directly or through windows in between. */
    /**
     * True if another component holds this one among its children (a container it was given
     * to, a `Tabs` keeping it for a hidden tab): its owner's render does not dispose it then.
     */
    private function isHeld(): bool
    {
        foreach ($this->heldBy ?? [] as $holder => $_) {
            if ($holder->mounted && \in_array($this, $holder->children, true)) {
                return true;
            }
            unset($this->heldBy[$holder]);
        }
        return false;
    }

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
