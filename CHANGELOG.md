# Changelog

## 0.0.1-beta2 (2026-10-30)

**Text that fits the window it is in**

- `Log` keeps its lines and lays them out for the width it has now. Narrowing the terminal no
  longer cuts the lines off for good, widening it no longer leaves them cropped, and lines
  appended before the log got its real size (in `mount()`, or before the terminal was attached)
  no longer show wrapped for 80 columns and cut. Programs no longer need to keep their own copy
  and re-append it on resize. After a resize the visible part is right at once; a long
  scrollback follows a moment later.
- `Log` wraps between words with `new Log(wordWrap: true)` (or `setWordWrap()`), and by display
  width either way: wide characters and emoji count two cells and never split.
- `Log::setLines()` replaces the lines in one go, to re-style, edit or filter what is shown
  without clearing and appending line by line. `Log::lines()` now returns the lines as they were
  given, escape sequences included (it returned the visible text before).
- New `Text::wrap($text, $width, $words)` lays out text the way a surface of that width shows
  it, for your own text components. `Text::width()`, `truncate()` and `pad()` now ignore escape
  sequences: `Text::width("\e[31mred\e[0m")` is 3, no longer 10.
- A child component renders for the first time once its layout has given it its size, and a root
  started on a terminal (`start(new Tty())`) renders first at the terminal's size: no first frame
  drawn for a guessed 80x24.

**Quitting ends everything; a failure is reported, not a hang**

- `Component::start()` takes a terminal device: `fiber\run(fn() => (new App())->start(new Tty()))`
  is a whole program. It returns when the app quits.
- Detaching the terminal (quitting) disposes the component tree: every `Terminal` component
  hangs its program up (SIGHUP, then SIGKILL after half a second), so `fiber\run()` returns
  without the program killing its shells by hand. Disposing the root component, or closing the
  root surface (`Surface::close()`, new), detaches the terminal. A `Pty` you start yourself
  stays yours.
- An exception in the root component's `mount()` or `render()` (also a later render), its
  handlers or coroutines no longer hangs the program silently: the tree is disposed, the
  terminal restored, and the exception is thrown by `start()` or by `fiber\run()`. A failing child
  component now also ends its own children (their programs are hung up).
- The docs say plainly what keeps `fiber\run()` running and what ends it (README, components,
  terminal).

**Focus follows the windows on the screen**

- `Component::focus()` and `hasFocus()` now work whatever is between the root and the
  component: a plain surface with a layout of its own (the documented way to nest layouts) no
  longer makes `focus()` do nothing and `hasFocus()` stay false.
- Focus is a property of the surface tree: every surface knows its `parent()`, its own
  `viewport()`, its `root()` and its `area()` on the root (a new `Rect`). `Surface::focus()` focuses
  the way down from the root to it, also before it is shown (it applies when a layout places it),
  and the old and new focus each get one focus event. `hasFocus()` reads the real path;
  `takeFocus()` keeps the keys on a host itself. **Changed:** `$host->focus($child)` is now
  `$child->focus()`, and `$host->focus(null)` is `$host->takeFocus()`.
- Directional focus: `focusToward('left' | 'right' | 'up' | 'down')` moves to the window on that
  side of the focused one, from the geometry of the windows, next to `focusNext()`.
- Layouts nest directly: `Split::horizontal()->add($a)->add(Split::vertical()->add($b)->add($c))`,
  also in a `Stack`, without a hand-made surface in between. The surface the outer layout makes
  for the inner one is kept when a component's `render()` builds the layouts anew, so windows
  and focus survive every render. **Changed:** `Split`/`Stack` `surfaces()` is now `items()`;
  `host()` returns the surface a layout arranges.
- `setFocusOnClick(true)` on the root now applies to the windows of nested layouts too.

**Programs announce when they end**

- `Pty::start(..., onExit: fn(int $code, Pty $pty) => ...)` and `Pty::exited()`, an awaitable
  resolving with the exit status, so a host can react without a coroutine that `wait()`s.
- `Terminal` component: `exited()` awaitable; `$onExit` now receives the component too and runs
  in a coroutine of it (it may wait; a failure is contained); `restart()` runs the command again
  in the same window, to restart a program that ended or hang up and replace one that runs.

**Built-ins**

- `TextInput(history: true)`: Up/Down walk through the submitted lines as in a shell, the line
  being typed comes back after the newest; `history()` and `setHistory()` save and restore them.
  Enter's `$onSubmit` is the submit event (documented as such); without history, Up and Down go
  on to the parent.
- New `ListView`: rows by key with one selected, keyboard (arrows, Page Up/Down, Home/End,
  Enter) and mouse (click, click again to activate, wheel), scrolling that keeps the selection in
  view, `onSelect`/`onActivate` events, `setItems()` that keeps the selection on the same key,
  coloured labels with the selection drawn over them. For member lists, file trees, process
  lists and pick lists.

**Emulator and terminal**

- Mouse motion without a button held (mode 1003): the terminal device now asks the real
  terminal for it while any program in the tree wants it, at any depth, and goes back to button
  events when none does. Before, such programs only saw motion while a button was held.
- The Claude Code acceptance test now also drives its first-run screens (in a home of its own,
  so nothing of the user's configuration is touched): menu selection with the arrow keys, the
  live redraw of its preview, truecolor, and a resize; the trust-prompt test is kept.

**Examples**

- The examples start on the terminal and quit by disposing the root, so the programs in them
  are hung up and the terminal given back. `split-shells.php` moves the focus with Ctrl-B and an
  arrow, nests its layouts directly, and ends when both shells have exited.

## 0.0.1-beta1 (2026-10-30)

The first version: a terminal UI library built on a real terminal emulator.

- **Surface**, a terminal emulator in memory: xterm-compatible (`TERM=xterm-256color`), parsed
  character by character by a parser generated from a PEG grammar. Text with autowrap,
  grapheme clusters and wide characters, cursor movement, erasing, insert/delete, scroll regions,
  SGR with 16/256/truecolor, private modes (alternate screen, mouse, bracketed paste, focus
  events, synchronized output, application keys), the kitty keyboard protocol and
  modifyOtherKeys, tab stops, charsets including DEC line drawing, titles, OSC 52 clipboard,
  scrollback, and answers to the queries programs send (cursor position, device attributes,
  versions, modes, colours). Damage tracking for any number of readers.
- **Producers**: `Pty` runs a program in a pseudo-terminal with a controlling terminal and the
  surface's size, following resizes; `Surface::run()` runs a PHP closure as a producer, capturing
  its `echo` with ext-phasync. `Input`, `InputDecoder`, and the `Key`, `Mouse`, `Paste`, `Focus`
  events.
- **Hosting**: a Surface hosts Surfaces through `Viewport`s to any depth. Hosts composite by
  writing escape sequences to themselves, always as a diff: only the cells that changed, in runs,
  never those under a window above, so a busy pane costs its changed cells and an idle one nothing; input goes down the focus path and is encoded for each
  program's modes; mouse wheel scrolling and drag-to-select with copy (OSC 52) where a program did
  not ask for the mouse; titles, bells and clipboard requests go up.
- **Terminal devices**: `Tty` attaches the real terminal to a root Surface (minimal redraws at up
  to 60 frames a second, input decoding, resizes, raw mode and restore on exit, crashes and
  signals, kitty keyboard protocol when available); `FakeTerminal` does the same in memory for
  tests and headless programs.
- **Layout**: `Split` (side by side or stacked, sizes in cells, percentages or weights, optional
  separators) and `Stack` (overlays and dialogs).
- **Components**: `Component` with mount/render/run/unmount, batched renders, keyed children,
  coroutines that end on disposal, event handlers and an error boundary; built-ins `Terminal`,
  `Log`, `TextInput`, `StatusBar` and `Box`.
- **Examples**: `split-shells.php` (two shells, nestable like tmux in tmux), `host.php` (any
  command in a window, e.g. Claude Code), `coroutine-pane.php`, `dashboard.php`.
