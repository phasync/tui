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

$tty = new Tty();
fiber\run(function () use ($tty) {
    $clock = (new Clock())->start();
    $tty->attach($clock->surface);                  // the root component's surface is the root
    $clock->surface->on('key', fn() => $tty->detach());
    $tty->wait();
    $clock->dispose();
});
```

## Lifecycle

1. `start(?Surface $surface = null)` mounts a root component, on `$surface` or a new surface
   that a terminal device or a layout will size. Child components are started by their parent's
   `child()`.
2. `mount()` runs once: set up state here.
3. `render()` runs, then `run()` starts as a coroutine.
4. `render()` runs again after every resize and after `requestRender()` (batched: calls in the
   same moment give one render, as soon as the current coroutine yields).
5. `dispose()` ends it: children are disposed first, the `stopped()` awaitable resolves and
   `sleep()` returns false, disposal waits up to a second for the component's coroutines to
   return, then `unmount()` runs. The surface keeps its last content.

Coroutines are not cancelled from outside (fiber-interop has no cancellation): write loops as
`while ($this->sleep(...))`, check `isMounted()`, or race a wait against `stopped()`:
`fiber\await_any([$work, $this->stopped()])`.

## Hooks to override

| Hook | When it runs |
|---|---|
| `mount(): void` | Once, before the first render. |
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
| `start(?Surface $surface = null): static` | Mount a root component. Throws when already started. |
| `dispose(float $timeout = 1.0)` | End the component (see the lifecycle). Safe to call twice. |
| `isMounted(): bool` | True between start and dispose. |
| `stopped(): object` | An awaitable that resolves on disposal. |
| `requestRender()` | Ask for a render (batched). |
| `focus()` | Give it the focus: every host from the root down focuses the way to it. |
| `hasFocus(): bool` | True if it is on the focus path from the root. |
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

## Errors stay in their rectangle

An exception in a child's hooks, handlers or coroutines is contained to that child: it is
disposed and its rectangle shows the error (`✖ RuntimeException: ...`); the rest of the program
keeps running. An exception in the root component is not caught: it ends `fiber\run()`, and
`Tty` restores the terminal on the way out.

## Built-in components

All in `phasync\Tui\Component\`.

### Terminal

A command in a pseudo-terminal, as a component: `new Terminal(string|array $command, ?string
$cwd = null, array $env = [], ?Closure $onExit = null)`. The program gets the component's surface
as its terminal: size and resizes, keys and pastes while focused, answers to its queries. When
it exits, `$onExit($code)` is called and the window keeps its last output. Disposing hangs the
program up (SIGHUP, then SIGKILL after half a second). `pty(): ?Pty`, `isRunning()`,
`exitCode()`.

### Log

Append-only text with scrollback that follows the end: `new Log(int $scrollback = 10000)`.
`append(string $text)` adds text (`"\n"` starts a line; escape sequences style it), `clear()`,
`scrollBy($rows)`, `scrollTo($row)`, `follow()`, `isFollowing()`, `lines()` (all lines, oldest
first, wrapped lines joined). The mouse wheel scrolls it and a drag selects and copies.

### TextInput

A single line of editable text: `new TextInput(string $value = '', string $prompt = '', string
$placeholder = '', ?Closure $onSubmit = null, ?Closure $onChange = null, ?Style $style = null)`;
both callbacks receive `(string $value, TextInput $input)`. Keys as in a shell: Left/Right
(Ctrl-B/Ctrl-F), Home/End (Ctrl-A/Ctrl-E), Ctrl-Left/Ctrl-Right or Alt-B/Alt-F by word,
Backspace, Delete (Ctrl-D), Ctrl-W or Alt-Backspace, Ctrl-U, Ctrl-K, Enter submits. Pastes are
inserted with line breaks as spaces. Grapheme-aware; scrolls sideways; shows the cursor while
focused. `value()`, `setValue()`, `cursor()`, `setCursor()`, `setPrompt()`.

### StatusBar

One row with text on the left and right: `new StatusBar(string $left = '', string $right = '',
?Style $style = null)` (inverse video by default), `set($left, $right = '')`, `left()`,
`right()`. When both do not fit, the left one is shortened.

### Box

A border with a title around another component: `new Box(Component $content, string $title =
'', ?Style $style = null, ?Style $focusStyle = null)`; `content()`, `setTitle()`, `title()`. The
border is drawn in `$focusStyle` while the content has the focus.
