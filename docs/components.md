# Components

A `Component` is a piece of user interface: it owns a Surface, has a lifecycle and a coroutine,
child components, and event handlers. The surface is a terminal like any other, so a component
draws by writing escape sequences into it, or arranges child components with a layout. A
component tree is the same structure as everything else: surfaces hosting surfaces.

```php
use phasync\Tui\{Ansi, Component, Layout, Tty};

final class Clock extends Component
{
    protected function render(): ?Layout
    {
        $this->surface->write(Ansi::clearScreen() . Ansi::moveTo(0, 0) . date('H:i:s'));
        return null;                                // drew itself
    }

    protected function run(): void
    {
        while ($this->sleep(1)) {                   // false once disposed
            $this->requestRender();
        }
    }
}

fiber\run(function () {
    $clock = (new Clock())->start(new Tty());       // on the terminal, at its size
    $clock->surface->on('key', fn() => $clock->dispose());     // any key quits
});                                                 // returns once it has quit
```

## One file, no class

A screen that only shows state needs no class: an element tree and a runner.

```php
use phasync\Tui\{State, Tui};
use phasync\Tui\Element\{Column, Text};
use phasync\Tui\Component\ProgressBar;

fiber\run(function () {
    $s = new State(['done' => 0]);
    fiber\go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { fiber\sleep(0.05); $s->done = $i; } });
    Tui::inline(fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
    ]));
});
```

**A closure is a component.** `Tui::run()`, `Tui::inline()` and `Tui::headless()` take an
element, a component, or a closure that returns either. The closure is rendered (called) when
the screen is shown and again whenever a `State` it read changes (see "State" below): here,
every write of `$s->done`. It has no keys of its own, and quits on Ctrl-Q like every program
run by `Tui`. A closure that reads no state is called once, and the component it returns is the
root itself (`Tui::run(fn() => new App())`).

**Element content may be a closure**, so one value can change in a screen that is otherwise
built once: `new Text(fn() => "{$s->done}%")`, a `Box` title `fn() => "log ({$s->lines})"`, a
`ProgressBar` value `fn() => $s->done / 100`. Such an element is a small component of its own:
when a `State` its closure read changes, only it is drawn again, and the component that placed
it does not render.

`fiber\go()` must be called inside `fiber\run()` (fiber-interop/api throws `NotInRunException`
otherwise), which is why the program above is wrapped in one; the runners work inside a
`fiber\run()` of yours. Classes stay for anything with behaviour of its own (keys, coroutines
that belong to it, children kept by key); a closure component is the same thing without a name.

## Lifecycle

1. `start(Surface|TerminalDevice|null $on = null)` mounts a root component: on a terminal
   device (`new Tty()`, or a `FakeTerminal`), which gets a new root surface attached first so the
   first render is for the terminal's size; on a surface of yours; or on a new 80x24 surface that
   you attach or host afterwards (it renders again at the size it gets). Child components are
   started by their parent's `child()`.
2. `mount()` runs once: set up props and state here, and create children. The surface does not
   have its real size yet.
3. Once the component has its real size, `sized()` runs once, then the first `render()`, then
   `run()` starts as a coroutine. A root has its size at once (the terminal device's, or that of
   the surface you gave it). A child has it when its parent's render has placed it in a layout:
   it never draws for a guessed size, and a child that no render shows waits (is not sized,
   rendered or run) until a render shows it.
   **The rule for component authors: `mount()` is for props and state; anything that depends on
   the rectangle starts in `sized()`**: a program that reads its terminal size once when it
   starts (the built-in `Terminal` starts its program there), text laid out for the width, a
   view computed for the height. Later sizes arrive with `onResize()` and a render.
4. `render()` runs again whenever the component's **area** changed, and after
   `requestRender()` (batched: calls before the next frame give one render; see "Frames"
   below). The area is the rectangle the component has: its size
   (`$this->surface->cols()`/`rows()`) and where it shows on the screen
   (`$this->surface->area()`, a `Rect` in root coordinates). The library renders again when
   either changed since the last render, whatever changed it: the terminal was resized, a
   layout re-arranged (a parent's render that moves or resizes it, a nested layout around it
   that moved), or a window it is in was moved, resized or scrolled by hand. So a `render()`
   that reads its size or position (a status line that shows the size, a widget that lays
   itself out, a pop-up placed next to another window) is never stale, and needs no resize
   handler of yours.

   Together with `sized()`: `sized()` runs once, before the first render, when the area is
   first known; every later change of the area is followed by a render (and a change of size
   by `onResize()` first).
5. `dispose()` ends it: children are disposed first, the `stopped()` awaitable resolves and
   `sleep()` returns false, so the component's coroutines know they are to end, and it waits
   for the end: `unmount()` runs when the last of its coroutines has returned and its children
   have ended (each the same way, so a parent unmounts after its children), or after `$timeout`
   (one second) at the latest, when a coroutine does not listen. **When `dispose()` returns,
   `unmount()` has run** (a dialog closed in a key handler has let go of what it held).
   `fiber\run()` returns only when everything has ended. The surface keeps its last content. A
   component is disposed by its parent (when a render neither asks for it nor shows it, or the
   parent ends), by `dispose()`, or when its surface is closed (`Surface::close()`); in these
   other cases nothing waits (a render, or detaching the terminal, does not stall while a
   component winds down), and the end follows the same way. A disposed child is forgotten at
   once: `child($key)` gives null for it (or, with a factory, a new one).

   **Disposing from the component's own coroutine** (`$this->dispose()` in `run()` or a `go()`,
   quitting the program from a child's coroutine, a `Terminal`'s `$onExit` closing its pane):
   `dispose()` cannot wait for the coroutine it is called from. It signals as above and
   returns; the coroutine goes on with `isMounted()` false and `sleep()` returning false, and
   `unmount()` runs once it has returned. Return soon after; do not wait in it for the
   component's end (it cannot come while you wait). This holds for a coroutine of the component
   or of any of its descendants; the library knows which coroutine runs from
   `fiber\current()`.

Coroutines are not cancelled from outside (fiber-interop has no cancellation): write loops as
`while ($this->sleep(...))`, check `isMounted()`, or race a wait against `stopped()`:
`fiber\await_any([$work, $this->stopped()])`.

## How a program ends

```php
Tui::run(new App());        // full screen until the app quits; Tui::inline() for a live region

// or, with the run in your hands:
fiber\run(function () {
    (new App())->start(new Tty());
});   // returns when the app has quit; rethrows the exception if it failed
```

`Tui::run($root)` (`phasync\Tui\Tui`) is the whole runner: it attaches a `Tty`, starts the
root, waits until it has quit, gives the terminal back and returns the root component (to read
what it collected). `Tui::inline($root, keep: true)` does the same in inline mode, and
`Tui::headless($root, $cols, $rows)` starts it on a `FakeTerminal` inside your own `fiber\run()`
and returns the terminal, for tests (`inline: true` for inline mode). Under `Tui`, **Ctrl-Q
quits** when no component on the key's way binds it (a `Help` element lists it as "quit");
`$root` may be an element tree or a closure (see "One file, no class").

- **Quitting.** Dispose the root component (`$this->dispose()` in its key handler), close the root
  surface (`$this->surface->root()->close()` from anywhere), or detach the device; each leads to
  the others. The whole tree is disposed: every `Terminal` hangs its program up, every
  component's coroutines are told to stop, and the terminal is given back. Then nothing is left
  running and `fiber\run()` returns. (A `Pty` you started yourself with `Pty::start()` is not
  part of the tree; end it yourself.)
- **A failing root.** An exception in the root component's `mount()`, `render()`, handlers or
  coroutines disposes the tree and closes the surface (so the terminal is restored), and then
  reaches you: `start()` throws it when it happened there, else `fiber\run()` does. A typo in a
  root `render()` is an error message, never a hang.
- **Failing code around it.** When the closure given to `fiber\run()` throws after `start()`
  (a typo: a protected method called from outside), or the coroutine that started the program
  does, the program ends with it: the tree is disposed, the terminal restored, and
  `fiber\run()` throws the exception, as it would without a terminal UI. The library watches
  the task that called `start()` (and `TerminalDevice::attach()`) through `fiber\current()`;
  awaiting that task from inside it needs fiber-interop/api newer than 0.3.1 (main from commit
  4dc3d41) and, on phasync, fiber-interop/phasync newer than 0.4.1 (main from 8445790); until
  those are tagged, with older versions nothing watches, and `Tui::run()` (which owns the run
  closure) or `FakeTerminal::run()` in tests are the way to see the exception. An exception in
  a coroutine you started yourself with `fiber\go()` is not seen until `fiber\run()` ends: start
  such work with the component's `go()`, whose failure is the component's.
- **A failing child** is contained to its rectangle (see below); its own children end with it.

What keeps `fiber\run()` running: an attached terminal device, the programs of running ptys,
and the coroutines of mounted components and your own.

## Hooks to override

| Hook | When it runs |
|---|---|
| `mount(): void` | Once, at start: props, state, children. Not sized yet. |
| `sized(): void` | Once, when the component has its real size, right before the first render: start work that depends on the rectangle here. |
| `render(): Layout\|Element\|null` | Return an element tree (see "Element trees"), or a layout of child components' surfaces, or draw into `$this->surface` and return null. |
| `propsChanged(): void` | A parent's render placed this component anew with other props; they were taken over (see "Props"). |
| `bindings(): array` | The keys of the component and the public methods they run (see "Key bindings"). |
| `run(): void` | The component's coroutine, started after the first render. |
| `unmount(): void` | Once, after disposal, when the coroutines have returned and the children have ended. |
| `onKey(Key $key): bool` | A key reached the surface; return true if handled (a render follows). |
| `onMouse(Mouse $mouse): bool` | A mouse event, in the component's coordinates; true if handled (a render follows). |
| `onPaste(Paste $paste): bool` | Text pasted while the component is on the focus path; true if handled (a render follows). |
| `onFocus(Focus $focus): void` | The component gained or lost the focus (a render follows). |
| `onResize(Resize $size): void` | The surface was resized (a render follows by itself; so does one after a move). |
| `wantsPointer(): bool` | Return true to follow the mouse: every motion over the component reaches `onMouse()` (`Mouse::MOVE`) and its `:hover` state is kept (see "The mouse"). |
| `canFocus(): bool` | Whether `focus()` gives the keys to the component itself or to the field it focused inside (see "Focus"). |
| `measure(int $cols, int $rows): ?array` | The size it needs for a parent's `'auto'` size: by default that of the element tree it rendered last (see "Sizes"). |

Event handlers run as the event travels down the surface tree (see [hosting](hosting.md)): a
component sees an event before its focused child does, and returning true stops it there. That
is where application-wide keys go (a quit key on the root component); a widget that handles
keys itself (a text field) returns true for the keys it uses.

## Element trees

`render()` may return a tree of **elements**, plain PHP values made anew on every render. The
library lays the tree out into the component's surface (a surface for each text and each
container, the child components' own surfaces where they are placed) and paints every element
through `write()`, the way any producer draws: there is no other way onto a Surface.

```php
use phasync\Tui\Element\{Box, Column, Help, Layers, Row, Span, Text};

protected function render(): Element
{
    return new Column([
        new Text(['Status: ', new Span($this->status(), class: 'ok')]),
        new Row([
            new Box($this->child('files', fn() => new Select($this->files())), 'files', width: 30),
            new Box($this->child('log', fn() => new Log()), 'log'),
        ]),
        new Help(),
    ]);
}
```

| Element | What it is |
|---|---|
| `Column(array $children, int $gap = 0, padding: null, align: null)` | Children on top of each other (as `Split::vertical()`); each as high as its `height`. `padding:` (`1`, `'0 1'`) and `align:` (`start`, `center`, `end`, `stretch`: where the children go across it) are the `padding` and `align-items` styles. |
| `Row(array $children, int $gap = 0, padding: null, align: null)` | Children side by side (as `Split::horizontal()`); each as wide as its `width`; `padding:` and `align:` as for a `Column`. |
| `Layers(array $children)` | Children over each other, the first at the bottom (as `Stack`); each `width` x `height`, placed by its `align` style (`left` top-left, `center`, `right` top-right). |
| `Box(Element $child, string\|Closure $title = '', padding: null)` | A border with a title around one element; border line, colour, `padding` and `title-align` from the stylesheet or classes (`border-double title-center p-1`); the title takes markup, and may be a closure (drawn again by itself). |
| `Text(string\|array\|Closure $content, bool $wrap = true, align: null)` | Text, wrapped between words (or cut with "…" when `wrap` or the `wrap` style is false: `truncate`), aligned by `align:` (`left`, `center`, `right`: the `align` style); content is a string (markup and SGR sequences allowed), a list of strings and `Span`s, or a closure returning either (drawn again by itself when the state it read changes). `Text::raw($s)` shows `$s` without markup. |
| `Span(string $text, $class, $style)` | A run of text in another style, inside a `Text`; takes markup. |
| `Help(?Component $for = null)` | The keys in force for the focused component (or `$for`) and its parents, as "key action" pairs. |
| any `Component` | Its surface, where it is placed: from `child()`, or `new` (see "Props"). |

**Attributes.** Every element takes `key`, `id`, `class`, `style` (inline style properties),
`width`, `height`, `disabled`, `selected`, `state` (states of its own for the stylesheet:
`state: 'error'` matches `'Text:error'`), `onFocus` and `onBlur` as named arguments; a
component takes them with `with()` (`$this->child('name', fn() => new Editor())->with(id:
'name', height: 1)`). Read them with `attribute('class')`. A `style:` given beside
`padding:`/`align:` wins over them. `onFocus` and `onBlur` are called with no arguments when
the focus comes into the element (onto a component in it) or leaves it, after the render that
moved it, blurs before focuses; a focus that passes through within one frame is not reported:

```php
new Column([$this->child('name', fn() => new Editor())], onFocus: fn() => $this->state->help = 'Your full name')
```

**Sizes.** In a `Column` a child's `height`, in a `Row` its `width`, in `Layers` both: cells,
`'25%'`, `'1fr'` (a share of what is left after the others), or `'auto'` (what its content
needs: `measure()`). Without one, everything is `'auto'`: `Text`, `Box` and `Help` are as
high as their wrapped lines, a component as its `measure()` says (a one-line `Editor`: 1 row; a
`Table`: its rows and its columns' widths; a component of yours that renders an element tree:
that tree, so one rendering a `Row` of one-line things is one row, and a `Column` of such row
components needs no `height:`), and a container (`Column`, `Row`, `Layers`) as its
children need: along its direction the sum of theirs (and the gaps), across it the largest. An
element whose size is not bounded (a component whose `measure()` is null, a `Log`, a
multi-line `Editor` without `maxLines`) takes a `'1fr'` share instead, and so does a container
with such a child in that direction. So a toolbar `Row` of one-line fields, a `Column` of
one-row `Text`s and a `Column` of `Checkbox`es need no sizes. Sizes may also come from the
stylesheet (`width`, `height`), and `max-width`/`max-height` cap an `'auto'` size (a `Log`
with `max-height: 10` takes up to ten rows). When a component's `measure()` changes (an
editor grew a line), its parent lays the tree out again by itself.

The room is given out in this order: fixed sizes (cells, percentages) first; then, **when
every `'auto'` item fits with what its siblings that take any room need at least** (one row each
for a field that fills, its parts for a container), the `'auto'` items get what they need and the
`'1fr'` items share what is left. When they do not all fit, the `'auto'` and `'1fr'` items share
the rest, **each `'auto'` item getting what it needs, but no more than a `'1fr'` share** while
others still need room. So an item that needs little gets exactly that,
and one whose content is larger than the room (a `Table` of 10,000 rows, a one-line `Editor`
that measures as wide as it is given) acts as `'1fr'` and leaves its siblings theirs: a `Help`
line under such a table keeps its row, a `Text(width: 20)` beside such a field is drawn. Give
an element a size to decide yourself.

**What stays.** Elements are matched with the previous render's by their place in the tree
(positions and element types), or by `key` (for items of a list that come and go or move):
the surface behind each stays, and with it the focus, scroll positions and the child
components. A tree rebuilt on every render keeps everything; a `Text` that moves to another
place in the tree is a new text, which costs only its repaint.

**Focus.** Text and help do not take the focus (`Surface::setFocusable(false)`), nor do
containers with no component in them: a `Column` of a label and a field gives the keys to the
field. When the program focused nothing and the keys would go to a component that does not
take them (a `ProgressBar` above the fields), the root gives the focus to its first component
that does. **Tab and Shift-Tab** walk the components that take keys, in the order they show,
around, when nothing binds them (a `Terminal`'s program keeps its Tab). `$this->focusNext()`
and `$this->focusPrevious()` go from field to field inside one component, around (with `wrap:
false`, stopping at the last and returning false, so the key goes on to a parent that continues
from there); bind them for a group of its own: `'tab' => 'focusNext', 'shift+tab' =>
'focusPrevious'`.

**Layout from the style.** Besides sizes, an element's style (classes or rules, see
[styling.md](styling.md)) gives it `margin` (blank cells around it), `flex-grow` (`grow`: a share
of the room left along its parent, at least what it needs), `flex-shrink: 0` (`shrink-0`: never
less than it needs) and `display: none` (`hidden`: left out, as `null` would be); a `Column` or
`Row` takes `padding`, `gap` (over its `gap:` argument), `flex-direction` (`flex-row`,
`flex-col`: a `Row` laid out as a column and the other way round, for `flex-col md:flex-row`),
`align-items` (`items-center`: children as large as their content across, centred) and
`justify-content` (`justify-between`: room left spread between the children).

**Mixing.** A component may draw into its surface and return a tree in the same render: what it
draws shows where no element covers it. Components that draw themselves (a graph with `Ansi`)
go in a tree like any other.

## Props

A component placed in the tree with `new` is a child at that place: the first render starts
it; on later renders the new instance is not started, the one already there **takes its props**
(its public properties that are not readonly) and attributes, `propsChanged()` runs, and it
renders. Its state stays.

```php
final class Counter extends Component
{
    public function __construct(public string $label = '') {}   // a prop
    // ...
}

// in a parent's render: the label follows the parent's state, the count stays the counter's
return new Column([new Counter(label: "clicks of {$this->state->user}"), ...]);
```

A component of another class at the same place replaces the old one. `child($key, $create)`
is the other way: its factory runs once, and the parent changes the child afterwards through
its methods.

**A child renders when its own things change, not when its parent renders.** A parent's
render that places `new Counter(label: 'A')` again with the same props does not render the
counter again: nothing of the counter changed. So a child that shows something of its
parent's (a value the parent keeps in a property, the parent's state) does not follow it by
itself. Two remedies: pass the changing value as a prop (`new Counter(label: $this->state->user)`:
a new value is new props, and the child renders), or give both the same `State` object (a
write renders every component that has it as its `$state`).

### The conventions of a widget's constructor

The library's widgets, and a component of yours that others place with `new`, follow these:

- **Props** are the constructor's parameters promoted to `public` (not `readonly`) properties:
  `public bool $tail = false`. They are what a parent may change by rendering the widget anew
  with other values; the kept instance takes them, then `propsChanged()` runs, then it renders.
  A widget whose props need more than a render (laying out again, following the end) does it in
  `propsChanged()`. Everything else it keeps is `private` (or `readonly`): a parent changes that
  through the widget's methods (`append()`, `setValue()`), never by rendering.
- **Callbacks** (`?Closure $onPress = null`) are not props: they are taken from the first
  instance only, and each also has an event (`press`) for code that is not the creator.
- **A callback that may answer later** (`Tree`'s `load:`, which fetches a node's children)
  returns its answer or an awaitable of it: an array or any `Traversable` (a generator) is the
  answer, and **any other object is taken for an awaitable** and passed to `fiber\await()` in a
  coroutine of the widget (a `fiber\go()` handle, a `fiber\Deferred`, a promise with `then()`),
  so the widget stays usable while it waits. A widget of yours with such a callback does the
  same, in its `go()`.
- **`...attributes`** in a signature (docs/widgets.md) means the named arguments `key`, `id`,
  `class` and `style`, after the widget's own parameters, plus `disabled` on a widget that takes
  input (a `Button`, a `Checkbox`): `new ProgressBar(0.4, class: 'slim')`. They are passed with
  their names, never by position. The others (`width`, `height`), and these too, go through
  `with()`, which every component has: `(new Log())->with(height: '1fr')`. The elements
  (`Column`, `Row`, `Layers`, `Box`, `Text`, `Help`) take all seven as named arguments.
- The attributes count as props: a parent's render that gives another `class:` restyles the kept
  instance.

## State: rendering by itself

`$this->state` is the component's `State`: an object whose writes render the component again.
A component has a plain one (any property names, `$this->state->open = true`), or declares a
class with typed public properties and assigns it in the constructor or `mount()`:

```php
final class TodoState extends State
{
    public array $items = [];
    public string $filter = '';
}

final class Todo extends Component
{
    /** @var TodoState */
    protected State $state;

    public function __construct()
    {
        $this->state = new TodoState();
    }

    public function add(string $item): void
    {
        $this->state->items = [...$this->state->items, $item];   // assign arrays whole
    }
}
```

The rule, in one paragraph: **a component renders again in the next frame after its state
changed, after one of its handlers took an event (`onKey()` and the others returning true, a
bound key), after a step of one of its coroutines (each `sleep()`, and the end of a `go()`
that waited), after a closure of the component that it gave to a child ran (a callback such as
`onSubmit`, a listener given to `on()`), when a parent's render gave it new props, and when its
area changed.** Everything in one frame is one render, parents before their children, so a
coroutine loop that changes the state many times between two waits renders once per step.

**Frames.** A tree shown on a terminal device (`Tty`, `FakeTerminal`, a `Tui` runner) renders
with the device's frames: what was asked for since the last frame renders, is composed and is
drawn together, at most 60 times a second, and at once when the terminal drew nothing for a
sixtieth of a second (a key in a quiet program shows right away). A tree that is not shown on
a device renders as soon as the current coroutine yields. A component asked for again by a
render of the same frame (a child whose size changed makes its parent lay out again) renders
again in that frame, so no half-done state is drawn. `sleep()` ends at a tick of the same
clock (see its entry below), so the coroutines of many components wake together and are one
frame. In tests, read the screen with `text()` or after `settle()`: both draw what is pending
first.
There are no signals, observables or dependency tracking: what `render()` shows is computed in
`render()` (or in methods it calls). `requestRender()` is the explicit call for the rest, a
change the component cannot see.

**Across parent and child.** A parent whose `render()` reads a child widget's state (a title
that shows which tab is current, a count of the rows a `Select` has marked) renders again only
when something of the parent's own happens; the child's renders are the child's. Two
remedies, both in the rule above: give the child a **callback** made in the parent
(`new Tabs($tabs, onChange: fn() => null)`: the parent renders after its closure ran, whatever
it does), or **listen** to the child's event (`$tabs->on('change', fn() => $this->requestRender())`,
for a child made by someone else). Every widget says which callbacks and events it has.

**Where a coroutine lives decides what renders.** A step of a coroutine renders the component
the coroutine belongs to, with its whole tree. A monitor whose parent runs one `go()` loop per
job and keeps the jobs in its own state renders all fifty rows on every step of every job; the
same monitor with a component per row whose coroutine (its `run()`, or a `go()` of the row)
updates the row's own state renders one row per step, and the parent not at all (a row whose size
did not change does not make the parent lay out again). So: state in the row, the row's
coroutine in the row, and a parent that only lists its rows. See "Performance" in
[surface.md](surface.md#performance) for what a frame costs.

**Reading subscribes.** A component whose `render()` reads a property of any `State`, not
only its own `$this->state`, renders again when that property is written. That is how a closure
component works, and how a component shows a state another one owns. A plain state with values
is `new State(['done' => 0])`; a subclass with typed properties needs no constructor (one that
has its own calls `parent::__construct()`, so that reads subscribe before a component holds it).

`$state->set(count: 0, label: 'x')` assigns several properties at once. A write of the value a
property has already renders nothing; a write of a wrong type is a `TypeError` as for any typed
property. **Shared state**: give several components the same `State` object as their `$state`
(a parent passes its own to children's constructors), and a write renders all of them.

## Styles

Elements and components take `class:`, `id:` and `style:`; the program's `Stylesheet` gives
each its look. Set it on the root component (it applies to the subtree; the nearest one wins)
and build it on the default theme:

```php
$app->setStylesheet(Stylesheet::default()->with([
    'Text.title' => ['bold' => true, 'color' => '$accent'],
    'Box:focused' => ['border' => 'double $accent'],
    '#name' => ['width' => 30],
    'Select::row:current' => ['background' => '$accent', 'color' => 'black'],
], vars: ['accent' => 'magenta']));
```

A component that renders a `Box` takes its border from its own classes too: `$this->child('log',
fn() => new Log())->with(class: 'border-accent')` inside a `Box` that sets no border colour of
its own colours that box (a border colour on an element without a border passes down to the
nearest `Box` in it).

Or with **utility classes** and no stylesheet at all: `class: 'border-round border-sky-500 p-1
grow text-slate-300'`, `focus:bg-sky-800`, `flex-col md:flex-row`. The full vocabulary, the
properties, markup in text (`<b>`, `<fg=red>`) and how colours reach terminals of 16 and 256
colours are in [styling.md](styling.md).

Selectors: a type (the element's or component's class name; parents' names match too) or `*`,
`#id`, `.class`, states `:focused` (on the focus path), `:disabled`, `:hover` (under the mouse), `:selected`,
an element's `state:` and a component's own, the screen's breakpoints `:sm`/`:md`/`:lg`/`:xl`
(from 60, 80, 100 and 140 columns), and
widget parts `::part` with their own states. Properties: `color`, `background`, `bold`, `dim`,
`italic`, `underline`, `inverse`, `strike`, `border` (kind `single`/`round`/`double`/`heavy`/
`ascii`, colour, `bold`/`dim`/`plain`, or `none`), `shadow`, `title-align`, `padding` (and its sides), `margin` (and its
sides), `align`, `wrap`, `width`, `height`, `max-width`, `max-height` (cells: the most an
`'auto'` size, and an overlay's measured size, may take), `flex-grow`, `flex-shrink`,
`display`, and for containers `gap`, `flex-direction`, `align-items`, `justify-content`.
Colours and attributes inherit down the tree. The cascade: rules by specificity (ids, then
classes/states/parts, then types; later rules win ties), then the utility classes in the order
written, then inline `style:`. The default theme uses the 16 terminal colours and the variables
`accent`, `muted`, `panel`, `error`, `success`, `warning`. `Stylesheet::explain()` tells what
resolved for an element. See `Stylesheet` for the details.

A component that draws itself asks for its look with `$this->style()` (a `ComputedStyle`:
`text` is the `Style` for `Ansi::style()`, plus `border`, `padding`, `align`, `width`,
`height`, `maxWidth`, `maxHeight` and the layout properties) and `$this->style('part', 'state')`
for its parts; it renders again when its states change. A label that takes markup is drawn
with `$this->markup($label, 'part', ...$states)`: its tags as escape sequences in that style. `properties()` gives a computed style as inline properties, for a `Text` or
`Span` in a part's style.

**Parts that apply together compose.** A run of text can be in several parts at once (a link
inside a strong span inside a heading): `$this->style(['heading', 'strong', 'link'])` composes
them in order, each on the one before, as an element inherits from its parent, and the first
on the component's own style. Attributes add up (bold and underlined), and the colour of the
last part that sets one wins.

### Parts on one row

A widget that draws several parts on one row (a tree's guide lines, its ▸ marker and the label;
a tab's number, label and ×) asks for each as the row's part composed with its own, **in the
row's states**: the states given apply to every part of the list.

```php
$row    = $this->style('node', ...$states);              // Tree::node:current: the bar
$marker = $this->style(['node', 'marker'], ...$states);  // on the bar: Tree::marker, Tree::marker:current
```

So a part without a colour of its own keeps a highlighted row one bar (it inherits the row's
inverse and colours), one with a colour shows it on the row, and a rule may style a part in
the row's state (`'Tree::marker:current' => ['bold' => true]`). This is the one merge the
library makes; a widget never overlays styles itself.

**The pen is the component's style.** Before `render()` runs, the library sets the surface's
pen (its current SGR) to the component's style. So a render that clears the surface
(`"\e[2J"`, `Ansi::clearScreen()`) fills it with the themed background, and text written
without SGR of its own is in the component's colours. `"\e[0m"` (`Ansi::reset()`) resets the
pen to the terminal's default; write `Ansi::style($this->style()->text)` after it to come back.

**States of your own.** `setStyleState('selected', true)` puts a component in a state the
stylesheet can select (`'.card:selected' => ['border' => 'double $accent']`), and renders it
and the tree around it (a `Box` showing it); `hasStyleState()` reads it. A parent may set it on
a child (`$card->setStyleState('selected', $key === $this->state->current)`). The library's own
states are `focused`, `disabled` and `hover`; the widgets add theirs (`Button:pressed`,
`Checkbox:checked`, a `Form` field's `:invalid`).

## Key bindings

Keys that are actions are data: `bindings()` maps a key (`Key::parse()` notation) to a public
method of the component, with a description for `Help` when it should be listed:

```php
protected function bindings(): array
{
    return ['ctrl+s' => ['save', 'save'], 'f5' => ['reload', 'reload'], 'delete' => 'remove'];
}
```

**Order.** A key goes down the focus path, the root first: each component on the way may take
it with its bindings marked `first` and its `onKey()`. At the component it ends at (the
focused one): its own bindings, then its `onKey()`, then the bindings of its parents, nearest
first. A key nobody takes goes to the focused surface's program (a `Terminal`'s shell). So a
focused text field types every letter an application binds, the application's Ctrl-Q works
wherever the focus is, and a method that returns `false` did not take the key (an editor's Up
on its first row), which goes on.

**A container that wants some keys first and some last** (tabs around panes, a split view)
says which in its bindings: the ones it takes whatever is focused inside it (Ctrl-Tab to the
next tab, even from a shell) are marked `'first' => true`, and the ones it takes only when the
focused content did not (Escape back to the tab bar, unless an editor uses it to clear its
selection) are plain:

```php
protected function bindings(): array
{
    return [
        'ctrl+tab' => ['nextTab', 'next tab', 'first' => true],   // before the focused pane
        'escape'   => ['toBar', 'tab bar'],                       // after it, if it did not take it
    ];
}
```

`Help` lists both kinds (those with a description). `onKey()` follows the same order as a
`first` binding on the way down and comes after the bindings at the focused component: keep it
for what a map cannot say (any letter, Alt and a digit).

**While a modal overlay is open** (a dialog), the component that opened it is suspended: its
`onKey()` and its bindings do not run, except bindings marked `always`; the bindings of its
parents run as usual. So a dialog opened by a pane leaves the application's keys working, and
a key the application must have everywhere, also under a dialog the root itself opened, is
marked:

```php
return ['ctrl+q' => ['quit', 'quit', 'always' => true], 'ctrl+s' => ['save', 'save']];
```

**Changing them.** `$component->bind(['ctrl+s' => null, 'ctrl+w' => 'save'])` changes one
instance; `$app->bind(['ctrl+enter' => 'submit'], Editor::class)` every `Editor` below `$app`.
The nearest setting wins. `bindingsInForce()` lists the result. A `Help` element shows the
described ones for the focused component and its parents, and follows the focus.

## Overlays and dialogs

Anything can float over everything, outside the component's rectangle and its parents'
clipping: a dropdown's list, a completion popup, a context menu, a tooltip, a dialog. One
primitive, on the component:

```php
$overlay = $this->overlay(Element $element, ?Rect $anchor = null, string|Rect $place = 'below', bool $modal = false);
```

- **Hosted on the root.** Every overlay is a window of the root component's surface (not of a
  `Layers` or the opener's parent), above the whole tree, so nothing around the opener clips
  or covers it.
- **Where.** Next to `$anchor`, a rectangle in the component's own coordinates (default: the
  whole component), by `$place`: `'below'` or `'above'` it, `'center'` of the screen, or a
  `Rect` (in the component's coordinates). The root's rectangle is a hard boundary: on the
  preferred side if the overlay fits there, else on the other side if it fits there, else on
  the side with more room, **shrunk to that room** (its content scrolls: a list of 30 items in
  6 free rows shows 6 and scrolls); shifted left or right to stay inside, narrowed only when it
  is wider than the screen. The decision is `Placement::for()`, a pure function a widget can
  also call for itself (see [layout](layout.md#placement)).
- **When the screen changes.** After the layout of every frame, before it is painted, each
  overlay is placed again where its anchor is now: the terminal resized, the anchor's
  component moved or scrolled, the tree rebuilt. It keeps its state (a list keeps its current
  item, scrolled into view in its new height). When the anchor's component is no longer shown,
  the overlay is cancelled.
- **Size.** The element's `width`/`height` when given (cells, or `'50%'` of the screen), else
  what it measures, else the whole screen; capped by its style's `max-width`/`max-height`;
  plus the border its style gives it. What it measures is asked again every frame: when the
  content grows or shrinks (a completion list filtered by typing, a dialog showing a
  validation message), the overlay is placed again at its new size and what it no longer
  covers shows again.
- **Order.** Later overlays above earlier ones, kept across renders.
- **Focus.** The overlay gets it, by the surface tree's rule (a widget that keeps the keys, as
  an `Editor` with its completion list, calls `$this->focus()` after opening it). Closing
  gives it back to where it was.
- **Modal** overlays take all input until they close: clicks outside reach nothing, and the
  opener's keys are suspended (see "Key bindings"). **Non-modal** ones are cancelled by Escape
  (when their content does not take it), a press outside them and their opener, or the focus
  moving to something else.
- **The element** is shown by a child component of the opener: the element itself when it is
  a component (not started yet), else a component of the library around it that scrolls it.
  Disposing the opener cancels its overlays.

The returned `Overlay`: `close(mixed $result = null)` closes it with a result, `closed()` is an
awaitable of the result, `isOpen()`, `result()`, and `content` (the component shown). A
cancelled overlay closes with null (a `Dialog`: its `$cancel`). A component shown in one finds
it with `inOverlay()`.

```php
public function openMenu(): void       // bound to a key
{
    $menu = $this->overlay(new Select(['copy' => 'Copy', 'paste' => 'Paste'],
        onActivate: fn($key) => $menu->close($key)));
    $this->go(function () use ($menu) {
        $action = fiber\await($menu->closed());      // null when cancelled
    });
}
```

**Dialogs.** `$this->dialog(Dialog $dialog, ?string $key = null): object` is an overlay in
the middle of the screen, modal, and returns an awaitable of the answer. Await it in a
coroutine:

```php
public function delete(): void          // bound to a key
{
    $this->go(function () {
        if (fiber\await($this->dialog(new Confirm('Delete the file?')))) {
            unlink($this->state->file);
        }
    });
}
```

While it is open, the component that opened it is suspended (its bindings marked `always`, and
its parents' bindings, still work: an application's Ctrl-Q quits). Escape cancels; when it
closes, the focus goes back where it was. **Quitting with dialogs open ends the program**:
disposing a component (the root, or any child) while dialogs it opened are open answers each
with its cancel value and disposes it, so the coroutines awaiting them go on and end, and
`fiber\run()` returns. See [widgets](widgets.md) for `Dialog` and `Confirm`.

**One at a time.** A key that opens a dialog and works while one is open (an `always`
binding: Ctrl-Q asking "Quit?") would open a second one when pressed twice. Give the dialog
a key: `$this->dialog(new Confirm('Quit?'), key: 'quit')`. While the dialog of that key is
open, the call gives it the focus and returns its awaitable; the new dialog is not shown.
Both coroutines get the one answer, so act on it in a way that is fine twice (`dispose()`).

## Focus

`focus()` in `mount()` sets where a screen starts; in `render()` it takes effect on the first
render that asks and on renders asked for with `requestRender()`, not on the renders the
library makes by itself (after a key, a state write, a coroutine step), so a render does not
take back the focus the user moved.

One component has the keyboard: the one focused last (`focus()`), or the descendant it passes
the keys on to. **A component with keys of its own takes them itself** when it is focused:
one with `bindings()` (or keys given with `bind()`) or an `onKey()`/`onPaste()` of its own. A
board that focused its filter field takes the focus back with `$board->focus()`. **A container**
(a component without keys of its own: a `Box`, a `Dialog`, a `Form`, a screen of fields)
passes the focus on to the descendant it focused, or to its first focusable one: a dialog that
focuses its text field in `mount()` and is then focused itself gives its keys to the text
field. Override `canFocus(): bool` to decide otherwise.

```php
final class NameDialog extends Component
{
    protected function mount(): void
    {
        $this->child('name', fn() => new TextInput())->focus();   // the dialog's own focus
    }
    // render() shows the TextInput
}

// in the parent's key handler: create the dialog, focus it, render it
$this->dialog = $this->child('dialog', fn() => new NameDialog());
$this->dialog->focus();
$this->requestRender();
```

The rule is the same before and after the component is shown, so nothing has to be repeated in
`render()`:

- **Before a render shows it** (the keys typed right after the one that opened the dialog), the
  keys reach it through its nearest shown ancestor, which passes them on to it as if it were on
  the focus path, and it passes them on to the descendant it focused (the text field gets
  them, not only the dialog's own `onKey()`). `hasFocus()` is true for it and for that
  descendant, and false for the window that had the focus before.
- **Once a render shows it**, the surface tree's focus path leads to the same component and on
  to the same descendant (the surfaces remember the requests; see `Surface::focus()`).

The latest request wins wherever it is: focusing another component, shown or not, takes the
keys there. Of several children none of which was focused, a host gives the keys to its first
window; a component not rendered yet gives them to its first child (as a `Box` does to its
content).

**The first focus and Tab, by default.** A program that focuses nothing still starts with a
field that has the keys: when the first window would give them to a component that does not
take keys (a `ProgressBar`, a `Spinner`, a label drawn by a component), the root gives the
focus to its first component that does (`canFocus()`: one with bindings or an `onKey()` of its
own, a `Terminal`), in the order they show, top to bottom and left to right. Once the program
or the user focused something, the root leaves the focus alone. **Tab and Shift-Tab**, when
nothing on the key's way binds them and the key ends at a component with keys of its own, go to
the next (previous) such component in the same order, around; while a modal dialog is open,
only among the components in it. A `Terminal` has no keys of its own (they go to its program),
so a shell keeps its Tab; a component that binds `tab` (a `Form`, `Tabs`, an `Editor` with
completion) keeps its meaning. When the focused component is no longer shown, the component focused before it gets
the focus back.

The focus belongs to the surface, not to the layout objects around it: a `render()` that builds
new `Stack`s and `Split`s every time, with new plain surfaces in them and the focused
component's surface added directly with a border and title
(`->add($this->dialog->surface, 40, 5, title: 'Name')`), keeps the keys on the focused
component. `hasFocus()` reads the same path the keys take, so it is true exactly when the next
key reaches the component (or goes through it).

## The mouse

Mouse events go to the window under the pointer, in its coordinates, each component on the way
seeing them first (`onMouse()`). **The component that took the press gets the whole drag and
the release**, wherever the pointer goes, with coordinates relative to its own rectangle that
may be outside it (negative, or beyond its width and height): drag and drop between siblings
needs nothing of the root's. (A program in a `Terminal` that asked for mouse reports gets them
at the edge of its window, as a terminal reports them.) Return true from the press to take
the drag; a press nobody takes starts the host's text selection.

**Following the pointer.** Motion without a button is reported only to components that ask
for it: one whose `wantsPointer()` returns true, or that the stylesheet has a `:hover` rule for
(`'Card:hover'`), or for one of its parts (`'Tabs::tab:hover'`). Such a component gets every
motion over it in `onMouse()` (`$mouse->action === Mouse::MOVE`) and its `:hover` state is
kept; its surface asks the terminal for motion reports (mode 1003), so it takes its mouse
events itself. A widget lights up the part under the pointer by passing the part the state
`hover` (`$this->style(['bar', 'tab'], 'hover')`).

```php
final class Menu extends Component
{
    private ?int $under = null;

    protected function wantsPointer(): bool
    {
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        $this->under = $mouse->y;       // the item under the pointer; a render follows
        return true;
    }

    // render() draws the item on row $this->under in $this->style(['item'], 'hover')
}
```

## Kept-alive children

A child made with `child()` lives while renders ask for it or show it: the first render that
does neither disposes it. A component that holds panes the current render does not show
(hidden tabs, a log collecting in the background, a shell that goes on running) makes them
with `keep()` and ends them itself with `stop()`:

```php
protected function render(): Element
{
    $pane = $this->keep("pane:$this->current", fn() => new Terminal($this->commands[$this->current]));
    return new Column([$this->bar(), $pane]);    // the others stay alive, unshown
}

public function closePane(string $name): void
{
    $this->child("pane:$name")?->stop();         // returns at once; the program is hung up
}
```

A kept child is started at once and sized and rendered when a render first shows it (a
`Terminal` starts its program then). `stop()` is `dispose()` without the wait, for renders and
handlers; it leaves the parent at once (`child($key)` gives null). `Tabs` keeps its component
panes this way.

## Methods

The methods of the library are `final` (all in the table that are not hooks to override:
`render()`, `mount()`, `run()`, `measure()`, `onKey()` and the other hooks of "Hooks to
override", `canFocus()`, `wantsPointer()`, `bindings()`): a method of yours with the name of one
fails where it is declared ("Cannot override final method"), not somewhere at run time. A name
the library uses privately (`key()`, `end()`) is free: a public `key()` of yours is bound like
any other method.

| Method | What it does |
|---|---|
| `surface` | The component's surface (readonly; set by `start()`). |
| `start(Surface\|TerminalDevice\|null $on = null): static` | Mount a root component, on a terminal device, a surface, or a new surface. Throws when already started; rethrows a failure of its first `mount()`/`render()` after disposing what was started; ends the program when the code that called it fails (see "How a program ends"). `Tui::run($root)` does it all for a program. |
| `dispose(float $timeout = 1.0)` | End the component (see the lifecycle): signals its coroutines and children and waits until they have ended, `$timeout` seconds at the latest; `unmount()` has run when it returns, except when called from a coroutine of the component or a descendant (it returns at once, and the end follows when that coroutine returns). For the root, its surface is closed first, which detaches the terminal. Safe to call twice. |
| `stop(float $timeout = 1.0)` | As `dispose()`, without waiting: the end follows when the coroutines have returned. For a child the render no longer wants (a closed tab's pane, a `keep()` child) from a render or a handler. |
| `isMounted(): bool` | True between start and dispose. |
| `stopped(): object` | An awaitable that resolves on disposal. |
| `requestRender()` | Ask for a render in the next frame (batched). Rarely needed: see "State: rendering by itself". |
| `with(key:, id:, class:, style:, width:, height:, disabled:): static` | Set element attributes (see "Element trees"); returns the component. `attribute(string $name)` reads one. |
| `measure(int $cols, int $rows): ?array` | The size it needs as `[cols, rows]`, or null to take what it is given; for `'auto'` sizes. By default the size of the element tree its last render returned (null for one that draws itself); override it in a component that draws itself and knows its size. The answers are remembered until the component is marked for a render (a state write, a handler, `requestRender()`) or renders, so compute it from what goes with a render: state, props, style. |
| `device(): ?TerminalDevice` | The terminal device its tree is shown on (`Tty`, `FakeTerminal`, also one `Tui::run()`, `Tui::inline()` or `Tui::headless()` made), or null when the tree is not attached: `$this->device()?->println('done')`, `freeze()`. |
| `style(string\|array\|null $part = null, string ...$states): ComputedStyle` | Its look from the stylesheet in its current states, or that of a part, or of several parts composed (innermost last; see "Stylesheets"). |
| `markup(string $text, string\|array\|null $part = null, string ...$states): string` (protected) | `$text` with its markup (`<b>`, `<fg=red>`, see [styling.md](styling.md#markup-in-text)) turned into escape sequences in the style of the component or of its part: how a widget draws a label that takes markup. |
| `stylesheet(): Stylesheet` / `setStylesheet(Stylesheet $s)` | The stylesheet in force (its own, else its parent's, else the default); set one for the subtree. |
| `theme(): string` / `setTheme(?string $name)` | The theme in force (`dark`, `light`, `mono`: its own, else its parent's, else the one `Tui::run(theme:)` or the terminal's background chose); set one for the subtree, null to follow the parent. A stylesheet built with `->with()` is replayed on the theme (see [styling.md](styling.md#themes)). |
| `bind(array $bindings, ?string $for = null): static` | Change the keys of this component, or of every component of class `$for` below it (see "Key bindings"). `bindingsInForce()` lists the keys in force. |
| `types(): array` | The type names it matches in a stylesheet (its class's short name and its parents'). |
| `focusNext(bool $wrap = true): bool`, `focusPrevious(bool $wrap = true): bool` | Move the focus to the next (previous) field inside it, around; with `$wrap` false, false at the last (first) one so the key goes on. Bind them to Tab and Shift-Tab for a group of fields of its own (unbound, Tab walks every component that takes keys; see "Focus"). |
| `focus()` | Give it the focus (on a component not started yet: when it starts), and through it to the descendant it focused (see "Focus" above): every host from the root down focuses the way to it, components or plain surfaces or nested layouts (see `Surface::focus()`). It takes effect at once, for the very next key, also for a component not shown yet (a dialog created in a key handler, shown by the render that follows). The focus stays with it through every re-render, also one that moves it into a different nested layout; when it is no longer shown, the component focused before it gets the focus back. |
| `hasFocus(): bool` | True if keys go to it or through it to a descendant, also while it is not shown yet: it follows the path the keys take. False once disposed. |
| `on(string $event, Closure $listener): Closure` | Listen to an event of the component (the built-ins document theirs: `Log` and `ListView` emit `scroll`); returns a function that removes the listener. See "Showing another component's state". |
| `parent(): ?Component` | The parent, null for a root. |
| `error(): ?Throwable` | The exception that ended it, if one did. |
| `child(string $key, ?Closure $create = null): ?Component` (protected) | The child under `$key`, created with `$create` and started the first time it is asked for. Without `$create`: the child of that key, or null when there is none or it was disposed (`$this->child('dialog')?->dispose()` in a handler). After a render that returned a layout, children it neither asked for (either way) nor shows are disposed: one whose surface the layout shows is kept, however it got there. `$create` may return a component started already (another's child, given to a container): it is shown here and stays its owner's. |
| `keep(string $key, Closure $create): Component` (protected) | A child that lives until it is stopped, whatever the renders show: the panes of hidden tabs, a background `Terminal`. Made and started the first time; `child($key)` finds it; end it with `stop()` or `dispose()`. |
| `emit(string $event, mixed ...$data)` (protected) | Call the listeners of `$event` (see `on()`); document the events your component emits. `hasListeners(string $event): bool` tells whether anyone listens, to skip work only a listener needs. A listener made by a component renders that component afterwards. |
| `call(?Closure $callback, mixed ...$args): mixed` (protected) | Call a callback the component was given (`onSubmit`); the component that made it renders afterwards. Use it for the callbacks of your own widgets. |
| `overlay(Element $element, ?Rect $anchor = null, string\|Rect $place = 'below', bool $modal = false): Overlay` (protected) | Show an element floating over everything (see "Overlays and dialogs"). |
| `dialog(Dialog $dialog, ?string $key = null): object` (protected) | Show a modal dialog in the middle of the screen; an awaitable of its answer (see "Overlays and dialogs"). With a `$key`, one of that key that is open already gets the focus and its awaitable is returned instead (one at a time). |
| `inOverlay(): ?Overlay` | The overlay this component is shown in, or null. |
| `children(): array` | The child components by key: `child()` keys, `key:` attributes, and keys of the library's (beginning with "\0") for the rest. How a parent or a test reaches a child: `$app->children()['log']`. |
| `setStyleState(string $state, bool $on = true)`, `hasStyleState(string $state): bool` | A state of its own for the stylesheet (see "Styles"). |
| `canFocus(): bool` (protected) | Whether `focus()` gives it the keys itself or passes them on (see "Focus"). |
| `wantsPointer(): bool` (protected) | Whether it follows the mouse (see "The mouse"); true by itself with a `:hover` rule for it or one of its parts. |
| `measureElement(Element $element, int $cols, int $rows): ?array` (protected) | The size an element tree needs, as the library lays it out: for `measure()` of a component as large as the tree it renders. |
| `layoutElement(Element $element): Layout` (protected) | An element tree laid out as a layout, not set: for a component that shows a tree on a surface of its own (as `ScrollView` does). |
| `state` (protected `State`) | The component's state (see "State: rendering by itself"). |
| `go(Closure $task)` (protected) | Another coroutine of the component: contained like `run()`; the component's end waits for it; when it ends after it waited, the component renders. |
| `sleep(float $seconds): bool` (protected) | Sleep about `$seconds`, less if disposed meanwhile; returns `isMounted()`. What the coroutine did before shows in the next frame. The sleep ends at a tick of the frame clock (60 a second): the first after the time asked for, or one at most 2 ms before it, so many components' loops wake together and a `sleep(0.1)` loop still makes ten steps a second. For timing to the millisecond, use `fiber\sleep()`. |

```php
protected function render(): ?Layout
{
    return Split::vertical()
        ->add($this->child('log', fn() => new Log())->surface)
        ->add($this->child('input', fn() => new TextInput(prompt: '> '))->surface, 1);
}
```

Children need not be direct: a layout can nest layouts (`Split::vertical()->add(Split::horizontal()
->add(...))`), or hold a plain surface with a layout of its own; focus goes through either.

**A container given an existing child.** A component made with `child()` can be handed to a
container such as `Box` (or one of yours that takes a component and calls `child($key, fn() =>
$this->content)` in its `mount()`). The container shows it and passes keys to it; it stays
yours: when the box goes, the child stays, with its content.

```php
$log = $this->child('log', fn() => new Log());
return Split::vertical()->add($this->child('box', fn() => new Box($log, 'output'))->surface);
```

## Scrolling in a widget that draws itself: `Scrollable`

A widget whose content is taller than itself (a document, a list it draws, a log) uses the
trait `Component\Scrollable` for its scrolling, so it scrolls like every other one: `Log` and
`Markdown` do. The widget says how many rows its content has (`contentRows()`) and draws rows
from `scrollTop()`; the trait gives the rest:

| | |
|---|---|
| `contentRows(): int` (abstract, protected) | How many rows the content has, at the width the widget has now. |
| `viewRows(): int` (protected) | How many it shows at once: the widget's height by default. |
| `scrollTop(): int` | The content row at the top of the view (0 the first). |
| `scrollTo(int $row): bool`, `scrollBy(int $rows): bool` | Move the view (clamped; it renders again); false when it did not move. |
| `lineUp`, `lineDown`, `pageUp`, `pageDown`, `toTop`, `toBottom` | The actions of the keys (a page is the height less one row); false when they could not move, so the key goes on. |
| `followTail(bool $on = true)` (protected) | Follow the end while content arrives, until the user scrolls up; back at the end it follows again. |
| `follow()`, `isFollowing(): bool` | Follow the end again; whether it does. |
| `scrollBindings(): array` (protected) | Up/Down, Page Up/Page Down, Home/End for `bindings()`: `[...$this->scrollBindings(), 'q' => 'close']`. |
| `scrollWheel(Mouse $mouse): bool` (protected) | For `onMouse()`: the wheel scrolls three rows a click. |
| `scrolled()` (protected) | Emits the event `scroll` (with the widget) when the rows in view or the following changed; call it after drawing. |
| `scrollbar(int $x): string` (protected) | Escape sequences drawing a scrollbar in column `$x`, the view's height: the track in `::scrollbar`, the thumb in `::scrollbar:thumb`; blank when everything shows. |
| `scrollState()`, `setScrollState(int $top, bool $following)` (protected) | Where the position is kept; override both to keep it elsewhere (`Log` scrolls a viewport). |

```php
final class Lines extends Component
{
    use Scrollable;

    public function __construct(private array $lines = [])
    {
        $this->followTail();
    }

    protected function contentRows(): int
    {
        return count($this->lines);
    }

    protected function bindings(): array
    {
        return $this->scrollBindings();
    }

    protected function onMouse(Mouse $mouse): bool
    {
        return $this->scrollWheel($mouse);
    }

    protected function render(): null
    {
        $out = "\e[2J";
        foreach (array_slice($this->lines, $this->scrollTop(), $this->viewRows()) as $y => $line) {
            $out .= Ansi::moveTo(0, $y) . Text::truncate($line, $this->surface->cols() - 1);
        }
        $this->surface->write($out . $this->scrollbar($this->surface->cols() - 1));
        $this->scrolled();
        return null;
    }
}
```

## Showing another component's state

A `render()` that reads another component's state (a pane title with the lines a `Log` shows, a
"12-30 of 200" next to a `ListView`) must run again when that state changes. The built-ins emit
an event for what others commonly show; listen with `on()`: a listener made by your component
renders it again after it ran (renders are batched, so a burst of events gives one), and the
`requestRender()` below is only there to say so:

```php
protected function mount(): void
{
    $this->log = $this->child('log', fn() => new Log());
    $this->log->on('scroll', fn() => $this->requestRender());
}

protected function render(): ?Layout
{
    $title = sprintf('lines %d-%d of %d', $this->log->topLine() + 1, $this->log->bottomLine() + 1, count($this->log->lines()));
    return (new Stack())->add($this->log->surface, title: $title);
}
```

Your own components emit events with `emit()`; document which.

## Errors stay in their rectangle

An exception in a child's hooks, handlers or coroutines is contained to that child: it is
disposed with its children (their programs hung up) and its rectangle shows the error
(`✖ RuntimeException: message`, wrapped to the rectangle and cut with "…" when it does not fit);
the rest of the program keeps running. The same holds for a closure given as element content
(`new Text(fn() => ...)`, a `Box` title): its exception shows in the element's place. An exception in the root
component ends the program (see "How a program ends").

## Text components

A component that shows text (a log, a chat history, a help page, a list) keeps the **text** and
lays it out for the width it has **now**: the model is the text, the layout is what `render()`
makes of it for `$this->surface->cols()`, every time. A surface is a terminal and does not
reflow, so text written once for one width is cut when the surface narrows and stays cropped
when it widens; render runs again after every resize, so a component that lays its text out in
`render()` is always right, from the first size it gets on.

```php
final class Help extends Component
{
    public function __construct(private string $text) {}

    protected function render(): ?Layout
    {
        $rows = Text::wrap($this->text, $this->surface->cols(), words: true);
        $this->surface->write("\e[0m\e[2J\e[H" . implode("\r\n", array_slice($rows, 0, $this->surface->rows())));
        return null;
    }
}
```

`Log` and `ListView` are built this way; a terminal pane (`Terminal`) is not, on purpose: a
program in a terminal draws for the size it is told and redraws on SIGWINCH.

## Built-in components

All in `phasync\Tui\Component\`. The widgets of the components layer (`Editor`, `Table`,
`Select`, `Dialog`, `Confirm`) are described in [widgets](widgets.md).

### Terminal

A command in a pseudo-terminal, as a component: `new Terminal(string|array $command, ?string
$cwd = null, array $env = [], ?Closure $onExit = null)`. The program gets the component's surface
as its terminal: size and resizes, keys and pastes while focused, answers to its queries. The
program starts when the component is sized (the render of its parent that shows it), in a pty
that already has the pane's size, so full-screen programs that read their size once (vim, htop,
less) fill their pane; until then `pty()` is null (`exited()` can be awaited from the start).

When the program exits, the window keeps its last output and the end is announced:
`$onExit(int $code, Terminal $terminal)` is called (in a coroutine of the component, so it may
wait, and a failure in it is contained), and the `exited()` awaitable resolves with the code
(`fiber\await($terminal->exited())`). The host can then close the pane, show the status, or
`restart()`: the command runs again in the same window (a running program is hung up first,
without being announced; the terminal is reset). Disposing the component, and so quitting the
program, hangs the program up with everything it started, a shell's foreground job included
(`Pty::hangUp()`: SIGHUP, then SIGKILL after half a second). `pty(): ?Pty`,
`isRunning()`, `exitCode()`.

### Log

Lines of text with scrollback that follows the end: `new Log(int $scrollback = 10000, bool
$wordWrap = false)`. It keeps its lines as given and lays them out for its current width,
wrapping by display width and grapheme (at any character, or between words with `$wordWrap`),
and lays them out again on every resize and when it gets its first real size, so nothing is
lost when the terminal narrows. The newest `$scrollback` lines are kept; the view scrolls back
`$scrollback` rows. (After a resize the visible part is laid out at once, a long scrollback a
moment later.)

| Method | What it does |
|---|---|
| `append(string $text)` | Add text; `"\n"` ends a line, text without one continues the last line. Escape sequences style it. |
| `setLines(array $lines)` | Replace all lines (without `"\n"`), laid out once: the way to filter, re-style, edit or re-sort what is shown. A view following the end keeps following. One scrolled back stays on the line at its top: on the same text if it is among the new lines (filtering), else on the same line number (re-styling or editing lines in place; the last line if there are fewer now). |
| `clear()` | Remove all text. |
| `lines(): list<string>` | The lines as given (escape sequences included), oldest first; an unfinished last line included. |
| `setWordWrap(bool $on)`, `wordWrap(): bool` | Wrap between words or at any character; lays out again. |
| `scrollToLine(int $line)` | Show line `$line` (an index into `lines()`, 0 the oldest kept) at the top: "go to line N". Clamped; near the end it shows the last lines and follows. |
| `topLine(): int`, `bottomLine(): int` | The first and last line the view shows (in part), as indexes into `lines()`, also while it follows: "lines 120-160 of 300". |
| `scrollBy(int $rows)`, `scrollTo(int $row)`, `scrollTop(): int` | Rows of the laid-out text (a long line takes several, depending on the width), 0 the oldest row kept: for paging by the window's height. Scrolling to the end follows again. |
| `follow()`, `isFollowing()` | Follow the end again; true while it does. |
| `lineUp`, `lineDown`, `pageUp`, `pageDown`, `toTop`, `toBottom` | The actions of Up, Down, Page Up, Page Down, Home and End, when the log has the focus (see `Scrollable`). |

**Lines, not rows.** An application addresses the text in lines, the numbers of `lines()`:
which rows a line takes depends on the width and the wrapping, and stays the log's business.

```php
$log->scrollToLine($n - 1);                                       // go to line N
$bar->set(sprintf('line %d of %d', $log->topLine() + 1, count($log->lines())));
```

The mouse wheel scrolls it, the keys above do when it has the focus, and a drag selects and
copies. `new Log(scrollbar: true)` shows a scrollbar in its last column (part styles
`Log::scrollbar`, `Log::scrollbar:thumb`). A view scrolled back stays on the same
line when the log is laid out again (a resize, a change of wrapping, `setLines()`).

**Event `scroll`** (with the `Log`): the lines in view (`topLine()`, `bottomLine()`) or
`isFollowing()` changed: by the wheel, a scroll call, text arriving while it follows the end,
`setLines()`, a resize. `$log->on('scroll', fn() => $this->requestRender())` keeps a title or
status line showing them current.

**Filtering.** Keep the full list yourself and give the log what should show; `setLines()` lays it
out once, and a reader scrolled back keeps their place when that line is still shown:

```php
$this->all[] = $line;                                   // every line, as it arrives
$log->setLines(array_values(array_filter($this->all, fn(string $l) => str_contains($l, $this->query))));
```

### TextInput

A single line of editable text: `new TextInput(string $value = '', string $prompt = '', string
$placeholder = '', ?Closure $onSubmit = null, ?Closure $onChange = null, ?Style $style = null,
bool $history = false)`; both callbacks receive `(string $value, TextInput $input)`. Enter
calls `$onSubmit` with the text: the submit event, no key handling of your own needed; clear
the field in it with `setValue('')` if you want. With `$history`, submitted lines are
remembered and Up/Down walk through them as in a shell (the line being typed comes back after
the newest); `history()` and `setHistory(array $lines)` save and restore them. Without history,
Up and Down go on to the parent. Keys as in a shell: Left/Right
(Ctrl-B/Ctrl-F), Home/End (Ctrl-A/Ctrl-E), Ctrl-Left/Ctrl-Right or Alt-B/Alt-F by word,
Backspace, Delete (Ctrl-D), Ctrl-W or Alt-Backspace, Ctrl-U, Ctrl-K, Enter submits. Pastes are
inserted with line breaks as spaces. Grapheme-aware; scrolls sideways; shows the cursor while
focused. `value()`, `setValue()`, `cursor()`, `setCursor()`, `setPrompt()`.

### ListView

Rows with one selected, scrolling to keep it in view: a member list, a file tree, a process
list, a pick list. `new ListView(array $items = [], ?Closure $onSelect = null, ?Closure
$onActivate = null, ?Style $selectedStyle = null)`. Items are labels by key (a list, or keys of
your own: file names, pids); a label is one row and may carry colours; a long one is cut with
"…". The selected row is drawn in `$selectedStyle` (inverse video by default) over the label's
colours.

Up/Down (Ctrl-P/Ctrl-N), Page Up/Page Down, Home/End move the selection, Enter activates it; a
click selects a row and a click on the selected row activates it; the wheel scrolls.
`$onSelect` is called when the selection changes, `$onActivate` on activation; both receive
`(int|string $key, string $label, ListView $list)`.

| Method | What it does |
|---|---|
| `setItems(array $items)` | Replace the items; the selection stays on the same key if it is still there, else on the same position. |
| `items(): array` | Label by key. |
| `selected(): int\|string\|null`, `selectedLabel(): ?string` | The selected key and label; null when empty. |
| `select(int\|string $key): bool` | Select it and scroll it into view; false when there is no such key. |
| `topIndex(): int`, `bottomIndex(): int` | The positions in `items()` (0 the first) of the first and last item in view: "12-30 of 200". |

**Event `scroll`** (with the `ListView`): the items in view changed (the wheel, a selection
moved out of view, new items, a resize).

```php
$list = $this->child('members', fn() => new ListView(
    ['frode' => "\e[1mfrode\e[0m (admin)", 'ivar' => 'ivar'],
    onActivate: fn(string $name) => $this->openChat($name),
));
```

### StatusBar

One row with text on the left and right: `new StatusBar(string $left = '', string $right = '',
?Style $style = null)` (inverse video by default), `set($left, $right = '')`, `left()`,
`right()`. When both do not fit, the left one is shortened.

### Box

A border with a title around another component: `new Box(Component $content, string $title =
'', ?Style $style = null, ?Style $focusStyle = null)`; `content()`, `setTitle()`, `title()`. The
border is drawn in `$focusStyle` while the content has the focus. The content is a new
component (the box starts it; it ends with the box) or one you started with `child()` already
(the box shows it; it stays yours, see "A container given an existing child").
