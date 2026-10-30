# Changelog

## 0.0.2-beta1 (2026-10-30)

The components layer, first cut: screens are described as element trees, components render
again by themselves, a stylesheet gives everything its look, keys are data, and the first
widgets (`Editor`, `Select`, `Table`, `Dialog`) are built on it. Everything from 0.0.1 works
as before.

**Element trees: describe the screen, the library lays it out**

- `render()` may return a tree of elements instead of a layout: `Column` and `Row` (windows on
  top of each other or side by side, sized as `Split` sizes them), `Layers` (over each other,
  as `Stack`), `Box` (a border and a title around one element), `Text` (styled, wrapped,
  aligned; `Span`s for runs in other styles) and `Help`; every component is an element too, so
  widgets and your own components go where they show. There is no more building `Split`s of
  `->surface`s by hand for an ordinary screen.
- Elements are made anew on every render; the library matches them with the previous render's
  by position or by `key:`, so the focus, the scroll positions and the child components behind
  them stay. A tree rebuilt on every render keeps the cursor in the field you are typing in.
- A text is as high as its wrapped lines, a one-line field one row: without a size an element
  takes what its content needs (`'auto'`), components and containers share the rest. When a
  component needs another size (an editor grew a line), its parent lays the tree out again.
- Everything is painted through `write()`, as before: a replay of the bytes the terminal device
  sent gives the same screen, styles included.
- Text and labels no longer take the keys: a `Column` of a label and a field gives them to the
  field (`Surface::setFocusable()`; `focusNext()` and `focusToward()` skip what does not take
  the focus).
- A component placed with `new` in the tree (`new Counter(label: $x)`) is matched with the one
  already there, which keeps its state and takes the new props (its public properties);
  `propsChanged()` tells it.

**Reactive state: no more `requestRender()` after every change**

- `$this->state` is a `State`: write to it (`$this->state->count++`, or typed properties in a
  class of your own) and the component renders again in the next frame. Many writes in a
  moment are one render, parents before children. A state object given to several components
  renders all of them.
- A component also renders by itself after one of its handlers took an event, after a bound
  key, after each `sleep()` of its coroutines and the end of a `go()` that waited, after a
  callback or listener it gave to a child ran (`onSubmit`, `on('scroll', ...)`), after new
  props, and when its area changed. `requestRender()` remains for changes it cannot see.

**A stylesheet**

- `Stylesheet` with rules by element type, `#id`, `.class`, the states `:focused`,
  `:disabled`, `:hover`, and widget parts (`Select::row:current`); properties `color`,
  `background`, the text attributes, `border` (kind of line and colour), `padding`, `align`,
  `width`, `height`; variables (`$accent`); rules, then classes, then inline `style:`.
  Colours and attributes inherit down the tree. Set it on the root with `setStylesheet()`;
  `Stylesheet::default()` is a theme in the 16 terminal colours that reads on dark and light
  terminals alike. A widget that draws itself gets its colours with `$this->style()`.
- Borders come in kinds of line: `Viewport::setBorder('round')` (also `double`, `heavy`,
  `ascii`; `true` keeps the kind set before).
- A focused field looks focused: the default theme gives the focused `Editor` a panel
  background and a `Box` around the focused component an accent border; a `Box` in a pane
  without the focus is no longer drawn as focused.

**Key bindings as data**

- `bindings()` maps keys to public methods of the component (`['ctrl+s' => 'save']`); the
  application changes them per instance or per class with `bind()`
  (`$app->bind(['ctrl+enter' => 'submit'], Editor::class)`), and a `Help` element lists the
  keys in force for the focused component, following the focus.
- The focused component's bindings come first, then its parents': a text field types the
  letters an application binds, the application's Ctrl-Q works wherever the focus is (also in
  a `Terminal`), and a method returning false passes the key on.

**Widgets**

- `Editor`: one line or many, word wrap, selection with Shift and the arrows or the mouse,
  undo/redo, a kill ring (Ctrl-K, Ctrl-Y, Alt-Y), copy and cut to the terminal's clipboard,
  bracketed paste as one step; `maxLines: 1` is a form field that replaces `TextInput`.
- `Table`: columns with widths (cells, %, fr, auto), alignment, cells cut with "…", sorting by
  a click on a title, sideways scrolling of tables wider than the window.
- `Select`: a list or table to pick from, filtering as you type, marking several with Space,
  `onSelect`, `onActivate`, `onChange`; the selection stays on the same items through
  `setItems()`, sorting and filtering.
- `Dialog` and `Confirm`: `$ok = fiber\await($this->dialog(new Confirm('Delete?')))` in a
  coroutine; modal over the component (its own keys and the mouse outside go to the dialog),
  Escape cancels, the focus goes back where it was. A dialog that ends unanswered (the program
  quits) answers its cancel value, so the coroutine awaiting it does not hang.

**Also**

- `Ansi::clearHome()`: erase the screen and start at the top-left corner.
- Starting a program on a `fiber-interop/api` older than 0.3.1 throws at once, saying which
  version is needed and the `composer require` that fixes it, instead of failing with an
  undefined function inside a coroutine.
- The callbacks of the built-ins (`TextInput`'s `onSubmit`, `ListView`'s `onSelect`, a
  `Terminal`'s `onExit`) render the component that gave them.
- `examples/form.php`: a form built from elements. The README's tour has a screen built from
  elements (tested as written). New: `docs/widgets.md`; `docs/components.md` describes the
  element tree, props, state, styles, bindings and dialogs.

## 0.0.1-beta5 (2026-10-30)

**The focus stays on a dialog however `render()` rebuilds the layouts around it**

- A `render()` that builds new layout objects every time (a new `Stack` holding a new `Split`
  with a new plain `Surface`) and adds the dialog's surface directly
  (`->add($this->dialog->surface, 40, 5, title: 'Name')`) no longer loses the dialog: keys
  typed after the frame that shows it used to stop at the parent component while
  `$dialog->hasFocus()` said true. The cause: a render disposed every child it had not asked
  for with `child()`, also one it showed, so the dialog was disposed by the very render that
  showed it. Now a render keeps every child whose surface its layout shows, however it got
  there.
- `Component::hasFocus()` reads the path the keys take (the surfaces' focus, and the child not
  shown yet that a component passes keys to), so it is true exactly when the next key reaches
  the component; it is false for a disposed component.
- A window that was in a nested layout no longer stays hosted, and focused, in a part of the
  tree that is no longer shown when a render drops that layout together with layouts nested in
  it (`Surface::hasFocus()` said true for it).

**`dispose()` waits for the end again**

- When `Component::dispose()` returns, the component's coroutines have returned (or the
  `$timeout` passed) and `unmount()` has run: a dialog closed in a key handler has let go of
  what it held, a `Terminal` closed this way has ended its program. Called from one of the
  component's own coroutines, or a descendant's (quitting from a child's `run()`, a
  `Terminal`'s `$onExit`), it cannot wait for that coroutine: it returns at once as in beta4,
  and `unmount()` follows when the coroutine returns. That is now found out with
  `fiber\current()`, also when the coroutine disposes before it first waited.
- A render that drops a child, a failing child, and detaching the terminal still do not wait.
- A child that was disposed is forgotten at once: `child($key)` returns null for it (it used to
  return the dead object until the next render), and `child($key, $create)` makes a new one, so
  a dialog that closed itself can be opened again.
- Requires `fiber-interop/api` ^0.3.1 (for `fiber\current()` and the public
  `fiber\Deferred::resolve()`), and, for the tests, `fiber-interop/phasync` ^0.4.1, with which
  phasync 2.0.7 installs (Composer had silently kept phasync 2.0.4).
- `echo` capture in `Surface::run()` works with ext-phasync 2.0.7, whose `sapi()` takes a
  `write()` method; with it, a coroutine's output went nowhere and `run()` failed with a
  `ValueError`. An older ext-phasync now counts as absent (`Surface::canCaptureOutput()` false).

**Showing another component's state: component events**

- A component whose `render()` shows another's state (a pane title with the lines a `Log`
  shows) can now be told when it changes: `Component::on($event, $listener)` listens to a
  component's events, `emit()` sends them. `Log` emits `scroll` when the lines in view
  (`topLine()`, `bottomLine()`) or `isFollowing()` change, by the wheel, a scroll call, text
  arriving while it follows, `setLines()` or a resize; `ListView` emits `scroll` when the
  items in view change, and tells which with the new `topIndex()` and `bottomIndex()`. The
  pattern: `$log->on('scroll', fn() => $this->requestRender())`.

**Small things, from use**

- `Log::setLines()` with re-styled lines (every line's escape sequences changed, the text the
  same) or a line edited in place no longer jumps to the end when the view is scrolled back: it
  stays on the same text when that is still there, else on the same line number.
- A component made with `child()` can be given to a `Box`, or to any container that takes a
  component: `new Box($this->child('log', fn() => new Log()), 'output')` used to fail with
  "already started". The container shows it and passes keys to it; it stays yours (it keeps its
  content when the box goes).
- `FakeTerminal::type()` says it is for text, and refuses control characters other than
  `"\n"`, `"\r"` and `"\t"`, pointing to `press()` for arrows and function keys and `feed()`
  for escape sequences: `type("\e[A")` used to arrive as an Escape key and three letters.
- A window scrolled back now shows the new text when its scrollback is rewritten under the same
  top row (it kept the old text; seen with `Log::setLines()` while scrolled back).
- A window with a border that is one cell wide shows its left border and no longer writes a
  cell past its edge (a PHP warning in the compositor).
- The acceptance tests (Claude Code, vim/htop/less in panes, split-shells nested) are green.

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

**`dispose()` returns at once, and the component ends after its coroutines**

- `Component::dispose()` no longer waits for the component's coroutines: it tells them to end
  (`stopped()`, `sleep()` returning false) and returns. `unmount()` runs when the last of them
  has returned and the children have ended, a parent after its children, and `$timeout`
  seconds after the dispose at the latest. `fiber\run()` still returns only when everything has
  ended. Code that disposes its own component from one of its coroutines (quitting from a
  child's `run()`, a `Terminal`'s `$onExit` closing its pane) goes on with `isMounted()` false,
  and `unmount()` follows once it has returned: return soon, and do not wait there for the
  component's end.
- A key handler that closes a dialog, or a render that drops a child, no longer stalls (keys,
  frames) while that component's coroutines wind down; it used to block for as long as they
  took, up to the timeout.
- The library no longer looks at the call stack (`debug_backtrace()`) to find out which
  coroutine disposes a component.

**Small things**

- `child($key)` without a factory returns the child of that key, or null when there is none
  (it used to throw): `$this->child('dialog')?->dispose()` in a key handler. A render that asks
  for a key this way keeps that child, as one with a factory does.
- `FakeTerminal::feed()` is documented as the way to send raw bytes as a terminal sends them
  (escape sequences, SGR mouse, bracketed pastes, focus reports), next to `type()` for text and
  `press()` for keys: one call is one read followed by a pause.

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
