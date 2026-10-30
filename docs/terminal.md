# The terminal: a device attached to the root

The real terminal is not a special kind of surface. It is a **device** attached to a root
Surface: an I/O adapter that shows the root on a terminal and feeds the terminal's input to it.
`Tty` is the real terminal (STDIN and STDOUT); `FakeTerminal` is an in-memory one for tests and
headless programs. Both extend `TerminalDevice` and behave the same.

```php
use phasync\Tui\{Key, Surface, Tty};

$tty = new Tty();
if (!$tty->isTerminal()) {
    exit("Run me in a terminal\n");
}
fiber\run(function () use ($tty) {
    $root = new Surface();
    $root->on('key', function (Key $key) use ($tty) {
        if ($key->is('ctrl+q')) {
            $tty->detach();
            return true;
        }
    });
    $tty->attach($root);                // takes over the terminal, sizes $root to it
    $root->write("Hello! Ctrl-Q quits.");
    $tty->wait();                       // until detached
});
```

## What a device does while attached

- **Draws** the root's damage as the minimum cursor moves and SGR runs against the previous
  frame, at most 60 frames a second and only when something changed, from a coroutine of its
  own. Frames are wrapped in synchronized output (2026) and the cursor is hidden while drawing;
  the cursor shown is the root's `activeCursor()` (the focused window's, with its shape).
- **Decodes input** (keys in legacy, SS3, CSI, kitty and modifyOtherKeys forms, Alt prefixes,
  UTF-8; SGR mouse with buttons, wheel and drags; bracketed paste; focus in/out) into events for
  `Surface::dispatch()` on the root. A lone ESC is the Escape key after 50 ms.
- **Follows the size**, resizing the root, which re-lays its windows down to the ptys.
- **Passes up** the root's title (OSC 2), bell and clipboard requests (OSC 52).
- **Takes the terminal over**: raw mode, the alternate screen, mouse reporting (button events,
  SGR encoding, and motion without a button while a program anywhere in the tree asks for it
  with mode 1003), bracketed paste and focus reporting, and the kitty keyboard protocol
  (disambiguated keys) when the terminal answers the query for it, so keys like Ctrl-I and
  Shift-Enter reach the programs in the windows as such. `detach()` restores all of it.

| Method | What it does |
|---|---|
| `attach(Surface $root)` | Show `$root` and send it the input, until `detach()`. Throws `LogicException` when already attached. |
| `detach()` | Give the terminal back: modes off, main screen, cursor shown, settings restored. Its coroutines end, and the root is closed (`Surface::close()`), which disposes a component on it. Safe to call twice. |
| `isAttached(): bool`, `root(): ?Surface` | |
| `wait()` | Wait until `detach()` (returns at once when not attached). |
| `refresh()` | Draw what changed now instead of at the next frame. |
| `size(): array` | The terminal's size as `[cols, rows]`. |
| `hasKittyKeyboard(): bool` | True once the terminal answered the kitty keyboard query. |

## How a program ends

`fiber\run()` returns when the function given to it has returned **and** every coroutine started
inside it has ended. In a program built with this library, these keep it running:

- an attached terminal device (its frame and input coroutines run until `detach()`);
- every `Pty` whose program is still running (its output pump runs until the program exits);
- every mounted component's coroutines (`run()`, `go()`), until it is disposed;
- coroutines of your own.

And this is what ends them:

- **Quitting** is detaching the device, or closing the root surface, or disposing the root
  component; each leads to the others. The component tree is disposed, so every `Terminal`
  component hangs its program up (SIGHUP, then SIGKILL after half a second), every component's
  coroutines are told to stop (`sleep()` returns false, `stopped()` resolves), and the device
  gives the terminal back. A `Pty` you started yourself with `Pty::start()` is yours: kill it (or
  let it exit) yourself.
- **A failure** of the root component (an exception in its `mount()`, `render()`, handlers or
  coroutines) ends the program the same way, then the exception is thrown: by `start()` when it
  happened there, else by `fiber\run()`. A failure inside a child component is contained: the
  child and its children are disposed (their programs hung up) and its rectangle shows the error.

```php
fiber\run(function () {
    (new App())->start(new Tty());   // run() returns once the app quits (or rethrows its failure)
});
```

## Tty

`new Tty($in = STDIN, $out = STDOUT)`; `isTerminal()` is false when input or output is
redirected (attaching then throws `RuntimeException`). The terminal settings are saved and set
to raw mode with `stty`, and restored on `detach()`, and also when the program ends any other
way: an uncaught exception, a fatal error, `exit()`, or SIGTERM, SIGHUP, SIGINT or SIGQUIT (with
ext-pcntl; the program then exits with 128 + signal). The size is re-read on SIGWINCH with
ext-pcntl, else polled twice a second. Text copied in a window goes to the clipboard with OSC 52
and also through `wl-copy`, `xclip` or `pbcopy` when one is installed and a display is available.
Writes to the terminal never block other coroutines and never interleave.

## FakeTerminal

A device whose terminal is itself a `Surface` (`$terminal->surface`): the device's output is
parsed by the same emulator, which also answers the device's queries. So in a headless program
or a test, **what the terminal shows must equal the root's cells**, and the library's own output
is checked by its own emulator.

```php
fiber\run(function () {
    $root = new Surface();
    $shell = new Surface();
    $root->setLayout(Split::horizontal()->add($shell));
    $terminal = new FakeTerminal(80, 24);
    $terminal->attach($root);
    $pty = Pty::start('/bin/sh', $shell);
    $terminal->type("echo hi\n");
    $terminal->waitFor('hi');                  // pty output comes later
    assert($terminal->surface->dump() === $root->dump());
    $pty->kill();
    $terminal->detach();
});
```

Input calls deliver at once: when `press()` returns, the root has dispatched the key. Input is
encoded by the fake terminal's surface for the modes the device enabled, as a real xterm-class
terminal would.

| Method | What it does |
|---|---|
| `new FakeTerminal(int $cols = 80, int $rows = 24)` | |
| `text(): string`, `dump(): string` | What the terminal shows (see `Surface::text()`/`dump()`), after drawing what is pending. |
| `output(): string`, `takeOutput(): string` | Every byte the device sent, or those since the previous call. |
| `clipboardText(): string` | The last text copied to the clipboard (OSC 52). |
| `waitFor(string\|Closure $condition, float $timeout = 5.0): bool` | Wait until the screen contains the text (or the closure, given the terminal, returns true), drawing as it goes. |
| `resize(int $cols, int $rows)` | As when the user resizes the window. |
| `press(Key\|string ...$keys)` | Press keys: `Key` objects or notations (`'ctrl+c'`, `'enter'`, `'a'`). |
| `type(string $text)` | One key per character; `"\n"` is Enter. |
| `paste(string $text)` | A bracketed paste. |
| `mouse(Mouse $event)`, `click($x, $y, $button = Mouse::LEFT)`, `drag($x1, $y1, $x2, $y2)`, `scroll($x, $y, $clicks)` | Mouse input at terminal coordinates; negative clicks scroll up. |
| `focus(bool $focused)` | The terminal window gains or loses focus. |
| `feed(string $bytes)` | Raw bytes, as if the terminal sent them. |
