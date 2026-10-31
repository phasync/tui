# 0.0.2-beta2: overlays, and what the first apps on the components layer ran into

Everything from beta1 keeps working (the suite, the examples, the README programs). Items 1 and 2
are the version; the rest is from use and is smaller.

## 1. Quitting with a dialog open must end the program

A program whose root binds Ctrl-Q to `$this->dispose()` exits at once without a dialog, and never
returns from `fiber\run()` when a `Dialog` or `Confirm` is open, awaited from one of the root's
coroutines (`$this->go(fn () => fiber\await($this->dialog(new Confirm('Sure?'))))`). The binding
runs; the coroutine awaiting the answer, the dialog's own coroutines or the frame loop keep the
runtime alive. Required: disposing a component (or the whole tree) while any dialog it opened is
open answers the dialog with its cancel value, disposes it, and leaves nothing running, so
`fiber\run()` returns. The same for a dialog opened by a child that is disposed. Test it exactly in
this shape, with both a `Confirm` and a `Dialog` subclass with Editors inside, and add it to the
randomized lifecycle test: dispose at a random moment with a random number of dialogs open.

Related, from the same apps: the root's bindings are suspended while its own dialog is open, so the
application's Ctrl-Q does not work there; docs/components.md promises the opposite ("its parents'
bindings still work: Ctrl-Q quits"). Decide one rule and make the docs and the behaviour agree; the
rule the apps want is that a modal dialog takes the keys of the component it belongs to *except* the
bindings that component marks as application-wide (or: the bindings of its ancestors and of the
root always apply, the component that opened the dialog is the one suspended).

## 2. Overlays (SPEC 8.5)

Implement section 8.5 as written: `$this->overlay($element, anchor:, place:, modal:)` returning an
`Overlay` handle; `Placement::for(anchor, root, desired, place)` as a pure value class with its own
tests (preferred side, flip, shrink-and-scroll on the side with more room, horizontal shift, hard
root boundary); re-placement after the layout pass of a resize frame, state kept, closed with the
cancel value when the anchor is no longer shown. Then build on it: `Dialog` and `Confirm` become
overlays (centred, modal; a dialog whose desired size exceeds the root is shrunk and its content
scrolls), `Select` gains a dropdown mode (a closed single-line field that opens its list as an
overlay anchored to itself; opens upward near the bottom), and `Editor` gets a completion popup
(`complete: callable` returning candidates for the word at the cursor, shown as an overlay at the
cursor, Tab/Enter accepts, Escape closes, typing filters). Tests through `FakeTerminal` for the two
cases the spec names (dropdown at the bottom, terminal shrunk under it and grown again; dialog
larger than the terminal) plus: an overlay covering a Terminal pane does not disturb the pane's
own output (the pane is repainted correctly when the overlay closes), and mouse clicks outside a
modal overlay reach nothing, outside a non-modal one close it.

## 3. Auto sizing must not swallow siblings

Two shapes from use, both with a tiny repro: a one-line `Editor` without `width:` in a `Row` takes
the whole row and a `Text(width: 20)` after it is not drawn at all; a `Table`/`Select` without
`height:` in a `Column` measures as all its rows (10,000), so the `Help` line under it gets no row.
Rule: an element's `'auto'` size is its measured size *capped by the room that is left after the
fixed and fr siblings have been given theirs*; a component whose measure is unbounded in a direction
(a one-line Editor's width, a Table's height) is `1fr` in that direction unless given a size. Test
both shapes.

## 4. Focus, two cases

- A component that focused a descendant must be able to take the focus itself again:
  `$board->focus()` after `$board->filter->focus()` currently goes straight back to the filter.
  `focus()` on a component focuses the component (its own surface) and `focusToward()`/the
  remembered descendant is only used when the component itself is not focusable or asks for it.
- Mouse drags: the component that received the press receives the whole drag and the release,
  wherever the pointer goes, with coordinates relative to its own rectangle that may fall outside
  it (negative, or beyond width/height). Today the coordinates are clamped to its rectangle, which
  makes drag-and-drop between siblings impossible without the root's `onMouse`. Document the rule
  in docs/components.md.

## 5. Editor

- A multi-line `Editor` must accept any number of lines whatever `maxLines:` says: `maxLines` is a
  cap on how many rows it *shows* (it scrolls beyond that); today a paste of more lines than
  `maxLines` silently joins the extra lines with spaces, which loses data. A separate
  `singleLine: true` (or `maxLines: 1`) is the only mode that joins.
- An Editor that grows with its content up to a cap: measure reports the content height, capped by
  `maxLines` (and item 3 caps it by the room), so a Box around it grows as the user types.
- `history:` as `TextInput` had it (Up/Down at the first/last row, when the editor is single-line or
  the cursor is on the edge rows).
- `Editor` is `final`; either unseal it or make its parts (measure, key handling) extensible in a
  documented way, so a chat input or a form field can specialise it.

## 6. Dialog and Select

- A `Dialog` subclass needs a hook to validate before it closes: `accept(mixed $answer): bool`
  (or an equivalent), so a form dialog can keep itself open with error texts instead of reopening.
- Tab inside a Dialog moves between its focusable content first and its buttons after, so form
  dialogs do not have to rebind Tab.
- A Dialog with element content sizes to its content (capped by the root, item 2) rather than a
  fixed 60x12 default.
- `Select` with `multiple: true` cannot filter on text containing a space, because Space toggles
  the mark. Make the filter a mode (`/` or typing starts it; Space inside the filter is a space)
  or move the mark to another key; whichever, document it.

## 7. Small widgets the apps all wrote themselves

`Button` (label, `onPress`, Enter/Space/click, `:focused`/`:pressed`/`:disabled`), `Checkbox`
(and a `Toggle` look), `ProgressBar` (value 0..1, optional label, indeterminate), `Spinner`
(frames, driven by the library's frame loop, no coroutine needed by the user), and `Form` as 8.6
names it (fields from the element tree, Tab order, validation, submit). Each with docs, styled
states and tests through FakeTerminal; the README's form example uses them.

## 8. Styling and Help

- A custom component can declare its own states for the stylesheet (`$this->setState('selected',
  true)` or similar) so `.card:selected` works without inventing a part.
- A self-drawing component that clears its surface gets the themed background: `\e[2J` paints the
  component's current style, not the terminal default; document how a component applies its style
  before it draws.
- `Help` fits one row by default: it cuts the list to its width (with `…`) and only wraps when given
  a height; it should also let the app choose what it lists (the focused component's bindings only,
  the application's only, or both).

## 9. Reactivity and headless testing, from the docs' side

- A child placed with `new` and unchanged props is *not* rendered again when the parent renders;
  the docs read as if it were. Say so plainly in docs/components.md, with the two remedies (shared
  `State`, or passing the changing value as a prop).
- `FakeTerminal`: screen reads right after `type()`/`press()` show the previous frame. Add a method
  that waits for the next frame (`$t->frame()` or `settle()`), use it in every doc example that
  reads the screen after input, and say in the FakeTerminal docblock that `usleep()` blocks the
  loop.
- A headless test whose own `fiber\run` closure throws while a terminal is attached hangs instead
  of failing (the runtime waits for the terminal's coroutines). Give `FakeTerminal` (or `attach()`)
  a way to end everything when the attaching coroutine throws, so a failing test fails.
- `child($key)` is protected; tests and parents need to reach a child by key. Make reading a child
  by key public (creation stays the component's).

## 10. Also

- A `LICENSE` (MIT) and a licence note are in the repo now; composer.json already says MIT. Keep
  them.
- `docs/widgets.md` and docs/components.md gain the items above; CHANGELOG under `## 0.0.2-beta2`,
  each entry stating what a user sees differently.
- Branches `widget/*` may appear in the repo while you work (other contributors building leaf
  widgets); leave them alone this version.
