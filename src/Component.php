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
    /** @var \WeakReference<Component>|null the running instance a render handed this new one's props to (see running()) */
    private ?\WeakReference $handedTo = null;
    /** @var \WeakMap<Component, true>|null the components whose render asked hasFocus(): they render again when it changes */
    private ?\WeakMap $focusReaders = null;
    /** @var \WeakMap<Surface, array{int, int, array<int, Surface>}>|null per root: the surfaces keys take the way of, with the versions they were found at */
    private static ?\WeakMap $focusPaths = null;
    private bool $rendered = false;
    private bool $renderPending = false;
    /** @var array{array{int,int}, list<Component>}|null unshownChildren() and the versions it was found at */
    private ?array $unshown = null;
    /** Its last layout waits for the parent to give it the size it asked for (see show()). */
    private bool $deferred = false;
    /** The pending render only lays out again what the focus changed (see markRestyle()). */
    private bool $restyleOnly = false;
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
    /** The element tree the last render laid out with layoutElement() (a ScrollView's content), see restyleNow(). */
    private ?Element $laidOut = null;
    /** The render in progress is a restyle: layoutElement() of the tree laid out last lays out only what the focus changed. */
    private bool $restyling = false;
    /** @var array{Stylesheet, ComputedStyle, int, list<string>}|null what the layout of $shown was made with: stylesheet, style, focus version, breakpoints */
    private ?array $shownWith = null;
    /** @var array<string, true> the keys of the children the last layout of $shown placed */
    private array $placed = [];
    /** @var array<int, Component> the started components it placed as themselves (another owner's, a kept instance), by object id */
    private array $placedSelf = [];
    /** Where the component was on the screen when it rendered last (see the `area` listener in start()). */
    private ?Rect $renderedAt = null;
    /** The layout is to be arranged again: a child's size changed (see rearrange()). */
    private bool $rearrange = false;
    /** The next render lays the tree out again, also when it is the same (see relayout()). */
    private bool $relayout = false;
    /** An instance the program keeps from render to render (see placedUnder()). */
    private bool $itself = false;
    /** @var array{?\WeakReference<Element>}|null the container the last element tree placed it in */
    private ?array $under = null;
    /** The latest focus request in its subtree when a render stopped showing it (see pendingRank()). */
    private ?int $hiddenRank = null;
    /** focus() was called before start(): it takes effect when the component starts. */
    private bool $focusOnStart = false;
    /** @var \WeakMap<Component, true>|null other components that show this one or keep it (see child()) */
    private ?\WeakMap $heldBy = null;
    private ?Stylesheet $stylesheet = null;
    /** The style a parent's element tree gives this component to inherit. */
    private ?ComputedStyle $inherited = null;
    /** @var array<string, array{int, int, ?array}> the rooms parents' layouts measured this component in since its last render, and the answers */
    private array $measured = [];
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
    /** @var array<int, \WeakReference<Component>> the roots started, in the order they were */
    private static array $roots = [];
    /** @var array<int, Component> the roots that have overlays open */
    private static array $floatingRoots = [];
    /** The component whose render() runs now (focus() remembers what renders ask for). */
    private static ?Component $inRender = null;
    /** The component whose render() is running: a State read now renders it again when written. */
    private static ?Component $reader = null;
    /** @var \WeakMap<Component, true>|null roots that give the focus to their first focusable component once it shows */
    private static ?\WeakMap $unfocused = null;
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
    /** @var \WeakMap<Component, string>|null components following the mouse (`:hover` rules, `hover:` classes, theirs or their elements'), and what the mouse was over then (hoverKey()) */
    private static ?\WeakMap $hoverAware = null;
    /** @var \WeakMap<Component, true>|null components whose bindings may have `anywhere` ones (see anywhere()) */
    private static ?\WeakMap $anywhere = null;
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
     * 'enter' => 'submit']`, a key in `Key::parse()` notation and a method name (or a closure,
     * given the `Key`), or `[method, description]` for a key that `Help` lists, with optional
     * flags: `'first' => true` runs it
     * before the focused component inside this one gets the key (a container's Ctrl-Tab),
     * `'always' => true` keeps it working while a modal dialog this component opened is open,
     * `'anywhere' => true` makes it work wherever the focus is, also outside this component,
     * when nothing on the way took the key and no other component on the screen binds that key
     * `anywhere` (a `Tabs`' Alt-1..9: with one `Tabs` on the screen they switch tabs from the
     * sidebar too; with two, each only while the focus is inside it; not while a modal dialog
     * is open).
     * The application changes them with `bind()`. A bound method runs when the key reaches the
     * component (see the class description on the order); one that returns false did not take
     * the key, which goes on. A render follows. `onKey()` is for what a map cannot say (any
     * letter, a key with a computed meaning); it runs on the way down (after the `first`
     * bindings) in a component the key passes, after all its bindings in the focused one.
     * The method name `'quit'`, on a component without a `quit()` method of its own, ends the
     * program (`Tui::quit()`): `'q' => ['quit', 'quit']`.
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
                State::flush();     // (arrays changed in place since they were read)
                if (self::$dirty !== [] && !self::$framing) {
                    self::renderFrame();
                }
            });
        }
        if ($on instanceof TerminalDevice) {
            $surface = new Surface();
            $on->attach($surface);
            // the terminal tells its background a moment later: the theme for it then
            $on->whenProbed(function (): void {
                if ($this->mounted && $this->theme === null) {
                    $this->renderSubtree();
                }
            });
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
                $callback = $this->attribute($e->focused ? 'onFocus' : 'onBlur');
                if ($callback !== null) {
                    $this->guard(fn() => $this->call($callback, $this));
                }
                $this->markDirty();
                Tree::focusMoved($this);      // (its place in the tree around it may look otherwise now)
                foreach ($this->focusReaders ?? [] as $component => $_) {
                    $component->markDirty();      // (its render asked hasFocus())
                }
                self::helpMayChange();
            }),
            $s->on('resize', function (Resize $e): void {
                $this->handle(fn() => $this->onResize($e));
                $this->markDirty();
            }),
            // what render() draws may depend on where the component is: draw again when it moved
            // (a window hidden draws nothing; one shown again where it was drawn last stands)
            $s->on('area', function (Rect $area): void {
                if ($this->parent !== null && $this->surface->viewport() === null) {
                    if ($this->rendered) {
                        $this->hiddenRank = $this->focusRank();     // (no longer shown: see pendingRank())
                    }
                    return;
                }
                if ($this->surface->root()->detached()) {
                    return;     // (in a window that is not shown: a hidden tab)
                }
                if ($this->renderedAt === null || $this->renderedAt != $area) {
                    $this->markDirty();
                }
            }),
            $s->on('close', fn() => $this->stop()),
        ];
        $this->guard($this->mount(...));
        foreach ($this->mounted ? self::normalizeBindings($this->bindings()) : [] as $binding) {
            if ($binding[4] ?? false) {
                self::$anywhere ??= new \WeakMap();
                self::$anywhere[$this] = true;
                break;
            }
        }
        if ($this->focusOnStart && $this->mounted) {
            $this->focusOnStart = false;
            $this->focus();
        }
        if ($this->parent === null) {
            unset(self::$roots[spl_object_id($this)]);
            self::$roots[spl_object_id($this)] = \WeakReference::create($this);
            self::$unfocused ??= new \WeakMap();
            self::$unfocused[$this] = true;
            $this->firstRender();
            self::focusFirst();
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
     * The instance that runs for this one: itself once started; for a new instance made by a
     * render (`new Editor()` inside a closure or `render()`) that the library matched by its
     * place (or `key:`) to the instance already running there and handed its props to, that
     * running instance; null for one that was never placed. A render's new instances are
     * descriptions: ask the running one what it shows (`$editor->running()?->value()`), or keep
     * one instance across renders (`$this->editor ??= new Editor()`). `Component::of($surface)`
     * gives the running component of a surface.
     */
    final public function running(): ?Component
    {
        if ($this->mounted || $this->stopped !== null) {
            return $this;
        }
        $to = $this->handedTo?->get();
        return $to?->running();
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
     * @internal The component whose `render()` is running, if any: a `State` read during it
     * renders that component again when it is written.
     */
    final public static function reader(): ?Component
    {
        return self::$reader;
    }

    /** @internal Run $code as if it were $reader's render, for what State reads (Tui runs a closure's first call this way). */
    final public static function readAs(Component $reader, \Closure $code): mixed
    {
        $before = self::$reader;
        self::$reader = $reader;
        try {
            return $code();
        } finally {
            self::$reader = $before;
        }
    }

    /**
     * @internal A render in the next frame that the library makes by itself (a state write, a
     * handler, a coroutine step): as `requestRender()`, except that the `focus()` calls of the
     * render that follows take effect only where they are new (see `focus()`).
     */
    final public function markDirty(): void
    {
        $this->sizes = [];
        $this->restyleOnly = false;
        $this->schedule();
    }

    /**
     * @internal The focus came to or left elements of this component's element tree (or
     * components it placed): in the next frame its tree lays those out again, without a render
     * (Tree::restyle()), unless a render is coming anyway.
     */
    final public function markRestyle(): void
    {
        if (!$this->renderPending && $this->schedule()) {
            $this->restyleOnly = true;
        }
    }

    /** Put the component in the next frame's renders; false when it is not to render now. */
    private function schedule(): bool
    {
        if (!$this->mounted || $this->renderPending || !$this->rendered || $this->rendering) {
            return false;     // (before the first render: that one is coming)
        }
        $this->renderPending = true;
        self::$dirty[spl_object_id($this)] = $this;
        if (TerminalDevice::of($this->surface->root())?->drawsFrames()) {
            return true;     // the terminal's next frame renders it (they are at most 60 a second)
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
        return true;
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
        self::focusFirst();
    }

    /**
     * A root whose program focused nothing yet, and whose keys would go to a component that
     * does not take them (a progress bar, a label), gives the focus to its first component
     * that takes keys (`canFocus()`), in the order they show (top to bottom, left to right),
     * once there is one: so the first field of a screen has the focus without a `focus()` call.
     */
    private static function focusFirst(): void
    {
        foreach (self::$unfocused ?? [] as $root => $_) {
            if (!$root->mounted || $root->focusRank() > 0) {
                unset(self::$unfocused[$root]);
                continue;
            }
            // (the keys go to the first window by default: that stands when it takes them)
            if (self::keyEnd($root->surface)?->canFocus()) {
                continue;
            }
            $first = self::focusOrder($root->surface)[0] ?? null;
            if ($first !== null) {
                unset(self::$unfocused[$root]);
                $first->focus();
            }
        }
    }

    /**
     * The stops of the Tab walk below $surface, in the order Tab visits them: the components
     * that take keys themselves (`canFocus()`) and are enabled, and the surfaces of the elements
     * with `onKey:` that hold no such stop (an element with `onKey:` around fields is not a
     * stop: its keys apply while the focus is inside it). In the order they show: by their
     * windows top to bottom, left to right, depth first. For a container widget that moves the
     * focus itself (the first field of a pane: `self::focusOrder($pane->surface)[0]?->focus()`).
     *
     * @return list<Component|Surface>
     */
    final protected static function focusOrder(Surface $surface): array
    {
        $out = [];
        foreach (self::tabViews($surface) as $v) {
            $c = self::$onSurface[$v->source] ?? null;
            if ($c !== null && (!$c->mounted || $c->attribute('disabled'))) {
                continue;
            }
            if ($c !== null && $c->canFocus()) {
                $out[] = $c;
            } elseif ($c === null && !$v->source->isFocusable()) {
                continue;
            }
            $inner = self::focusOrder($v->source);
            if ($c === null && $inner === [] && Tree::takesKeys($v->source)) {
                $out[] = $v->source;
            }
            array_push($out, ...$inner);
        }
        return $out;
    }

    /** The windows of $surface the Tab walk goes through, in the order it visits them: top to bottom, left to right. */
    private static function tabViews(Surface $surface): array
    {
        $views = [];
        $sorted = true;
        $last = null;
        foreach ($surface->viewports() as $v) {
            if ($v->width() > 0 && $v->height() > 0) {
                $at = [$v->y(), $v->x()];
                $sorted = $sorted && ($last === null || $last <= $at);
                $last = $at;
                $views[] = $v;
            }
        }
        if (!$sorted) {
            usort($views, fn(Viewport $a, Viewport $b) => [$a->y(), $a->x()] <=> [$b->y(), $b->x()]);
        }
        return $views;
    }

    /**
     * The stop of the Tab walk in $scope after $from (before it with $backwards), around, as
     * `focusOrder($scope)` has them; the first (the last) when $from is not one of them; null
     * when there is no other. It looks at the windows from $from on until it finds one, not at
     * every window of the screen: a Tab costs the same in a list of two hundred rows as in two.
     */
    private static function nextStop(Surface $scope, Component|Surface $from, bool $backwards): Component|Surface|null
    {
        $f = $from instanceof Component ? $from->surface : $from;
        // the windows from $from's up to $scope, if $from is a stop of the walk in $scope
        $chain = [];
        for ($s = $f; $s !== $scope; $s = $v->host()) {
            $v = $s->viewport();
            $c = self::$onSurface[$s] ?? null;
            if ($v === null || $v->width() <= 0 || $v->height() <= 0 || ($c !== null && (!$c->mounted || $c->attribute('disabled')))
                || ($c === null && !$s->isFocusable())) {
                $chain = null;
                break;
            }
            $chain[] = $v;
        }
        $isStop = $chain !== null && $chain !== [] && ($from instanceof Component
            ? self::$onSurface[$f] === $from && $from->canFocus()
            : !isset(self::$onSurface[$f]) && Tree::takesKeys($f) && self::stopIn($f, false) === null);
        if (!$isStop) {
            return self::stopIn($scope, $backwards);
        }
        if (!$backwards && $from instanceof Component && ($inner = self::stopIn($f, false)) !== null) {
            return $inner;      // (a component comes before what is inside it)
        }
        foreach ($chain as $v) {
            $host = $v->host();
            $views = self::tabViews($host);
            $at = array_search($v, $views, true);
            $after = $backwards ? array_reverse(\array_slice($views, 0, (int) $at)) : \array_slice($views, (int) $at + 1);
            foreach ($after as $w) {
                if (($stop = self::stopAt($w, $backwards)) !== null) {
                    return $stop;
                }
            }
            $c = self::$onSurface[$host] ?? null;
            if ($backwards && $host !== $scope && $c !== null && $c->canFocus()) {
                return $c;
            }
        }
        $around = self::stopIn($scope, $backwards);
        return $around === $from ? null : $around;
    }

    /** The first stop of the Tab walk inside $surface (the last with $last), or null. */
    private static function stopIn(Surface $surface, bool $last): Component|Surface|null
    {
        $views = self::tabViews($surface);
        foreach ($last ? array_reverse($views) : $views as $v) {
            if (($stop = self::stopAt($v, $last)) !== null) {
                return $stop;
            }
        }
        return null;
    }

    /** The first stop of the Tab walk in the window $v (the last with $last), or null. */
    private static function stopAt(Viewport $v, bool $last): Component|Surface|null
    {
        $src = $v->source;
        $c = self::$onSurface[$src] ?? null;
        if ($c !== null && (!$c->mounted || $c->attribute('disabled'))) {
            return null;
        }
        if ($c === null && !$src->isFocusable()) {
            return null;
        }
        $self = $c !== null ? ($c->canFocus() ? $c : null) : null;
        if (!$last && $self !== null) {
            return $self;
        }
        $inner = self::stopIn($src, $last);
        if ($inner !== null) {
            return $inner;
        }
        return $self ?? ($c === null && Tree::takesKeys($src) ? $src : null);
    }

    /**
     * Tab or Shift-Tab that nothing bound, at a component with keys of its own: the focus
     * goes to the next (or previous) such component in the order they show, around; inside the
     * topmost modal overlay while one is open. False when there is nowhere else to go.
     */
    private function walkFocus(bool $backwards): bool
    {
        $root = $this->rootComponent();
        $scope = $root->surface;
        foreach (array_reverse($root->floating) as $overlay) {
            if ($overlay->modal) {
                $scope = $overlay->content->surface;
                break;
            }
        }
        $next = self::nextStop($scope, $this->focusedElement() ?? $this, $backwards);
        $next?->focus();
        return $next !== null;
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
                    $restyle = $component->restyleOnly;
                    $component->restyleOnly = false;
                    if ($component->mounted) {
                        try {
                            $component->guard($restyle ? $component->restyleNow(...) : $component->renderNow(...));
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
            $restyle = $component->restyleOnly;
            $component->renderPending = false;
            $restyle ? $component->markRestyle() : $component->markDirty();
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
        $c = $this->shownFrom();
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
        return $this->ownKeys();
    }

    /**
     * True for a component that takes every key while it has the focus, as a program does: the
     * keys its own bindings and `onKey()` do not take go to its surface's program, not to the
     * bindings of the components around it (their plain ones, their elements' `onKey:`, and
     * `anywhere` ones); only their bindings marked `first` (which see the key on its way down)
     * or `always` (an application's quit key) and the runner's quit key (Ctrl-Q of `Tui::run()`)
     * come before it. A `Terminal` says so, so a shell gets Ctrl-D, Ctrl-R, Escape and the
     * arrows whatever its parents bind. Override it for a widget that wants the same (a game,
     * a raw key pad).
     */
    protected function takesEveryKey(): bool
    {
        return false;
    }

    /** True if the component has keys of its own: bindings, or an `onKey()`/`onPaste()` of its class. */
    private function ownKeys(): bool
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
     * take, so it is true exactly when the next key would reach the component. Asked of a new
     * instance that a render made and that was matched by its place to the instance running
     * there (`new Editor()` in a closure's render), it answers for the running one (see
     * `running()`); in the render that makes it, it is not matched yet (false): ask in a closure
     * of an element (`new Text(fn() => $field->hasFocus() ? '▸' : ' ')`), which runs after it.
     * Asked by a render, it subscribes as a `State` read does: that render runs again when the
     * answer changes.
     */
    final public function hasFocus(): bool
    {
        if (!$this->mounted) {
            // (a new instance a render handed over: the answer of the one running in its place)
            $running = $this->running();
            return $running !== null && $running !== $this && $running->hasFocus();
        }
        if (self::$reader !== null && self::$reader !== $this) {
            // (read by a render, as a State is: that component renders again when the answer changes)
            $this->focusReaders ??= new \WeakMap();
            $this->focusReaders[self::$reader] = true;
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
        // (an overlay opened by a component shown inside this one: the focus is inside it too)
        foreach ($this->rootComponent()->floating as $overlay) {
            $opener = $overlay->content->parent;
            if ($opener !== null && $opener !== $this && $opener->isShownIn($this->surface) && $overlay->content->hasFocus()) {
                return true;
            }
        }
        // keys come down from the root of the tree the nearest shown component is in
        return isset(self::focusPath($this->shownFrom()->surface->root())[spl_object_id($this->surface)]);
    }

    /**
     * The surfaces keys reaching $root go through, by object id: the way dispatch() gives them,
     * at a component to the child not shown yet it passes them to (see toUnshown()), else to
     * the window its surface focuses. Found once per focus and tree version and root, so a
     * render asking every component of a long list costs one walk, not one per component.
     *
     * @return array<int, Surface>
     */
    private static function focusPath(Surface $root): array
    {
        $versions = [Surface::focusVersion(), self::$treeVersion];
        self::$focusPaths ??= new \WeakMap();
        $cached = self::$focusPaths[$root] ?? null;
        if ($cached !== null && $cached[0] === $versions[0] && $cached[1] === $versions[1]) {
            return $cached[2];
        }
        $path = [];
        for ($s = $root; $s !== null && !isset($path[spl_object_id($s)]); $s = $next?->surface ?? $s->focused()) {
            $path[spl_object_id($s)] = $s;
            $on = self::$onSurface[$s] ?? null;
            $next = $on !== null && $on->mounted ? $on->unshownFocus() : null;
        }
        self::$focusPaths[$root] = [...$versions, $path];
        return $path;
    }

    /**
     * The nearest component from this one up that is shown in its root's tree: this one when
     * it is (or when it is a root), else the nearest ancestor that is. A component is not shown
     * when its surface is in no window, and also when it is in the windows of a component that
     * is not shown (inside the pane of a hidden tab).
     */
    private function shownFrom(): Component
    {
        $c = $this;
        while ($c->parent !== null) {
            $top = self::$onSurface[$c->surface->root()] ?? null;
            if ($top === null || $top->parent === null) {
                break;      // (in the tree of the program's root, or of a surface of its own)
            }
            $c = $top->parent;
        }
        return $c;
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
        foreach ($this->unshownChildren() as $child) {
            if (!$child->waitsUnshown($this)) {
                continue;
            }
            $first ??= $child;
            $rank = $child->pendingRank();
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
        foreach ($this->unshownChildren() as $child) {
            if ($child->waitsUnshown($this)) {
                $rank = max($rank, $child->pendingRank());
            }
        }
        return $rank;
    }

    /**
     * The children that are started and not shown (in no window): those waitsUnshown() may
     * be true of. Found once per tree and hosting version (keys and focus changes ask on every
     * key, and a list of two hundred rows has hundreds of children, all shown).
     *
     * @return list<Component>
     */
    private function unshownChildren(): array
    {
        $versions = [self::$treeVersion, Surface::hostingVersion()];
        if ($this->unshown === null || $this->unshown[0] !== $versions) {
            $list = [];
            foreach ($this->children as $child) {
                if ($child->mounted && $child->surface->viewport() === null) {
                    $list[] = $child;
                }
            }
            $this->unshown = [$versions, $list];
        }
        return $this->unshown[1];
    }

    /**
     * True if this child of $parent is not shown and keys reaching $parent may go on to it
     * (when it was focused last): not when another component shows it (a `Tabs` it was given
     * to: the keys go its way through that one), and not when a render stopped showing it after
     * it was focused (a hidden tab's shell does not keep the keys; a `focus()` after it was
     * hidden gives them to it again).
     */
    private function waitsUnshown(Component $parent): bool
    {
        return $this->mounted && $this->surface->viewport() === null && !($this->parent === $parent && $this->isHeld());
    }

    /** focusRank(), or 0 when this component was hidden by a render after its latest focus request. */
    private function pendingRank(): int
    {
        $rank = $this->focusRank();
        return $this->hiddenRank !== null && $rank <= $this->hiddenRank ? 0 : $rank;
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
        \fiber\go(function () use ($id, $task, &$started): void {
            // the task's own handle, for runs(): from fiber\current(), never from what go()
            // returned (opaque); a runtime that tells it only after a first suspension gets one
            if (current() === null) {
                \fiber\sleep(0);
            }
            $this->tasks[$id] = current();
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
    }

    /**
     * Sleep about $seconds, less if the component is disposed meanwhile; returns `isMounted()`.
     * The loop of a component's coroutine: `while ($this->sleep(0.5)) { ... }`. What the
     * coroutine changed before it sleeps shows in the next frame (a render follows).
     *
     * The sleep ends at a tick of the frame clock (60 a second): the first one after the time
     * asked for, or one at most 2 ms before it. So the coroutines of many components that sleep
     * about the same time wake together and are one frame, and a `sleep(0.1)` loop still makes
     * ten steps a second. For timing to the millisecond use `fiber\sleep()`.
     */
    final protected function sleep(float $seconds): bool
    {
        if ($this->mounted) {
            $this->markDirty();     // what the coroutine did until now shows in the next frame
            $this->wake->wait($seconds, inStep: true);
        }
        return $this->mounted;
    }

    // ------------------------------------------------------------------------------------------

    private function renderNow(): void
    {
        $this->renderedAt = $this->surface->area();
        $this->used = [];
        $laidOut = $this->laidOut;
        $this->laidOut = null;
        $this->rendering = true;
        $outer = self::$inRender;
        self::$inRender = $this;
        $this->focusAskedBefore = $this->asked ? [] : $this->focusAsked;
        $this->focusAsked = [];
        $this->asked = false;
        try {
            // the pen is the component's style: what render() clears (ESC [2J) or writes plainly is in its colours
            $style = $this->style();
            $pen = $style->text;
            if ($this->surface->pen() !== $pen) {
                $this->surface->write("\e[0m" . Ansi::style($pen));
            }
            if (!isset(self::$hoverAware[$this]) && $this->wantsPointer()) {
                $this->followPointer();
            }
            $reader = self::$reader;
            self::$reader = $this;
            try {
                $result = $this->render();
            } finally {
                self::$reader = $reader;
            }
            // the same tree as the last render's, in the same style and focus: what it laid out stands
            $same = $this->showsAgain($result, $style);
            if ($same) {
                $layout = null;
                $used = $this->used + $this->placed;
            } elseif ($this->restyling && $this->laidOut === $laidOut) {
                // (a restyle of the tree laid out with layoutElement(): what it placed stands)
                $layout = $this->layoutOf($result, true);
                $used = $this->used + $this->placed;
            } else {
                $layout = $this->layoutOf($result);
                $used = $this->used;
            }
        } catch (\Throwable $e) {
            $this->laidOut ??= $laidOut;
            throw $e;
        } finally {
            self::$inRender = $outer;
            $this->used = null;
            $this->rendering = false;
        }
        $this->sizes = [];      // (measured by what it rendered now)
        if ($this->tree !== null) {
            if (!isset(self::$hoverAware[$this]) && $this->tree->followsPointer()) {
                $this->followPointer();      // (an element with a hover: class: the mouse is followed)
            }
            // the focus entered or left elements with onFocus/onBlur
            foreach ($this->tree->takeFocusCalls() as [$callback, $element]) {
                $this->guard(fn() => $this->call($callback, $element));
            }
        }
        $returned = $same ? $this->surface->layout() : $layout;
        if (!$same) {
            $this->show($layout);
        } elseif ($this->rearrange) {
            $this->show($this->shownLayout());
        }
        foreach ($this->tree?->takeFocusRequests() ?? [] as $target) {
            $target->focus();       // (elements that became `focused: true`)
        }
        $this->firstShown();
        if ($returned !== null && !$this->restyling) {
            // (a restyle shows what the last render showed: nothing left the tree)
            foreach ($this->children as $key => $child) {
                if (!isset($used[$key]) && !isset($this->kept[$key]) && !isset($this->placedSelf[spl_object_id($child)])
                    && !$child->isShownIn($this->surface) && !$child->isHeld()) {
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
     * Children shown for the first time draw now, at the size the layout gave them (also
     * another owner's that this tree shows, which its owner's render did not lay out).
     */
    private function firstShown(): void
    {
        foreach ([...$this->children, ...$this->placedSelf] as $child) {
            if (!$child->rendered && $child->surface->viewport() !== null) {
                $child->firstRender();
            }
        }
    }

    /**
     * @internal The focus moved (to a component or an element): the `Help` elements on screen
     * show the keys of what has it, so they are laid out again in the next frame.
     */
    final public static function helpMayChange(): void
    {
        foreach (self::$helpShown ?? [] as $component => $_) {
            $component->tree?->helpMayChange();
        }
    }

    /**
     * Set the layout of a render (or a restyle) and tell the parent when the size it needs
     * changed. Then the parent lays it out again in this frame, at another size: the layout is
     * set after that, once, at the size it will have (a `Tabs` switched to a pane that fills,
     * from one of a row, does not first lay the pane out in one row and then again).
     */
    private function show(?Layout $layout): void
    {
        // a size it gave a parent's layout is another now: the items around it measure again
        // and the parent lays it out again (the rest of the parent's tree stands)
        // (the answers stand as given until the parent asks again: one it keeps from an earlier
        // frame is compared too; measureFor() notes the new answers)
        $resized = false;
        foreach ($this->measured as [$cols, $rows, $size]) {
            $resized = $this->measureFor($cols, $rows) !== $size || $resized;
        }
        if ($resized || ($this->leastAsked !== null && $this->leastNow($this->leastAsked[0]) !== $this->leastAsked[1])) {
            $this->leastAsked = null;
            Tree::sizeMayChange($this);
            $this->parent?->rearrange();
            if ($this->parent !== null && $layout !== null && !$this->deferred) {
                // (arranged when the parent gives it its size, else when it is arranged again
                // after the parent in this frame: the old layout is not arranged at the new size first)
                $this->deferred = true;
                $this->surface->deferLayout($layout);
                $this->rearrange();
                return;
            }
        }
        $this->deferred = false;
        $this->rearrange = false;
        if ($this->parent === null && ($this->floating !== [] || $this->overlayLayout !== null)) {
            $layout = $this->withOverlays($layout);
        }
        if ($layout !== null) {
            $this->surface->setLayout($layout);
        }
    }

    /**
     * The focus came to or left elements of the tree the last render returned (markRestyle()):
     * those are laid out again from that tree, render() is not called. A render instead when
     * anything else the layout reads changed since (the stylesheet, the component's style, a
     * child's size or style state).
     */
    private function restyleNow(): void
    {
        $sheet = $this->stylesheet();
        $style = $this->style();
        $last = $this->shownWith;
        $root = $this->shown ?? $this->laidOut;
        $possible = $this->tree !== null && $root !== null && $last !== null && !$this->relayout && $this->overlays === [] && $this->floating === []
            && $last[0] === $sheet && $last[1] === $style && $last[3] === $this->breakpoints();
        if ($possible && $this->shown === null) {
            // a tree laid out with layoutElement() (a ScrollView's content) where render() puts
            // it: render() runs, and its layoutElement() of the same tree restyles it
            $this->restyling = true;
            try {
                $this->renderNow();
            } finally {
                $this->restyling = false;
            }
            return;
        }
        $layout = $possible ? $this->tree->restyle($root, $sheet, $style) : false;
        if ($layout === false) {
            $this->renderNow();
            return;
        }
        if ($layout === null && $this->rearrange) {
            $layout = $this->shownLayout();     // (the same layout, arranged again)
        }
        $this->shownWith = [$sheet, $style, Surface::focusVersion(), $last[3]];
        foreach ($this->tree->takeFocusCalls() as [$callback, $element]) {
            $this->guard(fn() => $this->call($callback, $element));
        }
        if ($layout !== null) {
            $this->sizes = [];
            $this->show($layout);
            $this->firstShown();
        }
    }

    /**
     * The surfaces of the elements (and child components) in the state `selected` in the tree
     * the last render returned or `layoutElement()` laid out, in the order they were laid out:
     * for a container that keeps what is selected in view, as `ScrollView` does.
     *
     * @return list<Surface>
     */
    final protected function selectedSurfaces(): array
    {
        return $this->tree?->selectedSurfaces() ?? [];
    }

    /**
     * True if render() returned a tree that describes the same as the one shown (see
     * sameElement()), and nothing else the layout of it reads changed since: the stylesheet,
     * the component's style, the focus anywhere, a child's size or style state (relayout()).
     * Then the layout, the windows and the paint of the last render stand as they are, and a
     * render that changed nothing costs the render() call and the comparison.
     */
    private function showsAgain(Layout|Element|null $result, ComputedStyle $style): bool
    {
        $last = $this->shownWith;
        if (!$result instanceof Element || $this->shown === null || $last === null || $this->relayout
            || $this->overlays !== [] || $this->floating !== [] || $this->tree?->showsHelp !== false) {
            return false;
        }
        return $last[0] === $this->stylesheet() && $last[1] === $style && $last[2] === Surface::focusVersion() && $last[3] === $this->breakpoints()
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

    /**
     * A child's size changed (its items in this component's tree measure again, see
     * Tree::sizeMayChange()): the layout shown is arranged again in the next frame, without a
     * render. A clock or a counter in a `Text` closure that grows a column wider does not run
     * the render of the screen around it.
     */
    private function rearrange(): void
    {
        $this->rearrange = true;
        $this->markRestyle();
    }

    /** The layout of the last render on the surface (inside the overlays a root hosts). */
    private function shownLayout(): ?Layout
    {
        $layout = $this->surface->layout();
        return $layout !== null && $layout === $this->overlayLayout ? $this->overlayLayout->inner : $layout;
    }

    /** The layout for what render() returned (an element tree laid out). */
    private function layoutOf(Layout|Element|null $result, bool $restyled = false): ?Layout
    {
        foreach ($this->overlays as $key => $_) {
            $this->child($key);         // (keeps their content)
        }
        $this->shown = $result instanceof Element ? $result : null;
        $this->relayout = false;
        if (!$restyled) {
            $this->placed = $this->placedSelf = [];
        }
        $this->shownWith = null;
        if ($result instanceof Element) {
            $this->laidOut = null;
            $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...), $this->runElementHandler(...));
            $sheet = $this->stylesheet();
            $style = $this->style();
            $layout = $this->tree->build($result, $sheet, $style);
            $this->shownWith = [$sheet, $style, Surface::focusVersion(), $this->breakpoints()];
            self::$helpShown ??= new \WeakMap();
            if ($this->tree->showsHelp) {
                self::$helpShown[$this] = true;
            } else {
                unset(self::$helpShown[$this]);
            }
            return $layout;
        }
        if ($this->laidOut !== null) {
            // (a tree laid out with layoutElement(): restyled on a focus change, see restyleNow())
            $this->shownWith = [$this->stylesheet(), $this->style(), Surface::focusVersion(), $this->breakpoints()];
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
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...), $this->runElementHandler(...));
        // (by the items its last render laid out; a tree it did not lay out last is measured dry)
        $built = $this->tree->measureBuilt($this->shown, $cols, $rows);
        return $built !== false ? $built : $this->tree->measure($this->shown, $cols, $rows, $this->stylesheet(), $this->style(), place: false);
    }

    /**
     * $element laid out as a layout of this component's, without setting it: for a component
     * that shows an element tree somewhere other than its own surface (a `ScrollView` puts it
     * on a surface taller than itself). The surfaces and child components behind the elements
     * persist across calls as they do for `render()`; call it from `render()`, once per render.
     * When the focus moves inside the tree, the component renders again and the same element
     * (the same object: a prop that did not change) is laid out again only where the focus
     * changed its look, as a `render()` that returns its tree is (a Tab in a `ScrollView` of two
     * hundred rows costs what it costs in one of two).
     */
    final protected function layoutElement(Element $element): Layout
    {
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...), $this->runElementHandler(...));
        $sheet = $this->stylesheet();
        $style = $this->style();
        // (what the layout asks, a component's hasFocus() for its `:focused` look, is not a read
        // of the render: the tree follows the focus itself, as for a tree render() returns)
        $reader = self::$reader;
        self::$reader = null;
        try {
            if ($this->restyling && $this->laidOut === null && $element === $this->tree->builtFor()) {
                $layout = $this->tree->restyle($element, $sheet, $style);
                if ($layout !== false) {
                    $this->laidOut = $element;
                    return $layout ?? $this->tree->builtLayout();
                }
            }
            $this->laidOut = $element;
            return $this->tree->build($element, $sheet, $style);
        } finally {
            self::$reader = $reader;
        }
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
        $this->tree ??= new Tree($this, $this->place(...), $this->helpFor(...), $this->runElementHandler(...));
        $reader = self::$reader;
        self::$reader = null;       // (as in layoutElement())
        try {
            // (the tree laid out last: measured by its items, as a parent measures a render's tree)
            $built = $element === $this->laidOut ? $this->tree->measureBuilt($element, $cols, $rows) : false;
            return $built !== false ? $built : $this->tree->measure($element, $cols, $rows, $this->stylesheet(), $this->style());
        } finally {
            self::$reader = $reader;
        }
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
        if ($fresh->mounted) {
            // started already (an instance the program kept, or another component's): itself
            if ($create) {
                $this->placedSelf[spl_object_id($fresh)] = $fresh;
            }
            return $fresh;
        }
        if ($fresh->stopped !== null) {
            throw new \LogicException($fresh::class . ' was placed in the tree again after it left the tree, which stopped it '
                . '(an instance that leaves the tree is stopped): place a new instance instead (forget the old one when its row goes: '
                . 'unset($bars[$i])), or keep it in the tree with the class \'hidden\' while it should not show');
        }
        if ($create) {
            $this->placed[$key] = true;
        }
        $existing = $this->child($key);
        if ($existing !== null && ($existing->itself || isset($this->tree?->present[spl_object_id($existing)]))) {
            // a kept instance the program shows as itself (wherever it is now) is never taken
            // over by a new one: it keeps its key elsewhere, the new instance is a child of its own
            if (!$create) {
                return null;
            }
            unset($this->children[$key]);
            $this->children["\0i:" . spl_object_id($existing)] = $existing;
            if (isset($this->kept[$key])) {
                unset($this->kept[$key]);
                $this->kept["\0i:" . spl_object_id($existing)] = true;
            }
            self::$treeVersion++;
            return $this->child($key, fn() => $fresh);
        }
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

    /**
     * @internal An element tree places this component (started already, or new: its first place)
     * as the root). Placed under another container than last time, it is an instance the
     * program kept from one render to the next: from then on a new instance placed where it is
     * does not take its props over, wherever this one moves (see place()).
     */
    final public function placedUnder(?Element $parent): void
    {
        $was = $this->under;
        if ($was !== null && ($was[0]?->get()) !== $parent) {
            $this->itself = true;       // in a container of another render: the program kept it
        }
        $this->under = [$parent === null ? null : \WeakReference::create($parent)];
    }

    /**
     * The component whose surface $surface is (the root component on a terminal device's root,
     * a component's `$this->surface`), or null when none is mounted on it.
     */
    final public static function of(Surface $surface): ?Component
    {
        $component = self::$onSurface[$surface] ?? null;
        return $component !== null && $component->mounted ? $component : null;
    }

    /** Take the props (public properties) and attributes of $fresh, a new instance of this class. */
    private function receive(Component $fresh): void
    {
        $fresh->handedTo = \WeakReference::create($this);
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
     * else its parent's, up to the root; the theme in force (`theme()`) when none was set. A
     * sheet built on a theme (`Stylesheet::default()->with(...)`) is its rules on the theme in
     * force.
     */
    final public function stylesheet(): Stylesheet
    {
        if ($this->stylesheet !== null) {
            $sheet = $this->stylesheet;
        } elseif ($this->parent !== null) {
            $sheet = $this->parent->stylesheet();
            if ($this->theme === null) {
                return $sheet;      // (on the theme the parent is on)
            }
        } else {
            return Stylesheet::theme($this->theme());
        }
        return $sheet->themeName() === null ? $sheet : $sheet->onTheme($this->theme());
    }

    /**
     * The theme this component is drawn in (see `Stylesheet::theme()`): the one set with
     * `setTheme()` on it or the nearest component above it, else the one for the terminal's
     * background (`TerminalDevice::background()`: `light` on a light one, else `dark`).
     */
    final public function theme(): string
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if ($c->theme !== null) {
                return $c->theme;
            }
            if ($c->parent === null) {
                return $c->mounted && TerminalDevice::of($c->surface->root())?->background() === 'light' ? 'light' : 'dark';
            }
        }
        return 'dark';
    }

    /**
     * Draw this component and those below it in the theme $name (`dark`, `light`, `mono`), or,
     * with null, in the one for the terminal's background again. A stylesheet set with
     * `setStylesheet()` that is built on a theme (`Stylesheet::default()->with(...)`) keeps its
     * rules on the new one. `Tui::run($root, theme: ...)` sets it on the root.
     *
     * @throws \InvalidArgumentException for a theme that does not exist
     */
    final public function setTheme(?string $name): void
    {
        if ($name !== null) {
            Stylesheet::theme($name);       // (it exists)
        }
        if ($name !== $this->theme) {
            $this->theme = $name;
            $this->renderSubtree();
        }
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

    /** The theme set with `setTheme()`, null: the one above, or the terminal's. */
    private ?string $theme = null;

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
        if ($style->hoverAware && !isset(self::$hoverAware[$this])) {
            $this->followPointer();
        }
        return $style;
    }

    /**
     * $text with its markup (`<b>`, `<fg=red>`, `<span class="...">`, see `Markup`) turned into
     * escape sequences, for text drawn in the style of this component, or of its $part in
     * $states: how a widget shows labels that take markup. The text ends in that style again.
     *
     *     $this->surface->write(Ansi::style($row->text) . $this->markup($label, 'row', 'current'));
     *
     * @param string|list<string>|null $part
     */
    final protected function markup(string $text, string|array|null $part = null, string ...$states): string
    {
        return str_contains($text, '<') ? Markup::toAnsi($text, $this->style($part, ...$states), $this->stylesheet()) : $text;
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

    /** Ask for motion reports (once) and keep what the mouse is over (hoverKey()). */
    private function followPointer(): void
    {
        if (!$this->mounted) {
            return;
        }
        self::$hoverAware ??= new \WeakMap();
        if (!isset(self::$hoverAware[$this])) {
            // motion reports without a button held, so the mouse is followed (the device asks for them)
            $this->surface->write("\e[?1003h");
        }
        self::$hoverAware[$this] = $this->hoverKey();
    }

    /** What the mouse is over of this component: itself, and which of its elements that follow it. */
    private function hoverKey(): string
    {
        return ($this->isHovered() ? 'h' : '') . "\0" . ($this->tree?->hovered() ?? '');
    }

    /**
     * @internal True if the mouse (as last reported) is over $surface where it shows: how an
     * element is in the `hover` state.
     */
    final public static function pointerOver(Surface $surface): bool
    {
        if (self::$pointer === null) {
            return false;
        }
        [$root, $x, $y] = self::$pointer;
        return $root->get() === $surface->root() && $surface->area()->contains($x, $y);
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
        if (isset($this->measured[$key]) || \count($this->measured) < 16) {
            $this->measured[$key] = [$cols, $rows, $size];
        }
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
            $now = $component->hoverKey();
            if ($now !== $was) {
                self::$hoverAware[$component] = $now;
                $component->relayout();     // (its elements' hover states are laid out again too)
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

    /** @return list<string> the breakpoints in force on the screen it is on (`sm` ... `xl`): states of every element of its tree */
    private function breakpoints(): array
    {
        return $this->mounted ? Stylesheet::breakpoints($this->surface->root()->cols()) : [];
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
        array_push($states, ...$this->attribute('state'));
        if ($this->mounted) {
            array_push($states, ...Stylesheet::breakpoints($this->surface->root()->cols()));     // (sm: ... xl:)
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
        if (isset($this->styleStates[$state]) || \in_array($state, $this->attribute('state'), true)) {
            return true;
        }
        // (the built-in states are worked out; another one is only a state of its own or of its element)
        return ($state === 'hover' || $state === 'focused' || $state === 'disabled' || isset(Stylesheet::BREAKPOINTS[$state]))
            && \in_array($state, $this->states(), true);
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
        if ($mine && ($this->elementKeys($key) || $this->runBinding($key) || $this->handle(fn() => $this->onKey($key)))) {
            return true;
        }
        // (a component that takes every key, a Terminal: of its parents only the bindings marked
        // `always` come before its program)
        $every = $mine && $this->takesEveryKey();
        foreach ([$this, ...$this->bubbling()] as $c) {
            // (a parent the key passed on its way down had its `first` bindings asked then;
            // the elements of its tree around this component are inside it: they come first)
            if ($c !== $this && !$c->attribute('disabled') && ((!$every && !$c->suspended() && $c->elementKeys($key))
                || $c->runBinding($key, $every || $c->suspended(), $this->isShownIn($c->surface) ? false : null))) {
                return true;
            }
            if ($c === $this && $this->suspended() && $this->runBinding($key, true)) {
                return true;
            }
            if ($c->overlaid !== null && !$c->overlaid->modal && (string) $key === 'escape' && ($c === $this || !$every)) {
                $c->overlaid->cancel();
                return true;
            }
        }
        $modal = array_filter($this->rootComponent()->floating, fn(Overlay $o) => $o->modal) !== [];
        if ($mine && !$every && !$modal && ($c = self::anywhere($this->surface->root(), (string) $key)) !== null && $c !== $this && !\in_array($c, $this->bubbling(), true)) {
            return $c->runBinding($key);    // (a key bound `anywhere` by one component on the screen)
        }
        $root = $this->rootComponent();
        if ($root->quitKey !== null && (string) $key === $root->quitKey) {
            $root->dispose();       // (Tui::run(): nothing bound the key)
            return true;
        }
        $name = (string) $key;
        if (($name === 'tab' || $name === 'shift+tab') && $mine && ($this->ownKeys() || $this->focusedElement() !== null)) {
            // (at a component or an element with keys of its own; a Terminal's program keeps its Tab)
            return $this->walkFocus($name === 'shift+tab');
        }
        return false;
    }

    /**
     * Where a key this component does not take goes on to, in order: its parent (the owner,
     * whose render or child() made it) and the parent's parents, and before an owner the
     * components it is shown in below that owner (a `Tabs` and its pane around a `Tree` the
     * owner made itself and gave the Tabs as a tab's content). So the keys in force at a
     * component (and what `Help` lists) do not depend on whether the owner kept it with
     * child() or made it anew in every render.
     *
     * @return list<Component>
     */
    private function bubbling(): array
    {
        $out = [];
        for ($c = $this; ($p = $c->parent) !== null; $c = $p) {
            if ($c->isShownIn($p->surface)) {
                for ($s = $c->surface->parent(); $s !== null && $s !== $p->surface; $s = $s->parent()) {
                    $h = self::$onSurface[$s] ?? null;
                    if ($h !== null && $h->mounted && $h !== $this && !\in_array($h, $out, true)) {
                        $out[] = $h;
                    }
                }
            }
            if (!\in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * The elements of this component's tree on the focus path that have `onKey`, innermost
     * first: the elements around the focus, down to the next component.
     *
     * @return list<Element>
     */
    private function keyElements(): array
    {
        if ($this->tree === null) {
            return [];
        }
        $out = [];
        for ($s = $this->surface; $s !== null; $s = $s->focused()) {
            if ($s !== $this->surface && (self::$onSurface[$s] ?? null)?->mounted) {
                break;      // (a component: its elements are its own)
            }
            $element = $this->tree->elementAt($s);
            if ($element !== null && $element->attribute('onKey') !== null && !$element->attribute('disabled')) {
                array_unshift($out, [$element, $s]);
            }
        }
        return $out;
    }

    /** The surface of the innermost element with `onKey` that has the focus in this component's tree, or null. */
    private function focusedElement(): ?Surface
    {
        return $this->keyElements()[0][1] ?? null;
    }

    /**
     * The focused elements' `onKey`, innermost first; true when one took $key. A bindings array
     * runs its actions as the component's own bindings do (see runAction()): a method name is a
     * method of this component (the one whose render made the element), `'quit'` ends the
     * program, a closure is given the key and the element.
     */
    private function elementKeys(Key $key): bool
    {
        foreach ($this->keyElements() as [$element]) {
            $on = $element->attribute('onKey');
            if (\is_array($on)) {
                $binding = self::normalizeBindings($on)[(string) $key] ?? null;
                if ($binding !== null && $this->runAction($binding[0], $key, $element)) {
                    return true;
                }
            } elseif ($this->runElementHandler($on, $key, $element) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run a handler of an element of this component's tree (`onKey`, `onMouse`) as one of the
     * component's own: an exception is contained as in its hooks, and a render follows.
     */
    private function runElementHandler(\Closure $handler, mixed ...$args): mixed
    {
        if (!$this->mounted) {
            return false;
        }
        $result = $this->guard(fn() => $this->call($handler, ...$args));
        $this->markDirty();
        return $result;
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
        return $this->runAction($binding[0], $key);
    }

    /**
     * Run the action of a key binding, the same wherever it was bound (`bindings()`, `bind()`,
     * `Tui::run(keys:)`, an element's `onKey:` array): a closure runs as a handler of this
     * component's, given $key (and the element, for an element's), and takes the key whatever
     * it returns (`fn() => $s->open = false` gives false and took it); a method name calls that
     * public method of this component, which takes the key unless it returns false; `'quit'`
     * ends the program (`Tui::quit()`) when the component has no `quit()` of its own. A failure
     * is contained as in the component's hooks. True if the key was taken.
     */
    private function runAction(string|\Closure $action, Key $key, ?Element $element = null): bool
    {
        if ($action === 'quit' && !method_exists($this, 'quit')) {
            $action = static fn() => Tui::quit();      // (the library's: the program ends)
        }
        if ($action instanceof \Closure) {
            $args = $element === null ? [$key] : [$key, $element];
            $this->guard(fn() => $this->call($action, ...$args));
            $this->markDirty();
            return true;
        }
        $public = method_exists($this, $action) ? new \ReflectionMethod($this, $action) : null;
        if ($public === null || !$public->isPublic()) {
            throw new \LogicException(static::class . ": the key '$key' is bound to '$action', which is not a public method"
                . ($element === null ? '' : ' of the component that rendered the element (bind a closure for anything else)'));
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
     * method name or `[method, description]` binds a key, null unbinds it; a closure in place
     * of the method runs instead (`['ctrl+r' => [fn() => $this->reload(), 'reload']]`, given the
     * `Key`; the key is taken whatever it returns). The nearest setting wins: an instance's
     * own, then those of its nearest parent, up to the root. With $first, a binding that does
     * not say otherwise is marked `first` (see `bindings()`). Returns $this.
     *
     * @param array<string, string|\Closure|array<int|string, mixed>|null> $bindings
     */
    final public function bind(array $bindings, ?string $for = null, bool $first = false): static
    {
        $normal = self::normalizeBindings($bindings, $first);
        self::$anywhere ??= new \WeakMap();
        self::$anywhere[$this] = true;      // (looked at on keys nobody took: see anywhere())
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
     * [method name or closure, description or null, always, first, anywhere] by key notation (`ctrl+s`).
     *
     * @return array<string, array{string|\Closure, ?string, bool, bool, bool}>
     */
    final public function bindingsInForce(): array
    {
        $map = self::normalizeBindings($this->bindings());
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
     * @internal Bindings in the notation of `bindings()` as [action, description or null,
     * always, first, anywhere] by key notation; an action is a method name or a closure. $first is what
     * a binding that does not say is (`Tui::run()`'s keys run before the focused component's).
     *
     * @param array<string, mixed> $bindings
     * @return array<string, array{string|\Closure, ?string, bool, bool, bool}|null>
     * @throws \InvalidArgumentException for an unknown key or an action that is neither
     */
    final public static function normalizeBindings(array $bindings, bool $first = false): array
    {
        $out = [];
        foreach ($bindings as $spec => $action) {
            $key = (string) Key::parse((string) $spec);
            $do = \is_array($action) ? ($action[0] ?? null) : $action;
            if ($action === null) {
                $out[$key] = null;
                continue;
            }
            if (!\is_string($do) && !$do instanceof \Closure) {
                throw new \InvalidArgumentException("The key '$spec' is bound to " . get_debug_type($do) . ': bind a method name or a closure');
            }
            $out[$key] = \is_array($action)
                ? [$do, isset($action[1]) ? (string) $action[1] : null, (bool) ($action['always'] ?? false), (bool) ($action['first'] ?? $first), (bool) ($action['anywhere'] ?? false)]
                : [$do, null, false, $first, false];
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
        $at = $for?->running() ?? $for ?? self::keyEnd($this->surface->root()) ?? $this;
        $out = [];
        foreach ([$at, ...$at->bubbling()] as $c) {
            if ($c->attribute('disabled') || ($show === 'focused' && $c !== $at) || ($show === 'application' && $c === $at)) {
                continue;
            }
            // the keys of the focused elements of its tree come first (they are inside it)
            foreach ($c->keyElements() as [$element]) {
                $on = $element->attribute('onKey');
                foreach (\is_array($on) ? self::normalizeBindings($on) : [] as $key => $binding) {
                    if ($binding !== null && $binding[1] !== null && !$c->suspended()) {
                        $out[$key] ??= $binding[1];
                    }
                }
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

    /**
     * The one component shown on $root that binds $key `anywhere` (see `bindings()`), or null
     * when none does or more than one does.
     */
    private static function anywhere(Surface $root, string $key): ?Component
    {
        $found = null;
        foreach (self::$anywhere ?? [] as $c => $_) {
            if (!$c->mounted || $c->attribute('disabled') || $c->surface->root() !== $root || $c->surface->viewport() === null
                || $c->shownFrom() !== $c || !(($c->bindingsInForce()[$key] ?? null)[4] ?? false)) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $c;
        }
        return $found;
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
        $path = [];
        for ($s = $root->surface; ($next = $s->focused()) !== null; $s = $next) {
            $path[] = $next;
        }
        $key = "\0o:" . ++self::$overlayCount;
        $overlay = new Overlay($content, $anchor, $place, $modal, $content instanceof Dialog ? $content->cancel : null, end($path) ?: $root->surface,
            fn(Overlay $o) => $this->closeOverlay($key, $o));
        $overlay->focusPath = $path;
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

    /**
     * @internal True while a modal overlay (a dialog) is open in this component's tree: a
     * handler waiting now may wait for its answer, which the next input gives.
     */
    final public function waitsForAnswer(): bool
    {
        foreach ($this->rootComponent()->floating as $overlay) {
            if ($overlay->modal && $overlay->isOpen()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @internal The root of the running program, for what opens on it from anywhere
     * (`Dialog::open()`): the root started last that is still mounted and shown on a terminal
     * device, else the one started last that is still mounted; null when none is.
     */
    final public static function runningRoot(): ?Component
    {
        $fallback = null;
        foreach (array_reverse(self::$roots, true) as $id => $ref) {
            $root = $ref->get();
            if ($root === null || !$root->mounted) {
                unset(self::$roots[$id]);
                continue;
            }
            if ($root->device() !== null) {
                return $root;
            }
            $fallback ??= $root;
        }
        return $fallback;
    }

    /** @internal dialog() for Dialog::open(): $dialog over this root, one at a time. */
    final public function openDialog(Dialog $dialog): object
    {
        return $this->dialog($dialog, "\0open");
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
                    // back where the keys went when it opened: the deepest window of that path
                    // that is still shown (a field a render made anew leaves its component)
                    $back = null;
                    foreach (array_reverse($overlay->focusPath) as $s) {
                        if ($s->root() === $root->surface) {
                            $back = $s;
                            break;
                        }
                    }
                    if ($back !== null) {
                        $back->focus();
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
            $anchor = $opener->framed($anchor, new Rect($ox, $oy, $area->width, $area->height));
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

    /**
     * $anchor (a rectangle of this component, $own its whole area, both in the root's
     * coordinates as placeOverlays() has them) reaching out to the border of the window this
     * component fills alone (an `Editor` that is a `Box`'s only content): an overlay below its
     * last row goes under the border, not over it; one above its first row over the border.
     */
    private function framed(Rect $anchor, Rect $own): Rect
    {
        $host = $this->surface->parent();
        $frame = $host?->viewport();
        if ($host === null || $frame === null || !$frame->hasBorder() || \count($host->viewports()) !== 1) {
            return $anchor;
        }
        $hostArea = $host->area();
        $area = $this->surface->area();
        // (the host's rows in the coordinates of $own: the border is the row above and the row under them)
        $top = $own->y - ($area->y - $hostArea->y) - 1;
        $bottom = $own->y - ($area->y - $hostArea->y) + $hostArea->height;
        $y1 = $anchor->y <= $own->y ? $top : $anchor->y;
        $y2 = $anchor->y + $anchor->height >= $own->y + $own->height ? $bottom + 1 : $anchor->y + $anchor->height;
        return new Rect($anchor->x, $y1, $anchor->width, max(1, $y2 - $y1));
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
        // the class without its namespace and the message, on as many rows as there are (one: cut with …)
        $class = $this->error::class;
        $message = substr($class, (int) strrpos('\\' . $class, '\\')) . ': ' . str_replace(["\r", "\n", "\t", "\e"], ' ', $this->error->getMessage());
        $width = max(1, $s->cols() - 2);
        $lines = Text::wrap($message, $width, true);
        if (\count($lines) > $s->rows()) {
            $lines = \array_slice($lines, 0, max(1, $s->rows()));
            $last = \count($lines) - 1;
            $lines[$last] = Text::truncate($lines[$last] . ' …', $width);
        }
        $out = "\e[0m\e[?25l\e[?7l\e[2J";
        foreach ($lines as $y => $line) {
            $out .= Ansi::moveTo(0, $y) . ($y === 0 ? "\e[1;31m✖ " : "\e[31m  ") . $line;
        }
        $s->write($out . "\e[0m");
    }
}
