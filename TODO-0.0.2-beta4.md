# 0.0.2-beta4: nothing hangs silently, overlays survive resizes, Tree and Tabs merged

Everything from beta3 keeps working. Items 1 and 2 are defects with the exact shapes from use;
3 merges two contributions; 4 is the contract work their authors asked for; 5 is small.

## 1. An exception in the program's own code must end the program, visibly

A component is started with `->start($terminal)` inside `fiber\run()`. The run closure then
throws (a typo: a protected method called from outside). Today nothing is printed and
`fiber\run()` never returns: the app's coroutines keep the frame open and the exception waits
behind them; on a real tty the terminal stays in raw mode and the alternate screen with no
message. Required: when the task that started a root component (or any task in the frame the
library can see) fails, the running app is disposed, the terminal is restored, and the exception
propagates out of `fiber\run()` as it does without a TUI. Use what `fiber\` offers: the handle
of the starting task is known at `start()` (`fiber\current()`); the library can `go()` a watcher
that awaits that handle and disposes on failure (`fiber-interop/api` main and
`fiber-interop/phasync` main now make awaiting a parent's handle from a task started inside it
work, and the api's conformance example 23 covers it; require the versions that carry it once
they are tagged, and until then document the exact versions needed). If the watcher cannot be
expressed, make `start()` document the limit and give `Tui::run()`-style helpers (see SPEC 9.1,
only the runner: `Tui::run($root)`, `Tui::inline($root)`, `Tui::headless($root, cols, rows)`)
that own the run closure and so always see its exception. Tests: the exact shape, under
FakeTerminal and in a forked process with a pty, asserting the message reaches stderr, the modes
are reset and the process exits non-zero within a second. The same for an exception thrown in a
component's `run()`/`go()` coroutine that no error boundary handles.

## 2. Overlays inside a dialog across a resize

A Dialog holding a Form with a dropdown `Select`; the dropdown is open; the terminal is resized.
After the resize the list is painted at column 0 of the screen without the dialog, and the
dialog's lower part (fields, buttons, bottom border) is blank. Expected: the dialog is placed
again (centred) and the list stays anchored under its field inside the dialog, or closes if its
anchor is no longer shown; nothing under them is left unpainted. The anchor of an overlay opened
from inside another overlay is the inner component's area *after* the outer overlay was placed
in that frame (place overlays in opening order, each after its anchor's layout). Tests: the shape
above at several sizes including one where the dialog no longer fits; a dropdown in a dialog in a
nested instance; the resize storm from the acceptance tests with a dialog and a dropdown open.
Also from the same report: a dropdown's list in a dialog should prefer the side that keeps the
dialog's buttons visible when both fit.

## 3. Merge `widget/Tree` and `widget/Tabs`

Two contributor branches, one commit each, built on beta3. Review as the maintainer, merge onto
this version, align them with the library's rules and with Markdown (Scrollable trait, parts
composed through `$this->style([...])`, key/id/class/style attributes, own style before drawing),
keep their tests green, move their CHANGELOG entries under this version, and add a Tabs-with-a-
Terminal-pane and a Tree-of-100k-nodes example to the README tour. Tree's j/k-style extra keys,
if any, follow the Markdown decision (not default; `bind()` adds them).

## 4. What the widget authors found missing in the contract

- **Pointer tracking on request.** `:hover` on a part (`Tabs::tab:hover`) never receives motion
  because tracking is only switched on when a selector names `:hover` on the element itself.
  Give components a declared way to ask for motion (`wantsPointer()` or an attribute), and make
  a part's `:hover` selector count as a request.
- **Kept-alive children.** Components that hold panes the current render does not show (hidden
  tabs) have no documented way to keep them alive except calling `child($key, fn)` for each on
  every render. Provide and document one (`keep(...)`, or `Tabs`-style containers declaring their
  children as owned), and a public non-waiting `stop()` for a child the render no longer wants.
- **Key order.** An ancestor's `onKey()` runs before the focused child and its `bindings()`
  after it. Document the combined rule for a container that wants some keys first (Ctrl-Tab) and
  some last (Escape unless taken), in one paragraph with an example, and make sure `Help` lists
  both.
- **Reactivity across parent and child.** A parent rendering from a child widget's state renders
  again only through a callback or an event; state the rule in docs/components.md next to "a
  child emitted an event it listens to", with the two remedies.
- **Styles of several parts on one row.** Document (and make true) that a part's style inherits
  the component's base style and the row state it sits in, so a marker without its own colour
  keeps the highlighted row as one bar; expose the merge the library uses so widgets do not write
  their own `Style::of()` overlay.
- **Awaitables from user callbacks.** Document for `load:`-style callbacks that any object other
  than a `Traversable` is treated as an awaitable and passed to `fiber\await()`.
- `Component` gets `focusNext()`/`focusPrevious()` so Tab handling does not need
  `$this->surface`.
- A `Column` of row components each measuring one row needs no `height:` (beta3 fixed the Row
  case; the report says components placed in a Column still do); test and fix.

## 5. Small, from use

- `Dialog::accept()` is asked after the Form validated; an `accept()` override that calls
  `$form->submit()` now recurses forever. Guard it (submit inside accept is a no-op or an error
  with a message), and say in docs/widgets.md where to read the values (the fields are gone after
  the dialog closes; `Form::onSubmit` or `values()` returned with the answer).
- The one-row completion popup is placed below the cursor line over the box's bottom border
  although it was above at four rows; prefer the side the larger size used while the overlay
  stays open.
- `ProgressBar` with a label: the percent stays (label *and* percent, or `label:` with `{percent}`
  and `{value}` placeholders), so inline runners do not need surrounding Texts; a label never
  runs past its box (an export dialog showed one cell over the border).
- Inline mode: a program should be able to freeze a finished row itself (`$tty->freeze($rowOrElement)`
  or the root's scroll), instead of formatting it a second time for `println()`.
- CHANGELOG under `## 0.0.2-beta4`, each entry stating what a user sees differently.
