# Changelog

## 0.0.1-beta4 (2026-10-30)

**A dialog focused before it is shown keeps the keyboard, down to its text field**

- A dialog created and focused in a key handler (`$dialog->focus(); $this->requestRender();`)
  whose `mount()` focuses a `TextInput` inside it: the keys typed before the next frame no
  longer stop at the dialog's own `onKey()`, they reach the text field, and after the frame
  they keep going there. Before and after the render that shows it, one rule decides: the
  latest `focus()` wins, and a focused component passes the keys on to the descendant it
  focused. Nothing has to be repeated in `render()`.
- `Component::hasFocus()` is true for exactly the components the keys go to or through: the
  dialog and its text field while the dialog is not shown yet, and no longer for the window
  that had the focus before (it used to say true for any component not shown).
- A `Box` focused before it is shown passes keys to its content right away (the content is
  the box's child from its `mount()` on). A component that has not rendered yet and focused
  nothing below it passes keys to its first child, as a host gives them to its first window.

**A component that reads its own rectangle is drawn again when the rectangle changes**

- A component whose `render()` shows its size or position, or lays itself out from it, no
  longer keeps showing the old one after a layout moved it without resizing it (a `Stack` item
  placed at another `x`, a split around a nested layout that moved), or after a window it is in
  was moved by hand: the library renders a component again whenever its area (its size, or
  where it shows on the screen, `Surface::area()`) changed since its last render. No resize
  handler calling `requestRender()` is needed.
- New surface event `area`: `$surface->on('area', fn(Rect $area) => ...)` is told when where
  the surface shows on its root changed (moved, resized, scrolled, hosted, unhosted), once per
  change of the tree.

**Go to a line of a Log, and show which lines are in view**

- `Log::scrollTo()` takes a row of the wrapped text, which an application could not compute
  (a long line takes several rows, depending on the width), so "go to line N" and "line X of Y"
  were impossible. New: `Log::scrollToLine(int $line)` shows a line (an index into `lines()`)
  at the top, and `topLine()`/`bottomLine()` say which lines the view shows, also while it
  follows the end. The view stays on that line while text arrives and across resizes.
  `scrollTo()`/`scrollBy()` stay, documented as row-based (for paging by the window's height).

## 0.0.1-beta3 (2026-10-30)

**A program starts at the size of its pane**

- vim, htop, less and other full-screen programs in a `Terminal` pane no longer stay at 80x24
  (or whatever the default was) when the pane is larger: the program now starts in a pty that
  already has its pane's size. A `Terminal` starts its program when it has been sized (the
  render of its parent that shows it), not at mount; until then `pty()` is null, and a
  `Terminal` that no render shows has not started its program yet.
- New hook `Component::sized()`: runs once when the component has its real size, right before
  its first render. The rule for component authors: `mount()` sets up props and state, work that
  depends on the rectangle starts in `sized()`. A child component that no render shows is no
  longer rendered at a guessed size; it is sized and rendered by the render that shows it.
- A pane resized while its program is still starting (before the program's first output) no
  longer leaves the program at the old size: once it writes, it gets a second SIGWINCH, for
  programs that install their handler late (vim).

**Quitting ends every program in the panes, also what a shell runs in the foreground**

- Quitting while a shell in a pane ran `sleep`, `vim`, a build or a nested multiplexer in the
  foreground could leave the shell and that program running as orphans holding the pane's
  terminal open, and `fiber\run()` never returned. Ending a `Pty` now ends every process it
  started: the program leads its own session on the pty, and hanging up signals every process
  group of that session (SIGHUP and SIGCONT, then SIGKILL after the grace period), as closing a
  terminal window does. A command given as a string and one given as an argument vector behave
  the same.
- New `Pty::hangUp(float $grace = 0.5)`: the usual way to end a Pty (the `Terminal` component
  uses it). `Pty::kill()` now signals the whole session too, not only the program's own group.
- Ptys still running when the PHP process exits (`exit()`, an uncaught exception, a signal such
  as the SIGHUP a program in a pane gets when its outer pane closes) are hung up on the way out,
  so a split-shells nested in a pane of another ends its own shells with it.

**Focus survives a re-render that swaps layouts**

- A component whose `render()` returns a `Stack` with a dialog and then the plain layout again
  no longer loses the keyboard when that layout contains a nested layout: the windows moved into
  other nested surfaces, the focus was left pointing at a window no longer shown, and typing did
  nothing afterwards. The focus now belongs to the surface: the surface focused last has it,
  wherever it is shown and however the windows are re-arranged.
- A `focus()` made during `render()` (or anywhere before a re-arrangement) wins over what the
  re-arrangement does.
- When the focused window is no longer shown (a dialog closes), the focus goes back to the
  window focused before it, if it is still there, else to the first window. (It used to go to
  the topmost window.)
- Focus events: surfaces that leave the focus path and those that join it each get one; a
  surface that only moved to another host gets none. A host that took the focus with
  `takeFocus()` keeps it when a new window is hosted, until a window is focused.

**Keys go to the new focus at once**

- Keys typed right after a key that changed the focus (opening a dialog with
  `$dialog->focus(); $this->requestRender();`) no longer go to the old focus until the next
  render: focus changes take effect immediately for input, and only the drawing waits for the
  frame. A component focused before a render shows it gets its keys and pastes through its
  parent until then.

**Small things**

- A window gets a border and title right where it is added to a layout:
  `$stack->add($dialog, 40, 10, title: 'Save?')`, also `Split::add($item, $size, title: ...)`,
  or `border: true` for a frame without a title. No detour through `host()` for a dialog's frame.
- `Log::setLines()` is documented as the way to filter (also in the Log class docs), and keeps
  the reader's place: a view scrolled back stays on the line at its top when it is among the new
  lines, and follows the end when it is not. A scrolled-back `Log` also stays on the same line
  (no longer the same row number) when a resize or `setWordWrap()` lays it out again.

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
