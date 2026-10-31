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
  own. Components of the tree marked for a render render at the start of a frame, so all that
  was asked for since the last one is one render pass and one draw. Frames are wrapped in synchronized output (2026) and the cursor is hidden while drawing;
  the cursor shown is the root's `activeCursor()` (the focused window's, with its shape).
- **Decodes input** (keys in legacy, SS3, CSI, kitty and modifyOtherKeys forms, Alt prefixes,
  UTF-8; SGR mouse with buttons, wheel and drags; bracketed paste; focus in/out) into events for
  `Surface::dispatch()` on the root. A lone ESC is the Escape key after 50 ms.
- **Follows the size**, resizing the root, which re-lays its windows down to the ptys.
- **Passes up** the root's title (OSC 2), bell and clipboard requests (OSC 52).
- **Writes hyperlinks** (OSC 8) of the root's cells when the terminal supports them
  (`supportsHyperlinks()`); to one that does not, the text alone.
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
| `refresh()` | Draw what changed now instead of at the next frame: the renders that are pending run first. |
| `TerminalDevice::of(Surface $root): ?TerminalDevice` | The device a root is attached to, or null. A component reaches its own with `$this->device()`. |
| `size(): array` | The terminal's size as `[cols, rows]`. |
| `hasKittyKeyboard(): bool` | True once the terminal answered the kitty keyboard query. |
| `supportsHyperlinks(): bool` | True if the terminal shows OSC 8 hyperlinks, so the device writes the cells' links (see below). |
| `terminalName(): string` | The name the terminal gave when asked (XTVERSION) at `attach()`, such as `kitty(0.36.4)`; `''` when it did not answer. |
| `isInline(): bool` | True for a device made with `inline()` (see below). |
| `background(): ?string` | `'light'` or `'dark'`: the terminal's background, as it answered when asked (OSC 11) at `attach()`; null when it did not answer (yet). A program that sets no theme is drawn in `light` on a light terminal, else in `dark` (see [styling.md](styling.md#themes)); the answer comes a moment after `attach()`, never holding start-up up, and the screen is drawn again when it says light. |
| `colors(): int`, `setColors(int $colors)` | The colours the terminal has: `Color::DEPTH_16`, `DEPTH_256` or `DEPTH_TRUECOLOR`. Cells in colours it lacks are drawn in the nearest it has (see below). |
| `println(string $text = '')` | Plain output of the program: in inline mode above the live region, at the next frame; not attached, to the terminal at once; attached full screen, after `detach()` (the alternate screen would hide it). Escape sequences in it work; a line break ends each call. |
| `freeze(int\|Surface $what)` | Keep rows of the root as they show now: a row by number, or the rows a surface covers (a component's: `$tty->freeze($bar->surface)`). Inline, they go above the region at the next frame, into the scrollback, never to change; full screen, they are written after `detach()`. See "Freezing a finished row". |

**Hyperlinks.** A cell's style carries the hyperlink it is in (see [surface](surface.md)); the
device writes OSC 8 around such cells only when `supportsHyperlinks()` is true, so a program
can print links and they are clickable where the terminal allows and plain text elsewhere.
`Tty` decides conservatively: from the environment (`TERM_PROGRAM` iTerm, WezTerm, VS Code,
ghostty; kitty; Windows Terminal; VTE 0.50 and later; `TERM` foot, alacritty and the like) and
else from the terminal's XTVERSION answer (kitty, WezTerm, foot, ghostty, iTerm2, contour, and
this library's own emulator, so a nested instance in a `Terminal` pane has them); never inside
tmux or screen, which may not pass them on. `FakeTerminal` is told: `new FakeTerminal(80, 24,
hyperlinks: false)`; true by default, since its surface keeps them.

**Colours.** Cells keep the colours the program chose; the device draws each in the nearest
colour the terminal has, in this one place (`Color::nearest()`): a truecolour cell becomes one
of the 256-colour cube and grays on a 256-colour terminal, one of the 16 on a 16-colour one.
`Tty` knows the depth from the environment (`Tty::colorsFromEnvironment($env)`: `COLORTERM`
truecolor or 24bit, or a terminal known to have them, is truecolour; a `TERM` with `256` in it
is 256; else 16); `setColors()` corrects a terminal that says it wrong. `FakeTerminal` is told:
`new FakeTerminal(80, 24, colors: Color::DEPTH_16)`; truecolour by default. Its background is
told the same way: `new FakeTerminal(80, 24, background: 'light')` answers OSC 11 as a light
terminal (and its surface's default colours are black on white), `background: null` does not
answer; `'dark'` by default.

## Inline mode: a live region in the terminal's normal screen

A program that shows a few progress bars, or a line per process it runs, should not take the
terminal over. `Tty::inline()` (and `FakeTerminal::inline()` for tests) is a device that shows
the root **in the terminal's normal screen**, as a live region under what the terminal already
shows. Nothing else changes: the same root, the same component tree, attached with
`attach()` or `$app->start($device)`.

```php
fiber\run(function () {
    $root = new Surface();
    $tty = Tty::inline();
    $tty->attach($root);
    foreach ($jobs as $i => $job) {
        $root->write("\e[?25l{$job->name}: starting\r\n");   // a line per job; the cursor waits under them
    }
    // ... update a job's line with cursor moves; finished lines are pushed up by newer ones
    $tty->println('all done');
    $tty->detach();                                          // the last frame stays, the prompt comes under it
});
```

- **The region** is the root's rows down to the last one with something in it (a character or
  a colour; the row of the cursor too, while the root shows its cursor). The root is as wide as
  the terminal and one row lower: the line under the region is where the terminal's cursor
  waits (unless the root shows its cursor inside the region), and where the shell's prompt
  appears after the program.
- **The root's scrollback is the terminal's.** A row that scrolls off the root's top (a program
  printing more lines than fit) is written once more above the region, as it was at that moment,
  and is never touched again: a progress line that went from 1% to 100% stays live while it is
  in the region and freezes at what it showed when newer lines pushed it out, exactly as if the
  program had printed it. `println()` puts lines there too, between frames, and the region is
  drawn again under them.
- **Drawing** uses relative cursor motion only (the region moves whenever the terminal scrolls);
  the damage is drawn against the previous frame as in full-screen mode.
- **No alternate screen, no mouse reporting** unless a surface in the tree asks for it (by
  setting mode 1000, 1002 or 1003, as a program does: `$root->write("\e[?1002h")`), so the
  terminal's own selection and scrolling keep working. Raw mode only while attached.
- **Ending**: `detach()` (or quitting the component tree) leaves the last frame standing with
  the cursor under it; `Tty::inline(keep: false)` erases the region instead. The terminal is
  restored as in full-screen mode, also on a crash or a signal.
- **As tall as its content**: a component tree in inline mode is laid out at the height it
  needs, not the terminal's: a `Row` of three boxes is a region of the boxes' height, a `'1fr'`
  item gets no more than its content. (Full screen, the root fills the terminal.)
- **Taller than the terminal**: the region is at most the terminal's height less the line
  under it. The root is laid out at that height, so the layout decides what is left out: a
  `Column` of more rows than fit gives the first rows their room, and the rest are clipped and
  never painted (their components are not drawn, and nothing of them is written). A program
  that grows (a line per job) shows the last rows with a `ScrollView`, or renders only the last
  N rows, or freezes the finished ones (below).
- **Resizes**: a width change repaints the region (terminals that rewrap their lines on a width
  change may leave a copy of its rows above it); a terminal made lower than the region pushes
  the region's top rows into its scrollback, as the content growing does.
- **Limits**: while the region is live, keys come through the library (`on('key')`, bindings),
  not `fgets(STDIN)`. A full-screen program (vim) in a `Terminal` inside an inline region works
  but is pointless. The region starts on the line the cursor is on: print a newline first if
  that line has text.

`examples/inline-progress.php` runs a few jobs with a progress bar each and a total, as a
component tree; the same tree runs full screen with `new Tty()`.

### Freezing a finished row

A program that runs jobs and shows a line for each keeps the finished ones as they ended, above
the live part, with `freeze()`: the row goes into the terminal's scrollback exactly as the
region shows it, and the program stops showing it, so the region is only what still runs. No
second formatting for `println()`:

```php
// in the component's coroutine, when a job ends
$this->state->done[$job] = 1.0;
$this->device()?->println("copied $job");
$this->device()?->freeze($this->child("job:$job")->surface);    // the row as it shows 100%
unset($this->state->done[$job]);                  // the next render leaves it out
```

`freeze()` takes the rows as the root shows them after the renders that are pending ran, so
the row it keeps is the one the last state change drew (100%, not the 75% of the frame before).
`device()` is the device the component's tree is shown on, also one that `Tui::inline()` or
`Tui::headless()` made, and null once the tree is no longer attached.

| Method | What it does |
|---|---|
| `Tty::inline($in = STDIN, $out = STDOUT, bool $keep = true)` | The terminal in inline mode. |
| `FakeTerminal::inline(int $cols = 80, int $rows = 24, bool $keep = true, bool $hyperlinks = true, int $colors = Color::DEPTH_TRUECOLOR, ?string $background = 'dark')` | An in-memory terminal in inline mode, for tests. |
| `FakeTerminal::frozen(): string` | The text above the region (the terminal's scrollback first): the lines written above it as the root scrolled and by `println()`, as they were then, and what the terminal showed before. |
| `FakeTerminal::region(): string` | The text of the live region (`''` when not attached). |

`FakeTerminal::text()`, `region()` and `refresh()` draw what is pending first, renders
included, so a test reads the screen as the next frame would show it.

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
- **A failure of the code that attached** (the closure given to `fiber\run()` throws after
  `attach()` or `start()`, or the coroutine that called them does): the device detaches by
  itself, so the terminal is restored, the tree is disposed, and `fiber\run()` throws the
  exception, which PHP then prints. The device watches the task that called `attach()`
  through `fiber\current()`; that needs fiber-interop/api newer than 0.3.1 and, on phasync,
  fiber-interop/phasync newer than 0.4.1 (their main branches until the next tags). With older
  versions, use `Tui::run()`, which owns the run and always sees the exception.

```php
fiber\run(function () {
    (new App())->start(new Tty());   // run() returns once the app quits (or rethrows its failure)
});

Tui::run(new App());                 // the same in one call, Ctrl-Q quitting by default
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

Input calls deliver at once: when `press()` returns, the root has dispatched the key. There are
three ways to send keys and text:

```php
$terminal->type("ls -l\n");                 // text, one key per character ("\n" is Enter)
$terminal->press('ctrl+c', 'up', 'f5');     // keys by name
$terminal->feed("\e[1;5A\e[<0;3;2M");        // raw bytes, exactly as a terminal sends them
```

`type()` and `press()` are encoded by the fake terminal's surface for the modes the device
enabled (bracketed paste, SGR mouse, the kitty keyboard protocol), as a real xterm-class
terminal would, and then decoded like real input. `feed()` skips the encoding: the bytes go to
the decoder as they are, for sequences the other calls cannot express or bytes captured from a
real terminal. Each `feed()` is one read followed by a pause: a sequence must be whole within the
call (a bracketed paste may span calls), and a lone ESC at the end is the Escape key.

**Reading the screen after input, and tests that fail.** Input is dispatched at once, but a
component renders in the next frame: read the screen after `settle()`, which waits until the
renders the input asked for have run and the screen is drawn (`text()` and `dump()` draw what
is pending first, too). Frames come at most 60 a second, so a test that waits for a render
with `fiber\sleep()` sleeps a sixtieth of a second or more. Never `usleep()` in a coroutine:
it blocks the loop, so nothing renders (nor anything else runs) while it sleeps; use
`settle()`, `waitFor()` or `fiber\sleep()`. Wrap the test in `run()`: it detaches the terminal
afterwards, also when the test throws, so a failing assertion ends the program and reaches
`fiber\run()` instead of the terminal's coroutines keeping it running for ever.

```php
fiber\run(function () {
    $app = (new App())->start($terminal = new FakeTerminal(40, 10));
    $terminal->run(function (FakeTerminal $t) {
        $t->type('hello');
        $t->settle();
        expect($t->text())->toContain('hello');
    });
});
```

| Method | What it does |
|---|---|
| `new FakeTerminal(int $cols = 80, int $rows = 24, bool $hyperlinks = true, int $colors = Color::DEPTH_TRUECOLOR, ?string $background = 'dark')` | A terminal of that size; with `$hyperlinks` false, one that does not show OSC 8 links; with `$colors` 256 or 16, one that has only those colours; `$background` is what it answers when asked for its background (`'light'`, `'dark'`, null: no answer). `FakeTerminal::inline()` for inline mode (see above). |
| `run(Closure $code): mixed` | Run `$code($terminal)` and detach afterwards, also when it throws (then the exception goes on to `fiber\run()`). Returns what `$code` returned. |
| `settle(float $timeout = 1.0)` | Wait until the renders the input so far asked for have run and the screen is up to date (cooperatively, `$timeout` at most). |
| `text(): string`, `dump(): string` | What the terminal shows (see `Surface::text()`/`dump()`), after drawing what is pending. |
| `output(): string`, `takeOutput(): string` | Every byte the device sent, or those since the previous call. |
| `clipboardText(): string` | The last text copied to the clipboard (OSC 52). |
| `waitFor(string\|Closure $condition, float $timeout = 5.0): bool` | Wait until the screen contains the text (or the closure, given the terminal, returns true), drawing as it goes. |
| `resize(int $cols, int $rows)` | As when the user resizes the window. |
| `press(Key\|string ...$keys)` | Press keys: `Key` objects or notations (`'ctrl+c'`, `'enter'`, `'a'`). |
| `type(string $text)` | Text: one key per character; `"\n"` is Enter, `"\t"` Tab. For text only: arrows, function keys and modified keys go through `press()`, escape sequences through `feed()`; other control characters are refused (`InvalidArgumentException`). |
| `paste(string $text)` | A bracketed paste. |
| `mouse(Mouse $event)`, `click($x, $y, $button = Mouse::LEFT)`, `drag($x1, $y1, $x2, $y2)`, `scroll($x, $y, $clicks)` | Mouse input at terminal coordinates; negative clicks scroll up. |
| `focus(bool $focused)` | The terminal window gains or loses focus. |
| `feed(string $bytes)` | Raw bytes as the terminal sends them (UTF-8 text, CSI/SS3/kitty keys, SGR mouse, bracketed paste, focus reports); one call is one read followed by a pause. |
