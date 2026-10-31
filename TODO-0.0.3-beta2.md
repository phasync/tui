# 0.0.3-beta2: good by default, the cookbook, the sixty-line dashboard

Everything from 0.0.3-beta1 keeps working. Items 1-3 are SPEC 9.4-9.6; 4 is a defect with the
exact shape from use; 5 closes gaps in the variants, with the SPEC amended (9.1 example, 9.2
variants and breakpoints); 6 is small.

## 1. Good by default (SPEC 9.4)

`Stylesheet::default()` makes a program with no styling look like a modern tool: rounded borders,
one accent colour, the focused window's border in the accent, dimmed titles elsewhere, a visible
cursor only where text is edited, `Select` rows in inverse, dialogs with a shadow. Three themes
`dark`, `light`, `mono`, each a stylesheet, chosen by `Tui::run($root, theme:)` (and `inline`,
`headless`) or automatically from the terminal background (OSC 11 on a Tty, dark when there is no
answer within a short time, never blocking start-up; `FakeTerminal` takes `background:`). A
program's own stylesheet refines the theme (theme rules first). Every widget takes the common
things as constructor arguments (`Box(title:, class:)`, `Column(gap:, padding:, align:)`,
`Text(align:)`, `Button(label)` accepting a closure like `Text`) so no stylesheet is needed for
a first version. Tests: the same tree under the three themes differs only in colours and
attributes (screens compared as text); OSC 11 answer light/dark/none under FakeTerminal; a
program rule overrides a theme rule; the `mono` theme emits no colour sequences at all.

## 2. The cookbook (SPEC 9.5)

docs/cookbook.md: ten screens, each under forty lines, no class unless the screen is about one:
a progress runner, a log tail with filter, a form with validation in a dialog, a two-pane file
list with a preview, a tabbed shell host, a data table with a search box, a chat pane with a
growing editor, a settings list with checkboxes and a select, a live chart from a coroutine
(drawn with block characters in a `Text` closure), a Markdown viewer with a table of contents.
Each one runs by a test under `FakeTerminal` at 60x20 and 160x50 and is checked by a screen
comparison with no exception and the expected words on screen. The README opens with the
dashboard (item 3) and links the cookbook.

## 3. The dashboard (SPEC 9.6)

`examples/dashboard.php`, under sixty lines, no class: a header row with a `Spinner` and a
title, three `Box`es side by side with a `ProgressBar` and a `Text` each, updated by three
coroutines at different rates, a `Log` that receives events from them, a `Markdown` pane with
release notes, a `Help` line; styled with utility classes only, stacked vertically under 80
columns (`md:` from 80, see 9.2 as amended), good-looking in all three themes, correct under
`FakeTerminal` at 60x20 and 160x50, flicker-free at 10 Hz (frames differ only in the cells that
changed), and the same program runs in inline mode with `Tui::inline()` showing the three boxes
only. The README shows it (a text screenshot is fine; a `tools/screenshot` that renders a
headless frame to SVG is welcome if cheap).

## 4. `freeze()` of a component's surface writes the wrong row after an earlier row left the tree

Inline mode, a `Column` of job rows and a total. Job a finishes: its bar is set to 1.0, the
coroutine sleeps a frame, calls `$bar->device()->freeze($bar->surface)`, then a `State` change
makes the tree omit row a. Job b does the same later; its frozen line is the **total** row (b at
50%), not b at 100%; with eight jobs, rows are frozen as their neighbours and at 94%/98%. The
surface position used by `freeze()` is stale after rows above it left the tree. Required:
`freeze()` freezes the rows that component shows in the frame as it is at that moment (lay out
pending changes first, as beta5's `freeze()` renders pending state), independent of what left the
tree before. Test the exact shape with two jobs and with eight, and the replayed byte stream.

## 5. Variants on elements (SPEC 9.2, amended)

- `selected:` on a `Text` or any element: an element enters the `selected` state through
  `selected: true` (or a `state: ['selected']` argument), so `new Text('<b>Card</>', class:
  'selected:bg-sky-700 selected:bold', selected: $id === $s->sel)` is how a one-file program
  marks the selected card. Document on styling.md next to the variants.
- `hover:` on a `Box`, `Text` or any element: the element under the pointer is in the `hover`
  state (pointer tracking requested by the presence of a `hover:` class or `:hover` rule, as
  beta4 did for parts), and `hover:border-emerald-500` changes that Box's border. Border colour
  set on a component applies to the Box it renders when the Box sets none.
- Breakpoints become Tailwind's min-width semantics: `sm:` from 60 columns, `md:` from 80,
  `lg:` from 100, `xl:` from 140, later overriding earlier; `hidden md:block` shows at every
  width from 80 on. Update styling.md and every example and test that used the old ranges.
- A `focus` event (or `onFocus:`/`onBlur:` closures on components and elements) so a class-free
  program can react to the focused row without polling `hasFocus()`.

## 6. Small, from use

- `Spinner` labels take markup like every other label; the CHANGELOG lists Spinner among them.
- `Button(label)` accepts a closure like `Text`; `$b->label = ...` keeps working.
- An error inside a closure content (a `Log::append()` on a Log not in a tree, a `TypeError`)
  shows the exception class and message, not a truncated "Error:"; and `Log::append()` before the
  Log is in a tree keeps the line for when it is.
- A `ProgressBar` value closure is re-evaluated each frame; document that rounding in the closure
  keeps the draw cheap, or compare the rounded cell value before drawing so it does not matter.
- `Tabs`: a tab closed by the user that `render()` keeps listing comes back; document the pattern
  (state for the open tabs) or make `Tabs` remember closed keys until the list changes.
- `Table::setItems()` with the same rows each render must not re-measure (compare by identity
  or hash), so an app that rebuilds its row array per render is not punished.
- CHANGELOG under `## 0.0.3-beta2`, each entry stating what a user sees differently.
