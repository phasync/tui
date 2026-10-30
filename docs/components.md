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
4. `render()` runs again after every resize and after `requestRender()` (batched: calls in the
   same moment give one render, as soon as the current coroutine yields).
5. `dispose()` ends it: children are disposed first, the `stopped()` awaitable resolves and
   `sleep()` returns false, disposal waits up to a second for the component's coroutines to
   return, then `unmount()` runs. The surface keeps its last content. A component is disposed by
   its parent (when a render no longer asks for it, or the parent ends), by `dispose()`, or when
   its surface is closed (`Surface::close()`).

Coroutines are not cancelled from outside (fiber-interop has no cancellation): write loops as
`while ($this->sleep(...))`, check `isMounted()`, or race a wait against `stopped()`:
`fiber\await_any([$work, $this->stopped()])`.

## How a program ends

```php
fiber\run(function () {
    (new App())->start(new Tty());
});   // returns when the app has quit; rethrows the exception if it failed
```

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
- **A failing child** is contained to its rectangle (see below); its own children end with it.

What keeps `fiber\run()` running: an attached terminal device, the programs of running ptys,
and the coroutines of mounted components and your own.

## Hooks to override

| Hook | When it runs |
|---|---|
| `mount(): void` | Once, at start: props, state, children. Not sized yet. |
| `sized(): void` | Once, when the component has its real size, right before the first render: start work that depends on the rectangle here. |
| `render(): ?Layout` | Draw into `$this->surface` and return null, or return a layout of child components' surfaces. |
| `run(): void` | The component's coroutine, started after the first render. |
| `unmount(): void` | Once, after disposal, when the coroutines have ended. |
| `onKey(Key $key): bool` | A key reached the surface; return true if handled. |
| `onMouse(Mouse $mouse): bool` | A mouse event, in the component's coordinates; true if handled. |
| `onPaste(Paste $paste): bool` | Text pasted while the component is on the focus path; true if handled. |
| `onFocus(Focus $focus): void` | The component gained or lost the focus (a render follows). |
| `onResize(Resize $size): void` | The surface was resized (a render follows). |

Event handlers run as the event travels down the surface tree (see [hosting](hosting.md)): a
component sees an event before its focused child does, and returning true stops it there. That
is where application-wide keys go (a quit key on the root component); a widget that handles
keys itself (a text field) returns true for the keys it uses.

## Methods

| Method | What it does |
|---|---|
| `surface` | The component's surface (readonly; set by `start()`). |
| `start(Surface\|TerminalDevice\|null $on = null): static` | Mount a root component, on a terminal device, a surface, or a new surface. Throws when already started; rethrows a failure of its first `mount()`/`render()` after disposing what was started. |
| `dispose(float $timeout = 1.0)` | End the component (see the lifecycle); for the root, its surface is closed first, which detaches the terminal. Safe to call twice. |
| `isMounted(): bool` | True between start and dispose. |
| `stopped(): object` | An awaitable that resolves on disposal. |
| `requestRender()` | Ask for a render (batched). |
| `focus()` | Give it the focus: every host from the root down focuses the way to it, components or plain surfaces or nested layouts (see `Surface::focus()`). It takes effect at once, for the very next key: also for a child not shown yet (a dialog created in a key handler, shown by the render that follows), whose keys and pastes go to it through its parent until that render shows it. The focus stays with it through every re-render, also one that moves it into a different nested layout; when it is no longer shown, the component focused before it gets the focus back. |
| `hasFocus(): bool` | True if it is on the focus path from the root (read from the surface tree). |
| `parent(): ?Component` | The parent, null for a root. |
| `error(): ?Throwable` | The exception that ended it, if one did. |
| `child(string $key, Closure $create): Component` (protected) | The child under `$key`, created with `$create` and started the first time it is asked for. After a render that returned a layout, children it did not ask for are disposed. |
| `go(Closure $task)` (protected) | Another coroutine of the component: contained like `run()`, waited for on disposal. |
| `sleep(float $seconds): bool` (protected) | Sleep, less if disposed meanwhile; returns `isMounted()`. |

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

## Errors stay in their rectangle

An exception in a child's hooks, handlers or coroutines is contained to that child: it is
disposed with its children (their programs hung up) and its rectangle shows the error
(`✖ RuntimeException: ...`); the rest of the program keeps running. An exception in the root
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

All in `phasync\Tui\Component\`.

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
| `setLines(array $lines)` | Replace all lines (without `"\n"`), laid out once: the way to filter, re-style, edit or re-sort what is shown. A view scrolled back stays on the line at its top if it is among the new lines, else it follows the end. |
| `clear()` | Remove all text. |
| `lines(): list<string>` | The lines as given (escape sequences included), oldest first; an unfinished last line included. |
| `setWordWrap(bool $on)`, `wordWrap(): bool` | Wrap between words or at any character; lays out again. |
| `scrollBy(int $rows)`, `scrollTo(int $row)`, `follow()`, `isFollowing()` | Look back and follow the end again. |

The mouse wheel scrolls it and a drag selects and copies. A view scrolled back stays on the same
line when the log is laid out again (a resize, a change of wrapping, `setLines()`).

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
border is drawn in `$focusStyle` while the content has the focus.
