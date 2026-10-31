# 0.0.2-beta5: the render pass is cheap, and three defects from use

Everything from beta4 keeps working. Item 1 is the version; 2 and 3 are defects with the exact shapes
from use; 4 is small. This is the last 0.0.2 round if nothing new is found; 0.0.3 is SPEC section 9.

## 1. Rendering an element tree costs far too much

Measured on FakeTerminal 120x60, one component whose `render()` returns a `Column` of 50
`Row([Text, Text])` and re-renders at 10 Hz (a counter changes in one Text): about 44 ms of CPU
per frame, so 44% of a core while idle, and 100% of a core for a real 50-row monitor (rows holding
a ProgressBar, two Texts and two Buttons, each row a component with its own coroutine). One row
costs about 4 ms per frame; the cost grows linearly, about 0.5-1 ms per two-Text Row. It does not
depend on the terminal size and the bytes written are tiny (4 KB/s), so it is the
measure/layout/paint pass, not the diff or the output. Key-to-screen latency of that monitor is
47 ms median with spikes to 97 ms.

Required: a frame of a few hundred elements, most of them unchanged, is a matter of one or two
milliseconds, so the monitor above is a few percent of a core and its key latency well under
20 ms. Profile the pass (measure, stylesheet matching, ComputedStyle construction, Element
allocation, ANSI generation per cell, Surface parsing) and remove what is paid per element and
per frame without need. Things that usually matter: compute a style once per selector match, not
per element per frame; emit one escape sequence per run of equal style, not per cell; do not
re-parse what did not change; a component whose own state did not change and whose area did not
move keeps its last paint. Also: a child component re-rendering must not make its parent
re-measure the whole tree when its size did not change.

Tests: a timing test with a generous bound (the 50-row tree above re-rendered 100 times, total
CPU under a stated budget that leaves 10x headroom on this machine), a test counting the work
(how many elements are measured and painted when one Text in one row changes), and
docs/surface.md "Performance" gains the render budget in numbers: elements per frame, and what
to do when a tree is big (components per row, state in the row).

## 2. Tabs loses the focus when a tab's content is an element wrapping components

`Tabs` with the focus inside the content of tab B (a `Terminal`). Ctrl-Tab to tab A whose content
is `new Row([$select, new Box($tree)])`, an element holding components. Afterwards nobody has the
focus: `$tabs->hasFocus()` false, `inContent()` false, the Select does not have it; Ctrl-Tab,
Right and Alt-n then do nothing, the user is stuck until a mouse click or Tab. With `'content'
=> $select` (the component itself) it works. Required: the focus follows into the first focusable
component inside the new tab's content, whatever element tree wraps it; if the content has no
focusable component, the Tabs bar takes the focus. Same for the mouse and for `select()`. Test
both content shapes, and the round trip B -> A -> B by key.

## 3. A dialog taller than the terminal squeezes its boxes

A fixed-size `Dialog` (50x24) holding a `Form` with a dropdown `Select` in a `Box` (3 rows:
border, "Low ▾", border) on an 80x14 terminal. The dialog's scroll area shows the Priority Box as
its top and bottom border with no row between them, so the focused field is invisible and Down
opens nothing; at 80x16 the Title Box degrades to one border line; at 40x12 the focused field is
not shown at all. The same without a resize. Required: content that does not fit a ScrollView is
cropped, never squeezed (a 3-row Box stays 3 rows and scrolls); giving a field the focus scrolls
its whole Box into view; a dropdown opened from a scrolled field is anchored under the field's
visible row. Tests at 80x14, 80x16 and 40x12 with the focus walked through every field, and the
resize from 80x30 to 80x14 with the list open (the list closes or stays anchored; nothing is
squeezed).

## 4. Small, from use

- `Tui::inline($root)` gives the root no way to reach its device, so `println()` and `freeze()`
  need a hand-built `Tty::inline()`; give components access to their terminal device (or the root
  `println()`/`freeze()` methods that forward) so a runner-started program can log and freeze.
- `freeze($row)` needs a short sleep after the last `setValue()` for the final render to have run;
  `freeze()` must render pending state first so the frozen row is the final one.
- A component created with `$this->child('log')` and passed as a tab's content makes
  `$this->child('log')` return null afterwards. Either keep the parent's handle valid or give
  `Tabs` a documented accessor for a tab's content; say in docs/widgets.md who owns it.
- A user component declaring `public function key()` silently overrides `Component::key()` and
  fails at the first bound key press. Make the names the library relies on `final`, or fail at
  construction with a message naming the clash.
- docs/components.md: a `go()` coroutine started in a parent re-renders the parent at every
  step; say plainly that a row's coroutine belongs in the row component.
- Once fiber-interop/api and fiber-interop/phasync newer than 0.3.1 / 0.4.1 are tagged, require
  them; until then the dev pins stay.
- CHANGELOG under `## 0.0.2-beta5`, each entry stating what a user sees differently.
