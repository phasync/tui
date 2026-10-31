# 0.0.2-beta3: inline mode, the Markdown widget, and four defects from use

Everything from beta2 keeps working. Items 1 and 2 are the version; 3 merges a contribution; the
rest is from use.

## 1. Four defects, each with a test in the exact shape

- **An overlay whose content shrinks keeps its old size.** An `Editor` with `complete:` opens a
  popup with four candidates; typing two more letters filters it to one, and the popup stays four
  rows tall: the one candidate on its old top row, three blank rows below it painted over the Box
  border under the popup. SPEC 8.5 now says it: an overlay's desired size is measured every frame;
  when it changes, the overlay is placed again and the cells it no longer covers are repainted by
  what is under them. The same for growth (a dropdown whose list grows) and for a Dialog whose
  content changes size (a validation message appearing).
- **A Dialog given `with(width:, height:)` draws no content and takes no input.** The frame and
  the buttons are drawn at the given size; the content (Editors, Texts) is not, nothing has the
  focus, typed text goes nowhere. Without the size the same dialog works. Fix, and test a sized
  dialog with a multi-line Editor inside: content drawn, first field focused, typing lands.
- **A container's auto size comes from its children.** A `Row` of one-line children (`maxLines: 1`
  Editors, a dropdown `Select`) without `height:` takes a `1fr` share of the Column (nine rows on a
  20-row terminal), while a single Editor placed directly in the Column is one row. Rule: a
  container's `'auto'` size is the sum of its children's measured sizes along its axis and their
  maximum across it; a container is `1fr` only when one of its children is unbounded in that
  direction. A toolbar `Row`, a `Column` of one-row `Text`s and a `Column` of row components
  (each measuring one row) need no explicit sizes. Test these three shapes.
- **A Form inside a Dialog cannot be submitted.** `new Dialog('Add', new Form(...), ['Add' =>
  'add', 'Cancel' => null])` with `accept()`: Tab reaches `[ Add ]`, Enter and Space do nothing;
  Enter in a field does nothing visible; `accept()` is never called. The same dialog with a plain
  `Column` as content answers 'add'. Required: Enter in a field submits the Form, which validates
  and, if valid, answers the dialog with its first button's value through `accept()`; the dialog's
  buttons press as they do without a Form; a Form's `onSubmit` and the Dialog's `accept()` are
  wired so that docs/widgets.md "A form in a dialog" works as written. Test both paths.

## 2. Inline mode (SPEC 4.1)

Implement section 4.1 as written: `Tty::inline()` and `FakeTerminal::inline()`; the root's visible
rows are the live region at the bottom of the terminal's normal screen, the root's scrollback is
written once into the terminal above the region as rows leave the top; `println()`; relative cursor
motion only; width change repaints, content height change scrolls; `keep:`; restore on exit. The
tests the spec names (five bars and a total; twenty process lines on a 10-row terminal finishing in
random order, each frozen as it last looked; `println()` between frames; byte-stream replay into a
Surface shows the same frozen lines and region), plus the same tree run fullscreen and inline from
one source. docs/terminal.md gets a section; `examples/inline-progress.php`.

## 3. Merge the Markdown widget

Branch `widget/Markdown` (one commit, by a contributor, built on beta2) adds
`src/Component/Markdown.php`, `src/Internal/MarkdownBlocks.php`, tests, docs and an example. Review
it as the maintainer: merge it onto this version, fix what does not meet the library's rules (it
must paint through `write()` only, which it does; check its styling, scrolling and focus against
the other widgets), keep its tests green, and move its CHANGELOG entry under this version. Its
author reported gaps in the contract; the ones to act on here:

- **OSC 8 hyperlinks** are dropped by the emulator, so a widget cannot emit them. Keep them: a
  cell carries its hyperlink (id and URI) through the Surface, the compositor forwards them, and
  the device emits them when the host terminal supports them; `TerminalDevice` answers
  `supportsHyperlinks()` (Tty: from the environment and the terminal's answers, conservatively;
  FakeTerminal: configurable). Tests: a hyperlink written into a Surface survives a replay of the
  byte stream; a hosted `Terminal` pane running a program that prints OSC 8 shows clickable links
  on a supporting device and plain text otherwise.
- **Scrolling for self-drawing components** is re-implemented per widget (Table, Log, ScrollView,
  Markdown each have their own offset, wheel handling and follow-tail). Give them one documented
  helper (scroll state, wheel, keys, `scroll` event, follow-tail, an optional scrollbar part) and
  use it in at least Log and Markdown.
- **Style parts that apply together** (strong inside h2, a link inside a quote): the stylesheet
  composes parts, attributes adding up and the innermost colour winning, instead of each widget
  merging by hand.
- Document in docs/surface.md that writing into the last column with autowrap off leaves the
  cursor on that column, so `\e[K` erases what was just written (xterm behaviour, not a bug), and
  in docs/components.md what a widget's `...attributes` and props conventions are exactly.

## 4. Small, from use

- An `always` binding that opens a dialog opens a second one when pressed twice; document the
  pattern, or give `Component::dialog()` a way to say "one at a time" (the existing one gets the
  focus).
- Overlays are hosted on the root, not on "the nearest Layers"; the spec now says so.
- CHANGELOG under `## 0.0.2-beta3`, each entry stating what a user sees differently; README tour
  gains the inline example and a Markdown pane.
