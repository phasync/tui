# 0.0.3-beta3: input without a class, and two defects from use

Everything from 0.0.3-beta2 keeps working. Item 1 is SPEC 9.1 as amended; 2 and 3 are defects
with the exact shapes from use; 4 is small. If this round finds nothing new, 0.0.3 is done.

## 1. A closure program receives input (SPEC 9.1, amended)

A one-file program (`Tui::run(fn() => new Column([...]))` over a `State`) today cannot react to a
key or the mouse and cannot open a dialog: elements have no key or mouse hook, the closure root
"has no keys of its own", `Tui::run()` returns the root only when the program ends, and
`dialog()` is protected on `Component`. Required, as the SPEC now says:

- `Tui::run($root, keys: [...])` (and `inline`, `headless`) binds keys on the root with the
  `bindings()` syntax, including `always`; the handlers run before the focused child's.
- Elements take `onKey:` and `onMouse:` closures. An element with `onKey:` is focusable (it
  joins the Tab walk, shows `focus:` styles) and gets the keys when focused; one with `onMouse:`
  gets presses, drags and releases over its area with the position relative to it, and the usual
  wheel. Drag between two elements: the press goes to the element under the pointer, the release
  to the same element with the release position, so a program can find the target by position
  (`Tui::elementAt($x, $y)` or the Mouse event carrying the element under the release).
- `Dialog::open($dialog)` and `Confirm::ask(title, text)` are awaitable from any coroutine (a key
  handler, a `go()`), host the dialog on the running root, and resolve with the answer; one
  dialog at a time per root as beta3 decided.

Tests: a closure Kanban of three Boxes with Text cards: arrows change `$s->sel`, Shift+arrows
move a card, a press on a card and a release over another column moves it, Enter opens a
`Dialog::open()` with a Form and the answer lands in the State; all under `Tui::headless()`.
docs/components.md gets a "Without a class" section; the cookbook's dialog screen loses its class.

## 2. A component instance kept across closure renders shows another row's value

`Tui::headless(fn() => new Column([...]))` building `Row([Text, $bars[$i] ??= new
ProgressBar(fn() => $s->p[$i])])` for jobs a and b plus a total row. A `State` change makes the
tree omit row a. Row b then shows 50%, the total's value, and `freeze($bars[1]->surface)` freezes
that wrong row. The kept instance is matched to the tree by position, not identity. Required: a
component instance that appears in the next tree keeps its identity, state and surface wherever
it moved; instances that left the tree are stopped; new instances are new. Test the exact shape
(two jobs, eight jobs, drop the first, drop the middle, reorder), full screen and inline, with
`text()` and the frozen history checked; and give a closure program a documented way to reach a
keyed component in the tree (`FakeTerminal::root()` / `Tui::headless()` returning something with
`children()`, or the instances the program kept, which is the recommended way).

## 3. Tab stops working after a hidden Terminal tab was shown

`Tabs` whose shell tab is `$this->child('term', fn() => new Terminal([...]))` (the owner's child
as content, as docs/widgets.md describes). Start: Tab walks table → tree → bar. Show the shell
tab (Alt-2) and go back (Alt-1): Tab on the table now does nothing, however often pressed;
Shift-Tab still moves. With the Terminal given as a prop (`new Terminal(...)` in the tabs) it
works. The hidden, kept-alive Terminal takes part in the Tab order and the focus bounces back.
Required: a component the current render does not show is never in the focus order, whoever owns
it; test the exact sequence above with `child()` content, with prop content, and with `keep()`.

## 4. Small, from use

- `examples/dashboard.php` runs headless without editing: `Tui::run()` honours an environment or
  argument (`--headless=COLSxROWS` printing the screen, or `TUI_HEADLESS`) so examples and the
  cookbook are testable as written; the README says how.
- The completion popup covers the Editor's Box border row; place it just outside the Box when the
  Editor is the Box's only content, or over the content row, never over the border.
- An exception in a `go()` coroutine is a plain fatal uncaught error; the 0.0.2 "restore the
  terminal, then the message" path applies to every coroutine the program started inside
  `fiber\run()`, so the message is readable; document the error-boundary alternative.
- Tabs props do not update without `setTabs()`; say so in the Tabs docs at the top, or make a
  `Tabs` built anew per render update its tabs (closed tabs still stay closed).
- Deleting the current `Table` row scrolls back to row 1; keep the position (clamped).
- A `Dialog` spans the full width by default; its default width is its content's measured width
  plus padding, at most 80% of the root.
- Cookbook 9 says "1 samples": plural form.
- CHANGELOG under `## 0.0.3-beta3`, each entry stating what a user sees differently.
