# Changelog

## 0.0.3-beta7 (2026-11-01)

A Tab inside a `ScrollView` of 200 rows costs what it costs at 2, a tab switch in a full
layout at 120x40 costs half, a `Table` of markup cells costs about what a plain one does, a
focused `Terminal` keeps the keys its shell needs, and a headless run can be given a theme.
Everything from 0.0.3-beta6 works as before, except where an entry says what changed. All
times are `press()` + `settle()` (or `text()`) around `hrtime()` under `Tui::headless()`, a key
every 50 ms, on the machine the report's numbers were taken on.

**A Tab in a ScrollView**

- A Tab in a `ScrollView` of rows (a `Text` and two `Button`s each, `key:`, `gap-1`,
  `onFocus:` writing a `State` that a footer `Text` closure reads) cost O(rows): 2 rows 4.6 ms,
  50 rows 22.6 ms, 200 rows 43 ms. Now 3.6 ms at 2 rows and 6.3 ms at 200, with or without
  `onFocus:`, and the closure program is not rendered at all for a Tab
  (`tools/monitor-bench.php scroll 200` is that program). What changed:
  - The `ScrollView` rendered its whole content again for every key that passed it, to follow
    the focus. It now lays out again only the elements whose look the focus changed (the two
    rows the focus moved between) and scrolls the view to the focus without a repaint.
  - Its layout of the content read the buttons' focus as a render does, so it rendered again
    whenever one gained or lost it. It no longer does.
  - A `Text` closure that grows a column ("sel 9" to "sel 10") re-ran the program's `render()`
    to make room. The layout around it is now arranged again without a render.
  - `Viewport::scrollTo()` and `pan()` to where the window already is repaint nothing.

**Switching tabs**

- A tab switch in a full layout (`Tabs` with `Row([Markdown, Box(Select)])` and
  `Box(Terminal)`, inside `Column([Row([Tabs, Box(tools)]), Box(Editor), Text(closure)])`)
  cost 26-28 ms at 120x40 and 18.5-19.5 ms at 80x24; now 14.2 ms and 11 ms
  (`tools/monitor-bench.php tabs 120 40`). A `Markdown` tab costs what a `Text` tab does: it is
  laid out once and never drawn again by the switches (its size does not change). What changed:
  - A `Row` or `Column` that only arranges (no border, background, title, shadow, keys,
    `onFocus:`/`onBlur:`/`onMouse:`, state or focus and hover look) no longer has a window of
    its own: its items are windows of the surface around it, so nesting no longer adds a level
    at which everything is composited again. It is still listed in `Mouse::over()`, and gets
    its window back (focus and text intact) when its look needs one.
  - The components of a hidden tab were told they had the focus when the tab hid and drew
    themselves for nobody, and every component of a pane shown again drew itself again for its
    area. A hidden tab's components now hear nothing of the focus until it is shown, and one
    whose area is what it drew for does not draw again.
  - A pane replaced by another was blanked before the new one painted over it; now only what
    nothing covers is blanked, in one write.
  - The escape-sequence parser and the window diff do less per sequence (an SGR that starts
    with a reset, a run of text into a row, the gaps between changed cells).

**Small things**

- A `Table` with six columns of markup cells cost 18 ms per key against 8 ms for two plain
  columns at 120x40. A key that moves the current row now writes only the two rows it changes,
  and each cell is kept as drawn (its markup turned into escape sequences once per text):
  3.0 ms against 1.8 ms. Scrolling, resizing and new columns or styles draw the whole table as
  before. `Table::rowsWritten()` (internal) says how many rows the last render wrote.
- A program that passes no theme to `Tui::run()` or `Tui::inline()` can now be given one when
  started: `TUI_THEME=light` in the environment or `--theme=mono` on the command line (the
  argument wins; a theme the program passes wins over both). So a cookbook screen run under
  `TUI_HEADLESS` shows the theme you ask for.
- A focused `Terminal` no longer loses Ctrl-D (or Ctrl-R, Escape, Tab, the arrows, any key) to
  the plain bindings of the components around it, their elements' `onKey:` or `anywhere`
  bindings: its program gets them. Bindings marked `first` or `always` still come before it, and
  so does the Ctrl-Q of `Tui::run()`. **Changed:** an application key that should work from
  inside a shell must now be marked `'first' => true` (`examples/tabs.php` marks its Ctrl-N so);
  docs/components.md lists the keys to avoid binding around a Terminal. A widget of your own
  can say the same with `protected function takesEveryKey(): bool { return true; }`.
- A `ProgressBar` whose value moves writes only the cells that changed and its label, not the
  whole bar (a bar that moves a cell writes about 60 bytes instead of the 300 of a whole bar
  of 90 cells). The frame as a whole (`$s->p[$i]` read in the program's render, so
  the render runs, then each bar, its row and the screen composited) went from 40 to 37 ms at a
  frame every 50 ms (19 ms back to back); with closure values (`new ProgressBar(fn() =>
  $s->p[$i])`) the program's render does not run and the frame is 31 ms (15 ms back to back).
  The rows of that monitor have `onKey:` and so are focus stops with windows of their own: that
  level of compositing stays.

## 0.0.3-beta6 (2026-11-01)

A key costs the same at 200 rows as at 2, switching to a Markdown tab costs what any tab does,
the selected card of a long lane comes into view, and the Help line is the same whoever made
the focused component. Everything from 0.0.3-beta5 works as before, except where an entry says
what changed.

**The cost of a key**

- Tab (and any focus change) in a screen of many rows no longer grows with the rows. Measured
  as a program measures it, `press('tab')` and `settle()` under `Tui::headless()` at 160x50,
  rows of a `Text` and two `Button`s with `onFocus:`: 2 rows 1.2 ms, 200 rows 1.8 ms (11.8 ms
  in 0.0.3-beta5 on the same machine). The 50-row monitor of `tools/monitor-bench.php keys`,
  bars moving at 10 Hz: 2.5 ms median. What changed:
  - The focus coming to or leaving an element no longer renders the component whose tree holds
    it: a closure program's `render()` is not called for a Tab. The elements the focus left and
    entered are laid out again from the last tree, and only when their look changes with the
    focus (`focus:` classes, `:focused` rules); `onFocus:`/`onBlur:` run as before, and a
    `Help` line follows the focus the same way. A closure program whose `onFocus:` writes state
    it does not read is no longer rendered at all for a Tab (beta5: once).
  - Equal styles are one object, so an element whose state changed but whose look did not
    keeps everything laid out for it.
  - The Tab walk looks at the windows from the focused one on instead of ordering the whole
    screen; a focus request settles only the windows above it; the children that are not
    shown are found once.
- `tools/monitor-bench.php keys` measures `press()` and `settle()`, as a program does, so its
  number and the one a program sees agree.

**Switching tabs**

- Switching to a tab holding a kept `Markdown` (or any content that fills the pane) from a tab
  of one row cost 18 ms more than to a `Text` tab at 120x40: the tabs laid the new content out
  in the old tab's one row first, the `Markdown` was resized to one row, drawn, resized back
  and drawn again, and switching away shrank it the same way (losing its rows). A component
  whose size changes with its render is now laid out once, at the size its parent gives it
  next. A window shown again writes only the cells that differ from what its host shows there.
- A component the program gave as a tab's content in a render and that the tab then started
  (the first render's own `new Tree(...)`) was stopped by the next `setTabs()` with a new
  instance, when the program re-rendered while its tab was hidden: it is the tab's, and stays.

**Selection, focus and Help**

- A `ScrollView` brings the element that becomes selected into view (`selected: $s->sel === $i`
  on the 200 cards of a lane moved by arrow keys in `keys:`), scrolling as little as shows it,
  as it does for the focus: select card 50 and it shows, select 0 and the view is at the top,
  one step at the edge scrolls one row. The wheel may scroll away until another is selected.
- Every element takes `focused:` (and a component `->with(focused: ...)`): the render where it
  becomes true gives it the focus, so a program without classes moves the keys with its state
  (`new Text($card, onKey: [...], focused: $s->sel === $i)`). A render that keeps it true does
  not take the focus back after Tab moved it.
- With a fresh `new Tree(...)` in a tab's content (an owner calling `setTabs()` in every render)
  focused, the `Help` line showed the tab bar's keys ("left previous tab right next tab ..."),
  and Left in the tree at a closed node switched the tab; with the tree kept by `child()` it
  did not. A key a component does not take now goes on through the components it is shown in
  (the tabs and their pane), then its owner, whoever made it, so both shapes have the same keys
  and the same `Help` line. The bar's own keys of `Tabs` (Left/Right, Home/End, Tab, Enter,
  Delete) are in force only on the bar; in the content Escape (to the bar), Ctrl-Tab and
  Alt-number are.

**Small**

- Alt-1..9 and Ctrl-Tab switch the tabs from anywhere on the screen when there is one `Tabs`
  on it (a sidebar has the focus); with two, each only while the focus is inside it. They are
  bindings marked `'anywhere' => true`, which any component may use for a key that should work
  wherever the focus is (not while a modal dialog is open).
- docs/components.md says next to `onKey:` that an element's keys, `'quit'` among them, work
  only while the focus is in that element, and where to bind a key that must work everywhere.
- `Tui::freeze($component)` freezes the rows a component shows above the inline region, also
  the instance a closure render made anew (`$bar->device()->freeze($bar->surface)` still works).

## 0.0.3-beta5 (2026-11-01)

A program never hangs without saying why, `'quit'` works on an element, a key costs the rows it
changes, and the coroutines of a program stop with it. Everything from 0.0.3-beta4 works as
before, except where an entry says what changed.

**Hangs**

- On fiber-interop/api 0.3.1 and fiber-interop/phasync 0.4.1 (the versions Composer installs
  today), test code that throws after `Tui::headless()` in your `fiber\run()` closure hung with
  no output. Those versions cannot tell the library that the closure failed, so the program
  still waits, but the library now says so: one line on stderr when the program starts, naming
  the installed versions and the ones needed (`fiber-interop/api >= 0.3.2`,
  `fiber-interop/phasync >= 0.4.2`). What the library owns ends the program on every version:
  `Tui::run()` and `Tui::inline()` (which give no warning), the closure given as the root, a
  `Tui::go()` coroutine, a handler. docs/terminal.md and the README say what to do in tests
  (`$terminal->run(...)`, or the newer versions). `composer.json` cannot exclude the old
  versions until the fixed ones are tagged.
- `onKey: ['q' => ['quit', 'quit']]` (or `['q' => 'quit']`) on an element no longer hangs the
  program when `q` is pressed: it quits, exactly as the same binding in `Tui::run(keys:)`.
  An element's bindings take every action a component's do: a closure, `'quit'`, or the name of
  a public method of the component whose render made the element (before, any name was a
  `TypeError` that stopped the terminal's input).
- A failure that nothing in the program contains and that escapes the terminal's own work (a
  listener of a bare root surface, the dispatch of a key) ends the program with that exception,
  the terminal given back, instead of leaving the screen standing with nobody reading the keys.

**Coroutines stop with the program**

- `Tui::sleep($seconds)`: a sleep that throws `Quit` once the program has ended. A coroutine
  started with `Tui::go()` that waits with it stops when you press Ctrl-Q (or call
  `Tui::quit()`): `fiber\run()` returns with the screen, with no `$running` flag of yours (one
  program used to wait 100 s after Ctrl-Q for its loops). Catch `Quit` to clean up first.
  `fiber\sleep()` cannot be cut short (fiber-interop/api has no cancellation yet), and plain
  `fiber\go()` coroutines are not the program's: end those with a flag, as the docs say. The
  README, the cookbook, `examples/dashboard.php` and `examples/working.php` use `Tui::sleep()`.
- Coroutines of `Tui::go()` started before a runner that fails to start (an unknown theme, a
  bad `--headless=`) end with it, instead of keeping `fiber\run()` waiting.
- A failed `Tui::go()` coroutine that ended a headless program no longer comes out of a later
  `Tui::headless()` after that program has been garbage collected.

**The cost of a key**

- A Tab between the rows of a program without classes (50 `Row([Text, ProgressBar, Button,
  Button])` with `onKey:` and `onFocus:`) measures, arranges and paints the two rows the focus
  moves between and nothing else, and reaches the screen in about 10 ms (median, bars moving)
  instead of 90-170 ms. Asking the focus of 150 components no longer walks the tree for each,
  layouts keep the measures of rows whose components did not change size, and only rows with
  a component that did are arranged again. `php tools/monitor-bench.php keys` measures it.
- Changed: a render that read some properties of a `State` renders again when one of those is
  written, not when any property of it is (`onFocus: fn() => $s->current = $i` no longer
  renders a closure that never reads `current`; it cost a second render per Tab). The
  component holding a state as its `$state` still renders on every write, and `unset($s->x)`
  now renders those that read `x`.

**Small**

- `hasFocus()` of a new instance made in a render (`new Editor()` in a closure) that was matched
  by its place to the running instance answers for the running one (before: always false), and
  a render that asks `hasFocus()` renders again when the answer changes. `running()` gives the
  instance that runs for a new one.
- `Help(for: $field)` with such a new instance lists the running one's keys and its parents'.
- docs/components.md and docs/widgets.md say when a `ScrollView` is needed: a `Column` of more
  rows than its box cuts the rest, and the buttons cut off are out of the Tab walk. In a
  `ScrollView`, whatever takes the focus is scrolled into view: by Tab, Shift-Tab, `focus()` and
  after the user scrolled away from it (tests for buttons, cards with `onKey:` and rows).

## 0.0.3-beta4 (2026-11-01)

Two focus defects fixed at their cause, and the last rough edges of the one-file program.
Everything from 0.0.3-beta3 works as before, except where an entry says what changed.

**Focus**

- Tab is no longer trapped on the table after a shell tab was shown and left, in an owner
  whose `render()` calls `setTabs()` every time with a fresh `new Tree(...)` inside a fresh
  `Row` (and a `Terminal` made with `child()`, `keep()` or kept as a prop): Tab walks table →
  tree → tab bar → table again, Shift-Tab back. The cause: `Tabs` kept the components of a tab
  by object, so the fresh `Tree` of every render became a new running tree, and the one with
  the focus was stopped. Changed: **each tab's content is shown by a pane of its own that lays
  it out like any render**, so the components in a tab are matched by their place (or `key:`):
  a `new Tree()` made by every render is one tree all along, which takes the new props and
  keeps its expansion, selection and focus. Components of a tab never shown yet start when the
  tab is first shown (before, those in an element tree started with the tabs).
- Tab no longer skips the first `Button` of every row once the `Row` has `onKey:`. An element
  with `onKey:` around components that take keys is **not a stop of the Tab walk** any more:
  Tab visits its buttons exactly as without `onKey:`, its keys apply while one of them has the
  focus (`p` pauses the row whose button is focused), and its `onFocus:` runs when the focus
  enters the row from outside, not for every button in it. An element with `onKey:` and nothing
  focusable inside (a card of text) is a stop, as before.
- Alt-1 to Alt-9 switch tabs wherever the focus is inside a `Tabs`: also from the list of a
  dropdown `Select` or the completions of an `Editor` in the content (before, the open list
  swallowed them). The rule is in docs/widgets.md.
- After a dialog closes, the focus is back where it was when it opened, also when a render
  made the field anew meanwhile (the nearest window of that place that is still shown);
  `hasFocus()` of a component is true while a list a component inside it opened has the focus.
- `Component::focusOrder($surface)`: the stops of the Tab walk below a surface, in Tab order,
  for container widgets that move the focus themselves.

**One file, no class**

- `Tui::quit()` ends the running program from any handler or coroutine, as Ctrl-Q does; a key
  bound to `'quit'` does the same (`Tui::run($screen, keys: ['q' => ['quit', 'quit']])`, also
  in `bindings()` of a component that has no `quit()` method of its own).
- `State`: an array property changed in place is a change: `$s->progress[$i] = 0.5`,
  `$s->items[] = $item`, `unset($s->rows[$id])` render the screen as an assignment does. Before,
  PHP said "Indirect modification" and nothing rendered.
- `println()` takes markup like every other string (`'<fg=green>✔</> built'`), a `Text` with
  spans, and `Text::raw($line)` for a line printed exactly as written. Changed: a string with
  markup tags in it (`<b>`) is no longer printed with the tags; pass `Text::raw()` for that.
- `Select` takes the item it starts at: `new Select($items, value: 'm')`, or the marked items
  with `multiple: true` (`value: ['a', 'c']`), like `Checkbox`'s `checked:`.
- An exception nobody catches in a program that used the terminal (the run closure, a
  coroutine of `Tui::go()`, a handler) is printed after the terminal is restored as class,
  message and file:line on stderr, with the stack trace only with `TUI_TRACE=1`; exit status
  255. Before, PHP's raw "Fatal error: Uncaught" with the whole trace came over the restored
  screen. A program with its own `set_exception_handler()` keeps it.
- `Editor` with `complete:`: Tab moves the focus on when the closure returns no candidates
  (checked, with a test; it already did in this tree).

**Testing**

- docs/terminal.md says it first: call `settle()` after input and before reading the screen
  (`text()` right after `press()` shows what the handler changed, not what a coroutine it
  started changes later).
- Test code that fails after `Tui::headless()` ends `fiber\run()` with its exception at once,
  also with a dialog open, a `Terminal` running a program that ignores SIGHUP, or in inline
  mode (tests for each). Test code in coroutines: start them with `Tui::go()`; a failing plain
  `fiber\go()` is kept by `fiber\run()` until the program ends (a gap of fiber-interop/api,
  documented).
- A `Tui::go()` coroutine that failed and ended a headless program no longer makes the next
  `Tui::headless()` (or `Tui::run()`) of the process throw that old exception as it starts: the
  failure belongs to the program it ended.

## 0.0.3-beta3 (2026-11-01)

A program without a class takes keys, the mouse and dialogs; components a program keeps stay
themselves wherever they move; Tab no longer gets stuck after a hidden shell tab. Everything
from 0.0.3-beta2 works as before, except where an entry says what changed.

**A closure program receives input**

- `Tui::run($screen, keys: [...])`, also `Tui::inline()` and `Tui::headless()`: keys of the
  program, in the syntax of `bindings()`, with closures as actions (`'ctrl+n' => [fn() =>
  add($s), 'new card']`, `'always' => true` for one that works under a dialog). They run before
  the focused component's keys, and `Help` lists the described ones. Before, a closure program
  had only Ctrl-Q.
- Every element takes `onKey:` (a bindings array, or `fn(Key $key, Element $element): bool`)
  and `onMouse:` (`fn(Mouse $mouse, Element $element)`). An element with `onKey:` takes the
  focus: Tab and Shift-Tab stop at it, a click focuses it, `focus:` classes show it, `Help`
  lists its keys. `onMouse:` gets presses, drags and releases in the element's coordinates; the
  element that took the press gets the drag and the release, and `$mouse->over()` lists the
  elements under the pointer, so a card dragged onto a column knows the column.
- `Dialog::open($dialog)` and `Confirm::ask($title, $text)` open a dialog from any coroutine of
  the program, a key handler included, and return the awaitable of the answer; one at a time
  per program. They throw a `LogicException` when no program runs.
- Handlers may wait: a key handler that awaits a dialog (or sleeps, or reads a file) no longer
  holds the input up; the keys typed into the dialog reach it.
- An action in `bindings()` and `bind()` may be a closure instead of a method name; it always
  takes the key. `bind($keys, first: true)` marks them all `first`.
- `Tui::go($task)`: a coroutine of the program whose exception ends the program at once, with
  the terminal restored and the message readable. A coroutine started with plain `fiber\go()`
  still fails only when `fiber\run()` ends (fiber-interop/api gives the library no way to hear
  of it); a component's `$this->go()` keeps showing the error in the component's rectangle.
  The README, the cookbook, `examples/dashboard.php` and `examples/working.php` use it.
- docs/components.md has a "Without a class" section, and cookbook screen 3 (the form in a
  dialog) is a closure now: no recipe has a class.
- Changed, for tests: `FakeTerminal::press()`, `type()`, `paste()` and the mouse calls return
  when the handlers of the input have run (one second at most), or once a handler waits for a
  dialog it opened. Before, they returned before a handler that waited had finished.

**Kept components keep their identity**

- A component instance a closure program keeps and places on every render (`$bars[$job] ??=
  new ProgressBar(...)`) stays that instance, with its state, surface and value, wherever the
  next render places it. Before, dropping the first of two jobs made the second job's bar show
  the total's value, because instances were matched by position: the bar the program kept was
  replaced by the one at its old place.
- Changed: an instance that left the tree is stopped, and placing it again throws a
  `LogicException` that says to make a new instance (or keep it in the tree with the class
  `hidden`).
- `$terminal->component()` gives the root component a device shows, and `children()` lists
  its children by `key:`, so a test or a program reaches the components of a closure program
  (`$t->component()->children()['progress']`). `Component::of($surface)` gives a surface's
  component.

**Focus**

- Tab works again after a hidden tab with a `Terminal` was shown and left: a component the
  current render does not show (a hidden tab's shell, a `keep()` child, a component given as a
  prop) is never in the Tab order and does not get the keys, whoever owns it, until it is shown
  again or focused with `focus()`. Before, pressing Alt-1 right after Alt-2 could leave the
  keys with the hidden shell, and Tab did nothing.

**Small things**

- Headless without editing: a program run by `Tui::run()` or `Tui::inline()` started with
  `--headless=COLSxROWS` (or `TUI_HEADLESS=COLSxROWS`) runs on an in-memory terminal for a
  second (`@SECONDS` for another time), prints the screen as text and exits:
  `php examples/dashboard.php --headless=100x30`. A wrong size is an error saying what was
  expected.
- An Editor's completion list and a Select's dropdown, when the field is the only content of a
  `Box`, open just outside the box instead of over its bottom border.
- Deleting the current row of a `Table` keeps the place: the next row becomes current (the last
  one when the table got shorter) instead of the first row.
- Changed: a `Dialog` is as wide as its content (a message wraps at 60 columns), at most 80% of
  the screen; before, a dialog with a field spanned the whole width.
- A fixed `width:` or `height:` on an element in a `Row` or `Column` is its size also when the
  parent measures it (`new Box($x, height: 8)` in a `Row` was as high as its content).
- `Tabs` documents that its tabs are given once: a `new Tabs([...])` made again by a later
  render updates `position`, `hints` and `closable`, not the tabs; keep the instance and change
  them with `setTabs()`.
- Cookbook screen 9 says "1 sample", not "1 samples".

## 0.0.3-beta2 (2026-10-31)

A program with no styling looks like a modern tool in three themes, the cookbook has ten screens
to copy, and the README opens with a sixty-line dashboard. Everything from 0.0.3-beta1 works as
before, except where an entry says what changed.

**Good by default: themes**

- Three themes, `dark`, `light` and `mono`, each a stylesheet (`Stylesheet::theme('light')`).
  The default look: rounded borders, one accent colour (sky), the focused window's border in it,
  dimmed titles elsewhere, `Select` rows in inverse, and dialogs with a shadow under and right of
  them. The theme colours are named classes too: `text-accent`, `border-muted`, `bg-panel`,
  `text-error`, `text-success`, `text-warning`.
- `Tui::run($root, theme: 'light')`, also `Tui::inline()` and `Tui::headless()`, picks one.
  Without it the terminal decides: at attach the device asks for its background (OSC 11), and
  a program on a light terminal is drawn in `light`, on a dark one or one that does not answer
  in `dark`. Start-up never waits for the answer. `$device->background()` says what the
  terminal answered; `new FakeTerminal(80, 24, background: 'light')` (or `null`, no answer)
  tests it. `$component->setTheme('mono')` and `theme()` change and read it while running.
- `mono` emits no colour at all, for classes, markup and widgets alike, and marks focus,
  selection and errors with bold, dim and underline.
- A program's own stylesheet built with `Stylesheet::default()->with([...])` keeps its rules on
  every theme: on `light` or `mono` they are applied over that theme's, so a program's rule
  always overrides the theme's.
- Changed: the default colours are Tailwind's (truecolour: accent `sky-400`, muted
  `slate-500`, ...) instead of the 16 terminal colours, drawn in the nearest colours a 256- or
  16-colour terminal has. A test that compared cells with `Color::parse('cyan')` from the old
  default theme sees other values.
- Constructor arguments for the common things, so a first version needs no stylesheet:
  `Column(gap:, padding:, align:)`, `Row(gap:, padding:, align:)`, `Box($child, $title,
  padding:)`, `Text($content, align:)`; `style:` given beside them wins.
- `new Button(fn() => $s->saving ? 'Saving…' : 'Save')`: a button label may be a closure, drawn
  again by itself when a `State` it read changes, like a `Text`. `$button->label = 'x'` still
  works.
- `Viewport::setShadow($color)` casts a window's shadow on the windows below it (the `shadow`
  property and the `shadow`/`shadow-none` classes); it goes with the window.

**Variants on elements**

- `selected:` and states on any element: `new Text($name, selected: $id === $s->current,
  class: 'selected:bg-accent')` marks the current row of a class-free screen. `state: 'error'`
  (or a list) puts an element in states of its own for the stylesheet (`'Text:error'`).
- `hover:` on any element: `new Box($x, 'jobs', class: 'hover:border-accent')` changes the
  box's border while the mouse is over it. Before, `hover:` only worked on components. A screen
  follows the mouse only when a `hover:` class or a `:hover` rule is in it.
- A border colour on a component (`->with(class: 'border-red-500')`) now colours the `Box` the
  component renders, when that box sets no colour of its own. Changed: a border colour or
  attribute alone (`border-red-500`, `'border' => 'dim'`) no longer draws a border on an element
  without one; write `border border-red-500`. Borders take `bold`, `dim` and `plain` for their
  lines (`'border' => 'single dim'`).
- Changed: the width variants are Tailwind's minimum widths: `sm:` from 60 columns, `md:` from
  80, `lg:` from 100 and the new `xl:` from 140, a wider one overriding a narrower one, so
  `hidden md:block` shows at every width from 80 on. Before, each named one range (`sm:` under
  60, `md:` 60 to 99): a program that used `sm:` for narrow screens writes the narrow layout
  without a variant and the wide one with `md:` (`flex-col md:flex-row`). The stylesheet states
  `:sm` ... `:xl` follow the same rule. `Stylesheet::widthClass($cols)` is replaced by
  `Stylesheet::breakpoints($cols)`, the list in force.
- `onFocus:` and `onBlur:` on elements and components (`new Column([...], onFocus: fn() =>
  $s->help = 'Your name')`): called when the focus comes into it or leaves it, after the frame
  that moved it, with no polling of `hasFocus()`.

**The cookbook and the dashboard**

- docs/cookbook.md: ten screens of under forty lines each, a progress runner, a log tail with a
  filter, a form with validation in a dialog, a file list with a preview, a tabbed shell host, a
  table with a search box, a chat pane with a growing editor, settings with checkboxes and a
  select, a live chart from a coroutine, and a Markdown viewer with a table of contents. Each is
  run by the tests at 60x20 and 160x50.
- `examples/dashboard.php`, under sixty lines and without a class: three jobs updated by
  coroutines, their events in a `Log`, release notes in `Markdown`, styled with utility classes
  only and stacked under 80 columns. `--inline` shows the three job boxes only, as a live region;
  `--theme=light|mono` picks a theme. The README opens with it. The graph demo that was
  `examples/dashboard.php` is now `examples/graphs.php`.
- Inline mode lays a component tree out at the height its content needs: a `Row` of boxes
  given to `Tui::inline()` is a region of the boxes' height. Before, an element tree root filled
  the terminal's height.
- A `Row` whose children wrap their text in their share of the width is as high as the wrapped
  text: before, it measured each child at the whole row's width, so a box with text that wraps
  only in its share lost its last lines.

**Small things from use**

- `Spinner` labels take markup (`new Spinner('<b>deploying</b> v2.4')`), like every other
  label (`Text`, `Box` titles, `Button`, `Checkbox`, `Table`, `Help`, `Log`, `Select`,
  `ProgressBar`, `Spinner`).
- An exception in a closure content (`new Text(fn() => ...)`) shows its class and message in
  the element's place (`✖ TypeError: Argument #1 must be of type int`), wrapped and cut with "…"
  when it does not fit, instead of a cut "Error:".
- `Log::append()`, `setLines()` and `setWordWrap()` before the log is shown keep what they were
  given; the lines are there when it is.
- A `ProgressBar` with a closure value is called when its `State` changes but draws only when
  the bar looks different, so rounding in the closure is not needed to keep it cheap.
- `Tabs`: a tab the user closed stays closed while `render()` goes on listing it in
  `setTabs()`; it comes back when `setTabs()` names it as `$current`, or after a list without it
  lists it again.
- `Select::setItems()` with items equal to the ones it has, and a `Table` given rows equal to
  its rows as props by every render, measure nothing again: a program that rebuilds its row
  array per render is not punished.
- `freeze()` of a component's surface after rows above it left the tree: the reported shapes
  (two jobs and eight, in a `Column` and in `Row`s, with the replayed byte stream) freeze the
  right rows at the right values and are regression tests now; the defect did not reproduce in
  this release.

## 0.0.3-beta1 (2026-10-31)

A program can be one file without a class, styled with Tailwind's utility classes, with markup
in its text. Everything from 0.0.2-beta5 works as before, except where an entry says what
changed.

**One file, no class**

- `Tui::run()`, `Tui::inline()` and `Tui::headless()` take an element tree, a component, or a
  closure returning either. A closure is a component: it is rendered when the screen is shown
  and again whenever a `State` it read changes, so this is a whole program:

  ```php
  $s = new State(['done' => 0]);
  fiber\run(function () use ($s) {
      fiber\go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { fiber\sleep(0.05); $s->done = $i; } });
      Tui::inline(fn() => new Column([new Text("Working… {$s->done}%"), new ProgressBar($s->done / 100)]));
  });
  ```

  Ctrl-Q quits it, as every program run by `Tui`. A closure that reads no state is called once
  and the component it returns is the root, as before (`Tui::run(fn() => new App())`).
  `examples/working.php` is this program; the README starts with it.
- Element content may be a closure: `new Text(fn() => "{$s->done}%")`, a `Box` title
  `fn() => "log ({$s->lines})"`, a `ProgressBar` value `fn() => $s->done / 100`. When a `State`
  the closure read changes, only that element is drawn again; the component around it does not
  render.
- A component renders again when any `State` its `render()` read changes, not only its own
  `$state`: a state shared between components no longer has to be each one's `$state`.
- `new State(['done' => 0])` makes a state with those properties.
- `Tui::headless(..., inline: true)` tests a program in inline mode.

**Utility classes**

- `class:` takes Tailwind's utility classes next to the program's own: padding (`p-1`, `px-2`,
  `pt-1`), margins (`m-1`, `mx-2`), `gap-1`, borders (`border`, `border-double`,
  `border-sky-500`, `border-none`), title placement (`title-center`), colours (`text-red-500`,
  `bg-slate-800`, `text-[#ff5500]`, `text-bright-blue`), `bold` `dim` `italic` `underline`
  `strike` `inverse`, text alignment, `truncate`/`nowrap`/`wrap`, sizes (`w-20`, `w-1/2`,
  `w-full`, `max-w-40`, `h-3`), `grow`/`grow-2`/`shrink-0`, `items-center`, `justify-between`,
  `hidden`/`block`, `flex-row`/`flex-col`. A class that is not a utility selects stylesheet
  rules as before and is otherwise ignored.
- Variants: `focus:`, `hover:`, `disabled:`, `selected:`, and the width variants `sm:` (under
  60 columns), `md:` (60 to 99) and `lg:` (100 or more), combinable (`lg:focus:bold`). A layout
  with `class: 'flex-col lg:flex-row'` stacks on a narrow terminal and stands side by side on a
  wide one, and switches when the terminal is resized across the width.
- Every utility is a stylesheet property, so a theme can set the same things. New properties:
  `margin` (and `margin-top` ...), `padding-top` ... `padding-left`, `gap`, `title-align`,
  `wrap`, `flex-grow`, `flex-shrink`, `display`, `flex-direction`, `align-items`,
  `justify-content`; `padding` now works on every element, not only on a `Box`.
- The order is: stylesheet rules, then the utility classes as written (a later one wins), then
  the inline `style:`. `Stylesheet::explain($element, $states)` prints what each rule and class
  set and which variants are in force, to find out why something looks as it does.
- Colours: `Color::parse()` reads all the colour spellings (Tailwind names and shades, the 16
  terminal names, palette indexes, `#hex`). The terminal device draws every cell in the nearest
  colour the terminal has: a Tailwind colour on a 256-colour terminal is the nearest of the 256,
  on a 16-colour terminal the nearest of the 16, where before it was sent as truecolour
  regardless. `Tty` reads the depth from `COLORTERM` and `TERM`; `$device->colors()` and
  `setColors()`; `new FakeTerminal(80, 24, colors: 16)` tests what a 16-colour terminal shows.
- docs/styling.md lists every class, variant, property and markup tag.

**Markup in text**

- Text takes symfony/console's markup: `<b>`, `<i>`, `<u>`, `<s>`, `<dim>`, `<fg=red>`,
  `<bg=blue>`, `<options=bold,underscore>`, `<href=https://...>`, the styles `<info>`,
  `<comment>`, `<question>`, `<error>`, and `<span class="text-sky-400 bold">` (the stylesheet's
  `Span.class` rules apply too); closed by `</>` or `</b>`. It works in `Text`, `Span`, `Box`
  titles, `Button` and `Checkbox` labels, `Table` headers and cells, `Help`, `Log` lines,
  `Select` items, `ProgressBar` labels and the text of `Markdown`.
- Widths and wrapping count the visible text only; a tag that is not closed or not known shows
  as written, and `\<` writes a literal `<`. A text with markup is parsed once and the result
  kept, so a screen that re-renders the same text pays nothing again.
- Changed: a text containing `<b>`-like tags used to show them literally; it now styles them.
  `Text::raw('<not markup>')` shows text exactly as given, `Markup::escape()` escapes what a
  user typed, `Markup::plain()` gives the visible text, and a widget of yours styles a label
  with `$this->markup($label, 'part')`.
- `Viewport::setTitle()` takes the title's alignment, and a title may carry escape sequences.

**Faster**

- A `Table` with `'auto'` columns measured all its rows on every cursor move: with 10,000 rows a
  move was slow enough to feel. It now measures the cells when the rows change (once), and a
  move through 10,000 rows costs what one through 100 does. The sorted and filtered order is
  kept the same way. (A widget built on `Table` that filtered by overriding `shown()` now
  overrides `filterRows()` and `filterKey()`.)
- A `ProgressBar` whose value changes by less than a cell and a percent draws nothing: it
  compares what it would draw with what it drew. A program that reports progress per byte no
  longer redraws per byte.

**Focus and keys**

- A screen whose program focuses nothing gives the focus to its first component that takes
  keys (top to bottom, left to right), so a form or a list answers keys at once. Before, keys
  went to the root until something was clicked or focused.
- Tab and Shift-Tab move the focus through the components that take keys, around, when no
  component on the key's way binds them. A `Terminal` keeps its Tab (the shell's completion),
  as does any component that handles the key.

**Fixed and documented**

- `detach()` with a `Terminal` running in a child, in a hidden tab or a shown one, or a
  program that ignores SIGHUP, returns at once and ends the programs (a regression test covers
  every case).
- An inline program taller than the terminal: the region is the terminal's height less one
  row, and what does not fit is clipped and not painted (docs/terminal.md says how to show the
  last rows instead).
- docs/widgets.md says in one line which `Select` callback fires when: `onSelect` whenever the
  current item moves, `onActivate` when the user picks it (Enter, a click, a dropdown's
  choice), `onChange` when the marked items change.

## 0.0.2-beta5 (2026-10-31)

Frames cost a fraction of what they did, the focus follows into every kind of tab, and a
dialog taller than the terminal scrolls instead of squeezing its fields. Everything from
0.0.2-beta4 works as before, except where an entry says what changed.

**Faster**

- A program that re-renders a big tree no longer eats a core. A component rendering a `Column`
  of 50 `Row([Text, Text])` that changes a counter in one `Text` at 10 Hz took about 44 ms of
  CPU a frame (44% of a core); it takes about 1.3 ms (about 1.3%). A 50-row monitor with a
  component per row (a `ProgressBar`, two `Text`s and two `Button`s) whose rows tick at 10 Hz
  went from a whole core to about 12-15% with one row in twenty changing (about 6.5% of that is
  the runtime waking 50 coroutines ten times a second and its idle garbage collection, which a
  program pays with no rendering at all), and from far more than a core to about 55% with every
  row changing on every tick (`tools/monitor-bench.php` measures it). Its key-to-screen
  latency went from 47 ms (spikes to 97 ms) to 0.2 ms (at most about 2 ms). What changed:
  styles are computed once per question; the windows, layouts and measures of what did not change are kept from frame to frame; a render
  that returns the same tree keeps its layout and paint; a component's measure stands until it
  renders, so a parent does not measure its unchanged rows again; a child that re-renders at
  the same size does not make its parent lay out again; text runs are written and parsed as
  runs; compositing copies and compares whole rows, and the escape sequences around a window's
  drawing and in a text's paint are the ones needed and no more (ESC 7 and the like are parsed
  as one token); the focus is settled only when it may have moved; the timed waits of all
  coroutines share one clock. docs/surface.md, "Performance", has the numbers, how to measure
  and what to do when a tree is big.
- Renders go with the terminal's frames: on a terminal device, what all components asked for
  since the last frame renders at the start of the next one, together, and is drawn in that
  frame (at most 60 a second; at once after a quiet sixtieth of a second, so a key in a quiet
  program shows right away). Fifty rows that wake in one frame were fifty render passes,
  composes and draws. A parent that has to lay out again because a child's size changed in the
  same frame does so in that frame: the half-done layout is no longer drawn for a frame.
- A `ProgressBar` with a value no longer wakes ten times a second: only an indeterminate one
  animates (fifty determinate bars were 500 wake-ups a second doing nothing).

**Fixed**

- Tabs whose content is an element tree holding components (`Row([$select, new Box($tree)])`)
  no longer lose the focus: going to such a tab by key (Ctrl-Tab, Alt-number), by `select()` or
  by mouse while the focus is in the content gives it to the first component that takes keys,
  and the components in it keep their state while the tab is hidden (they were disposed, and
  showing the tab again failed with "was disposed"). A tab with nothing to focus in it gives the
  focus to the bar, so Ctrl-Tab goes on from there.
- A dialog taller than the terminal no longer squeezes its fields: what does not fit is cropped
  and scrolls, a `Box` around a field stays three rows (it showed as its top and bottom border
  with nothing between, so the focused field was invisible and Down opened nothing), also with a
  field that takes any room (an `Editor` without `maxLines`) in the form. Giving a field the
  focus brings its whole `Box` into view, border and title included. A dropdown opened from a
  field of a scrolled dialog opens under the row the field is shown on (keys going through the
  dialog's scroll area no longer scroll it to its bottom for a moment, which closed the list at
  once).
- In a `Column` or `Row`, an item whose content fits is no longer squeezed to give a sibling
  that fills the room more: when all the `'auto'` items fit with what the filling ones need at
  least, they get what they need (a 15-row text above a `Log` in 24 rows gets 15, not 12).
  When they do not all fit, the room is shared as before.
- `freeze()` keeps the final row: it draws what is pending first, so the row frozen right after
  the last `setValue()` shows that value (it showed the frame before, 75% instead of 100%,
  unless the program slept first). `FakeTerminal::text()`, `region()` and `refresh()` draw the
  pending renders first too.
- A component made with `child('log')` and given to `Tabs` as a tab's content stays the
  owner's: `child('log')` keeps returning it while its tab is hidden, and it is stopped when
  its tab goes. docs/widgets.md says whose a tab's components are.
- A public method of a component named like a private one of the library (`key()`, `end()`)
  is bound to its key like any other (it was ignored, or the library's own ran).
- `focus()` on a component that is not started yet takes effect when it starts.

**Added**

- `Component::device()`: the terminal device the component's tree is shown on, also one that
  `Tui::run()`, `Tui::inline()` or `Tui::headless()` made, so a program started by the runner
  can `println()` and `freeze()` (`$this->device()?->println('copied a.txt')`).
  `TerminalDevice::of($root)` is the same for a root surface.
- `Tabs::content($key)`: what a tab shows (its running component, or its element).
- `Viewport::setFollowOnInput(false)`: a window that keeps its scroll position when keys go
  through it (as a `ScrollView`'s does).

**Changed**

- The methods of `Component` that the library relies on are `final` (`children()`, `focus()`,
  `child()`, `on()`, `style()`, `device()` and the others in the docs' method table; the hooks
  such as `render()`, `mount()`, `onKey()`, `measure()` and `bindings()` are not). A component
  that declared one of them for itself silently broke the library before; it now fails where it
  is declared, with "Cannot override final method phasync\Tui\Component::children()". Rename
  the method.
- A component shown on a terminal renders with the terminal's next frame (see "Faster"), so a
  render may come up to a sixtieth of a second after the change instead of in the next tick. A
  test that slept a few milliseconds and then looked at a component (not at the screen, which
  `text()` and `settle()` draw first) should sleep `1 / 60` or more, or `settle()`.
- `sleep()` of a component ends at a tick of a 60 Hz frame clock: the first after the time
  asked for, or one at most 2 ms before it. So the loops of many components wake together, in
  one frame, whenever they began; a `sleep(0.1)` loop still makes ten steps a second, but a
  `sleep(0.005)` lasts a sixtieth of a second. Use `fiber\sleep()` where a coroutine needs the
  millisecond.
- `Component::measure()` is asked once per room until the component is marked for a render or
  renders: compute it from what goes with a render (state, props, style). One that read
  something else that changes without a render should call `requestRender()` when it changes.
- fiber-interop/api and fiber-interop/phasync are still required at their development
  branches (0.3.x-dev, 0.4.x-dev): no versions newer than 0.3.1 / 0.4.1 are tagged yet.

## 0.0.2-beta4 (2026-10-31)

Nothing hangs silently any more, overlays in dialogs survive resizes, and two contributed
widgets: `Tree` and `Tabs`. Everything from 0.0.2-beta3 works as before, except where an entry
says what changed.

**Fixed**

- A program whose own code throws no longer hangs with the terminal in raw mode: when the code
  that started a root component fails after `start()` (a typo in the closure given to
  `fiber\run()`, a protected method called from outside), the component tree is disposed, the
  terminal is restored (modes reset, the alternate screen left), and the exception comes out of
  `fiber\run()` with its message, as it does without a terminal UI. The same for an exception
  in a component's `run()` or `go()` coroutine that no error boundary contains, also when the
  root was started on a plain `Surface` or attached with `TerminalDevice::attach()`. This
  needs fiber-interop/api newer than 0.3.1 and, on phasync, fiber-interop/phasync newer than
  0.4.1 (their main branches until the next tags); with the older versions `start()` behaves as
  before for the code that started it, and `Tui::run()` (below) is the way to get this.
- A dialog with an open dropdown no longer falls apart when the terminal is resized: the
  dialog is centred again, the list stays next to its field inside the dialog (or closes when
  the field is no longer shown), and nothing of the dialog is left blank. The same in a nested
  instance and through a storm of resizes. A dropdown in a dialog opens on the side that keeps
  the dialog's buttons visible when it fits on both.
- A dialog whose small terminal hides the focused field scrolls it back into view after a
  resize (`ScrollView` brings the focus into view again), so an open list stays open.
- A dropdown's list follows the width of its field when the field grows or shrinks.
- `Dialog::accept()` overriding with `$form->submit()` no longer recurses forever: while the
  dialog is asking `accept()`, `submit()` of its forms does nothing and returns whether the
  form is valid.
- `Form::values()` after its dialog closed gives the values it was submitted with (the fields
  are gone by then), so `await($this->dialog(...))` followed by `$form->values()` works.
- A completion popup that shrinks while it is open stays on the side it opened on: filtering
  four candidates above the field to one no longer moves the one row below the field, over the
  box's bottom border.
- A `ProgressBar` never draws past its own width: a long label in an export dialog no longer
  shows one cell over the border.
- A `Column` of components that each need one row (progress bars, your own row components)
  takes the rows they need without a `height:`: a component without its own `measure()` is
  measured by the element tree it renders.

**The program and its end**

- `Tui::run($root)`, `Tui::inline($root)` and `Tui::headless($root, $cols, $rows)`: the runner.
  `run()` and `inline()` are a whole program (`Tui::run(new App());`): they wrap it in
  `fiber\run()`, start the root on the terminal, return the ended root when it quits and
  throw what failed, with the terminal given back, on any version of fiber-interop. A root run
  this way quits on Ctrl-Q when no component binds that key (`Help` lists it as "quit").
  `headless()` starts the root on a `FakeTerminal` for tests, inside your own `fiber\run()`.
- `Component::quitOn('ctrl+q')`: the key that disposes a root component when nothing took it;
  `null` turns it off.

**Changed**

- `ProgressBar` shows its label *and* the percentage (`████░░ 3/10 files 30%`), so an inline
  runner needs no `Text` beside it. A label may hold `{percent}` (`42%`) and `{value}`
  (`0.42`) to place them itself; then nothing is added. Before, a label replaced the
  percentage. The label takes at most half the width.
- `Component::stop()` is public and final: a parent stops a child it no longer shows without
  waiting (it was private). A component of yours with a method `stop()` of its own must rename
  it (`examples/inline-progress.php` calls its method `quit()` now).
- The fiber-interop versions the tests run on are the main branches (require-dev
  `fiber-interop/api: 0.3.x-dev`, `fiber-interop/phasync: 0.4.x-dev`); the library itself
  still requires `fiber-interop/api: ^0.3.1`.

**Components: what widget authors asked for**

- `wantsPointer()`: a component returns true to get mouse motion (for `:hover` of its own);
  a stylesheet rule with `:hover` on a part (`Tabs::tab:hover`) asks for it too, so hovering a
  tab highlights it.
- `keep($key, fn)`: a child the current render does not show stays mounted and keeps its
  state (a `Terminal` goes on running); `stop()` ends one the parent no longer wants.
- Bindings with `'first' => true` run before the focused child gets the key (Ctrl-Tab in a
  tab container, Ctrl-Q while a `Terminal` has the focus); plain bindings run after it, only
  when nothing took the key (Escape unless a field took it). `Help` lists both. The whole order
  is in docs/components.md, "Key bindings", with an example.
- A part's style inherits the component's base style and the state of the row it is drawn on
  (`$this->style(['node', 'marker'], 'current')`): a marker without a colour of its own keeps
  the highlighted row one bar.
- `focusNext()` and `focusPrevious()` on `Component`, for Tab handling without `$this->surface`.
- `TerminalDevice::freeze($row)` (a row number or a component's surface): inline, the row
  goes above the region into the scrollback as it shows now, and the region shrinks when the
  program drops it; full screen, it is written after the terminal is given back, as
  `println()` is. A finished job no longer needs formatting a second time for `println()`.
- docs/components.md now states when a parent renders again from a child's state (a callback
  or an event it listens to, with the two remedies) and that a `load:`-style callback may
  return any object other than a `Traversable` as an awaitable, passed to `fiber\await()`.

**Tree and Tabs (contributed)**

- `Tree`: hierarchical items with expand/collapse (Right/Left/Enter/Space and a click on the
  ▸/▾ marker), lazy children through a callable that may return an awaitable (a spinning sign
  on the node while it loads), movement keys, wheel and click, type-to-find, multi-select,
  switchable guide lines, the parts `Tree::node` (`current`, `selected`, `loading`, `failed`),
  `Tree::marker` and `Tree::guide`, the events `select`, `expand`, `collapse`, `activate` and
  `change`, `setItems()` keeping expansion, marks and the current node by key, and only the
  visible rows drawn (100,000 nodes). docs/widgets.md, `examples/tree.php`.
- `Tabs` widget: a tab bar (top or bottom) with one content per tab; components in hidden tabs
  keep their state (a `Terminal` goes on running); Left/Right, Ctrl-Tab, Ctrl-Shift-Tab,
  Alt-1..9, click, middle click, wheel; a scrolling bar with `‹` `›` when the labels exceed
  the width; `setTabs()` keeps the current tab by key; `change` and `close` events; parts
  `Tabs::bar`, `Tabs::tab` (`current`, `hover`, `focused`), `Tabs::hint`, `Tabs::close`,
  `Tabs::more`; Tab/Enter into the content, Escape back to the bar. Default theme rules added
  to `Stylesheet::default()`. docs/widgets.md, examples/tabs.php.
- Merged and aligned with the library: `Tree` scrolls with `Scrollable` (**changed** from the
  branch: `topIndex()` is `scrollTop()`, and Ctrl-P/Ctrl-N are not bound by default; add them,
  or j/k, with `bind()`), composes its parts with `$this->style([...])` and takes `key`, `id`,
  `class` and `style`. `Tabs` keeps its component tabs with `keep()` (stopped when
  `setTabs()` drops their tab), binds Ctrl-Tab and Ctrl-Shift-Tab as `first` bindings
  (`nextTab()`, `previousTab()`), and its `Tabs::tab:hover` rule works without a rule on the
  element itself.
- README tour: tabs with a shell that keeps running, and a tree of 100,000 nodes.

## 0.0.2-beta3 (2026-10-31)

Inline mode, the Markdown widget, hyperlinks, one way to scroll, and four defects from use.
Everything from 0.0.2-beta2 works as before, except where an entry says what changed.

**Fixed**

- A popup whose content shrinks no longer keeps its old size: an `Editor`'s completion list
  filtered from four candidates to one is one row tall, and the rows it covered (the border of
  the box under it) show again. The same when it grows again after Backspace (the list now
  filters on every change of the text, not only on typed letters), when a dropdown's items are
  replaced while its list is open (`Select::setItems()`), and when a dialog's content changes
  size (a validation message appearing). An overlay is measured every frame.
- A dialog given `with(width:, height:)` draws its content, gives its first field the focus and
  takes what you type, as it does without a size; a multi-line `Editor` in it fills the room.
- A `Row` of one-line fields, a toolbar of buttons or a `Column` of one-line texts no longer
  takes a share of the screen when it has no `height:`: a container without a size is as large
  as its children need (the sum along it, the largest across), and takes a share only when a
  child of it takes whatever it is given.
- A `Form` inside a `Dialog` can be submitted: Enter in a field validates the form and, when it
  is valid, answers the dialog with its first button's value (`accept()` is called); an invalid
  form stays open with its messages. Tab reaches the dialog's buttons, which press as without a
  form; the Add button submits the form first, Cancel does not. docs/widgets.md "A form in a
  dialog" works as written.
- A dialog button whose value is `null` (`'Cancel' => null`) answers null instead of the first
  button's value.
- An inline program that sets its last state and quits at once shows that state in the frame
  it leaves behind: disposing a root renders what was asked for first.
- Full screen, the cursor is no longer sent again on frames where nothing moved it.

**Inline mode: a live region under the shell's output**

- `Tty::inline()` (and `FakeTerminal::inline()` for tests) shows the root as a region at the
  bottom of the terminal instead of taking the terminal over: no alternate screen, the shell's
  output stays above it, rows that scroll off the root's top are written once into the
  terminal's scrollback as they looked then, and on exit the last frame stays with the prompt
  under it (`keep: false` erases it). Width changes repaint the region; a lower terminal pushes
  its top rows into the scrollback. Only relative cursor moves are used. The mouse is reported
  only while a surface asks for it. The same component tree runs full screen or inline.
- `TerminalDevice::println($text)`: a line (or several, wrapped, with SGR) above the region,
  into the terminal's scrollback. Full screen it waits until the terminal is given back.
- `examples/inline-progress.php`: five jobs with progress bars and a total, finished jobs
  printed above them (`--fullscreen` for the same full screen).

**Hyperlinks (OSC 8)**

- Links written as OSC 8 (`Ansi::link()`, `Ansi::hyperlink($text, $uri)`, or a program in a
  `Terminal` pane, such as `ls --hyperlink`) are kept in the cells (`Style::$link`), survive
  compositing and a replay of the byte stream, and are clickable on terminals that support
  them; elsewhere the text shows plain.
- `TerminalDevice::supportsHyperlinks()`: `Tty` decides from the environment (kitty,
  WezTerm, iTerm2, foot, ghostty, VS Code, Windows Terminal, VTE; not inside tmux or screen)
  and the terminal's own name, conservatively; `FakeTerminal(hyperlinks: false)` for tests.
  `terminalName()` is the name the terminal answered with (XTVERSION), when it did.

**Markdown**

- New widget `Component\Markdown`: CommonMark in the terminal (headings, wrapped paragraphs,
  emphasis, strong, code spans, links, nested lists, block quotes, fenced code with a simple
  php/js/sh/json highlighter, tables, thematic breaks), styled by one stylesheet part per kind
  (`Markdown::h1`, `Markdown::code`, ...; the default theme sets them), scrolling like `Log`,
  following the end with `tail: true`, and streaming: `append()` takes text that ends anywhere,
  remembers the rows of unchanged blocks and writes only the screen rows that changed. `set()`
  replaces the text. `hyperlinks: true` (pass `supportsHyperlinks()`) makes links clickable
  instead of showing their address; `scrollbar: true`. Contributed; example
  `examples/markdown.php`.

**Scrolling: one helper, `Component\Scrollable`**

- A widget that draws its own scrolling content uses the trait `Scrollable`: the position,
  Up/Down, Page Up/Page Down and Home/End, the wheel, following the end, the `scroll` event and
  a scrollbar in the parts `::scrollbar` and `::scrollbar:thumb`. `Log` and `Markdown` use it.
- `Log` scrolls with the keys when it has the focus, and `new Log(scrollbar: true)` shows a
  scrollbar. **Changed:** `Log::scrollTo($row)` and the new `scrollTop()` count rows from the
  oldest row kept (0), no longer from the top of the following window; `scrollBy()` is as
  before.

**Styles**

- Style parts that apply together compose: `$this->style(['heading', 'strong', 'link'])` (and
  `Stylesheet::compute()` with a list) adds the attributes up and lets the innermost colour
  win, so a widget no longer merges them by hand.
- `max-width` and `max-height` in the stylesheet: the most an `'auto'` size, or an overlay's
  measured size, may take (a completion list at most 8 rows).

**Small**

- `$this->dialog($dialog, key: 'add')`: a dialog of that key that is open already gets the focus
  and its answer is returned, so a key that opens a dialog does not open a second one when
  pressed twice.
- `Form` emits `submit` (with the form) when it submits, and `enter()` is the action of Enter in
  it; Tab at the last field of a form in a dialog goes on to the dialog's buttons
  (`focusNext(wrap: false)` on surfaces and components).
- docs/components.md says where overlays live: on the root, above the whole tree (not on the
  nearest `Layers`), as the spec does.
- docs/surface.md: writing into the last column with autowrap off leaves the cursor on that
  column, so `\e[K` erases what was just written; that is what xterm does. docs/components.md:
  the conventions of a widget's constructor (props, callbacks, `...attributes`).

## 0.0.2-beta2 (2026-10-31)

Overlays, and what the first applications on the components layer ran into. Everything from
0.0.2-beta1 works as before, except where an entry says what changed.

**Quitting with a dialog open ends the program**

- A program whose dialog (`Dialog`, `Confirm`, or one of yours with editors in it) was open when
  it quit now ends: disposing a component, the root or a child, answers every dialog it opened
  with its cancel value, disposes it and leaves nothing running, so `fiber\run()` returns. The
  coroutine awaiting the answer gets the cancel value and goes on.
- One rule for keys while a dialog is open, and the docs say the same: the component that
  opened it is suspended (its `onKey()` and bindings wait), its parents' bindings work as
  always, and a binding marked `always` works even in its own component's dialogs:
  `'ctrl+q' => ['quit', 'quit', 'always' => true]`. A root's Ctrl-Q no longer stops working
  while the root's own Confirm is open (mark it `always`); a Ctrl-Q of the application under a
  pane's dialog works without it.

**Overlays: anything can float (`Component::overlay()`)**

- `$this->overlay($element, anchor:, place: 'below'|'above'|'center'|Rect, modal:)` shows any
  element over everything, outside the component's rectangle and its parents' clipping, and
  returns an `Overlay` (`close($result)`, the awaitable `closed()`, `isOpen()`, `result()`).
- It never leaves the screen: it goes on the preferred side of its anchor if it fits, else the
  other side, else the side with more room, shrunk to it with its content scrolling (a list of
  30 items in 6 free rows shows 6 and scrolls); shifted sideways to stay inside. The decision
  is `Placement::for(anchor, root, desired, place)`, which widgets can use themselves.
- When the terminal is resized or its anchor moves, it is placed again after that frame's
  layout and keeps its state (a dropdown keeps its current item); when the anchor is no longer
  shown it is cancelled. Later overlays stay above earlier ones.
- A modal overlay takes all input (clicks outside reach nothing); a non-modal one closes on
  Escape, a click outside it, or the focus moving elsewhere; closing gives the focus back. An
  overlay over a `Terminal` pane leaves the program's output alone: the pane shows correctly
  when it closes.
- `Dialog` and `Confirm` are overlays now: centred, modal, as large as their content needs (no
  more fixed 60x12 for element content), and shrunk to a smaller screen with their content
  scrolling and the buttons in view.

**Dialogs that are forms**

- `Dialog::accept($answer)`: return false to keep the dialog open (a form that is not valid
  yet), instead of closing and reopening it.
- Tab and Shift-Tab in a dialog go through its fields first and its buttons after; no more
  rebinding Tab in form dialogs. The focus starts on the first field. Enter in a field presses
  the first button. The buttons are `Button` components (style them as `Button`; the parts
  `Dialog::button` are gone).

**Auto sizes no longer swallow their siblings**

- A one-line `Editor` without a width in a `Row` no longer takes the whole row: a `Text(width:
  20)` after it is drawn. A `Table` or `Select` without a height in a `Column` no longer
  measures as all its 10,000 rows: the `Help` line under it keeps its row. The rule: an
  `'auto'` item gets what its content needs, but no more than a `'1fr'` share while its
  siblings need room. A `Table` measures as wide as its columns need.

**Focus and the mouse**

- `$board->focus()` after `$board->filter->focus()` gives the focus to the board again, not
  back to the filter: a component with keys of its own (bindings, or an `onKey()`) takes the
  focus itself; containers (a `Box`, a `Dialog`, a `Form`, a screen of fields) still pass it to
  the field they focused. `canFocus()` decides, and can be overridden.
- A mouse drag goes to the component that got the press, with its release, wherever the
  pointer goes, in coordinates relative to that component that may lie outside it (negative,
  or beyond its size). Drag and drop between siblings no longer needs the root's `onMouse()`.

**Editor**

- A multi-line `Editor` never joins lines: a paste of more lines than `maxLines` used to join
  the extra lines with spaces. `maxLines` is now how many rows it shows (it scrolls beyond);
  only a single-line editor (`maxLines: 1`) joins.
- An editor with `maxLines` above 1 grows with its text up to that many rows, and a `Box`
  around it with it: a chat input.
- `history: true`: Up and Down on the first and last rows walk the submitted texts, as
  `TextInput` did; `history()`, `setHistory()`.
- `complete: fn(string $word) => [...]`: Tab shows completions for the word at the cursor in a
  list under it; typing filters, Tab or Enter accepts, Escape closes.
- `Editor` is no longer `final`: extend it for a chat input or a field of your own (bindings,
  the public actions, `insert()`, `onKey()`, `measure()`).
- Enter in a single-line editor without `onSubmit` goes on to the dialog or form it is in.

**Select**

- A multi-select can filter on text with spaces: typing starts a filter mode in which Space is
  a space; Enter ends the mode (the filter stays) so Space marks again; Escape clears it. `/`
  starts the mode without typing.
- `dropdown: true`: a one-line field that opens its list under itself (above near the bottom),
  as an overlay.

**New widgets**

- `Button` (Enter, Space, a click; `:focused`, `:pressed`, `:disabled`), `Checkbox` (also as a
  switch, `toggle: true`), `ProgressBar` (a value from 0 to 1 with a label, or indeterminate),
  `Spinner` (animates by itself, no coroutine of yours), `ScrollView` (content taller than its
  box; the focused field stays in view), and `Form`: fields from the element tree by `id`, Tab
  order, validation with messages and `:invalid` fields, submit by Enter or a button. The
  README's example is a form made of them.

**Styling and Help**

- `setStyleState('selected', true)` puts a component of yours in a state of its own, so
  `.card:selected` works without inventing a part.
- A self-drawing component that clears its surface (`\e[2J`) gets its themed background: the
  pen is set to the component's style before `render()`.
- `Help` fits one row: it is cut with "…" where it does not fit and wraps only when given a
  height. `new Help(show: 'focused' | 'application')` lists only the focused component's keys,
  or only the application's.

**Testing headless, and the docs**

- `FakeTerminal::settle()` waits for the frame the input asked for: a screen read right after
  `type()` or `press()` showed the previous frame. The docs' examples use it, and say that
  `usleep()` blocks the loop.
- `FakeTerminal::run(fn(FakeTerminal $t) => ...)` detaches the terminal afterwards, also when
  the test throws: a failing test fails instead of hanging `fiber\run()`.
- `$component->children()` reads a child by key from outside (`$app->children()['log']`);
  creating children stays the component's.
- docs/components.md says plainly that a child placed with `new` and unchanged props does not
  render again when its parent renders, and gives the two remedies (a prop, or a shared
  `State`); it documents the overlays, the key rule under dialogs, focus and drags.

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
- A `render()` that calls `focus()` (the README's `$input->focus()`) no longer takes the focus
  back after the user moved it: renders the library makes by itself leave the focus where it
  is; the first render that asks, and a render asked for with `requestRender()`, apply it as
  before. Without this, Alt-Right in the README's program moved to the shell and the render
  that key caused moved straight back.

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
