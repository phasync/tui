# 0.0.3-beta1: one file, utility classes, markup in text

Phase 3 starts: SPEC section 9. This version does 9.1, 9.2 and 9.3 in full; 9.4 (themes),
9.5 (cookbook) and 9.6 (the dashboard) are the next version, so build what this version adds so
that a theme is only a stylesheet on top. Everything from 0.0.2 keeps working. Item 4 is a defect
from use; 5 is small.

## 1. One file, no class (SPEC 9.1)

`Tui::run()`, `Tui::inline()` and `Tui::headless()` exist as runners; now they take an element, a
component or a closure returning either. A closure is a component: it renders again whenever
state it reads changes (a `State` read during the render subscribes it, the way a component's
`$this->state` does), has no key handling of its own, and quits on Ctrl-Q when nothing binds it.
Element content may be a closure (`new Text(fn() => "...")`, a `Box` title, a `ProgressBar`
value) so a single element is dynamic without rebuilding the tree around it. The example in 9.1
(a `State`, a `go()` loop, `Tui::inline(fn() => new Column([...]))`) must run as written, in
inline and full-screen mode, and under `Tui::headless()`. Tests: the example under
`Tui::headless()` at two sizes; a closure root with a `State` changed from a coroutine renders
again and only then; a closure `Text` inside a static tree updates without the parent rendering
(count renders); Ctrl-Q quits a closure root; a thrown exception in the closure ends the program
visibly as in 0.0.2.

## 2. Utility classes (SPEC 9.2)

`class:` resolves the Tailwind vocabulary exactly as 9.2 lists it: spacing (`p-`, `px-`, `py-`,
`pt-/pr-/pb-/pl-`, `gap-`, `m-`), borders (`border`, `border-round/double/heavy/ascii/none`,
`border-{color}`, `title-center`), colours (`text-{color}`, `bg-{color}` with Tailwind families
and shades, `[#hex]`, palette index, 16 names), text (`bold`, `dim`, `italic`, `underline`,
`strike`, `inverse`, `text-left/center/right`, `truncate`, `wrap`/`nowrap`), size and layout
(`w-{n}`, `h-{n}`, `w-1/2`, `w-full`, `grow`, `grow-{n}`, `shrink-0`, `items-*`, `justify-*`,
`hidden`, `flex-row`/`flex-col` on a container), and the variants `focus:`, `hover:`,
`disabled:`, `selected:`, `sm:`, `md:`, `lg:` (root width under 60, under 100, 100 and more).
Cascade: stylesheet rules, then utility classes in the order written, then `style:`. Every
utility maps to a stylesheet property, so a theme can set the same things; an unknown class is
ignored silently (it may be the program's own selector), but `Stylesheet::explain($element)` or
an equivalent tells a programmer what resolved. Truecolour degrades to 256 or 16 colours in one
place in the device by nearest colour (FakeTerminal configurable), and the Tailwind palette ships
as data, not as 220 constants. Tests: each family with one or two classes; the cascade order;
`flex-col md:flex-row` switching at a resize across 100 columns; a `[#ff5500]` on a 16-colour
device becomes the nearest name; `focus:bg-sky-800` on a focused and an unfocused element.
docs/styling.md (or the existing page) gets the full table.

## 3. Markup in text (SPEC 9.3)

`Text`, `Span`, `Box` titles, `Button` labels, `Table` cells, `Help`, `Log` lines, `Select` items
and `Markdown`'s plain-text labels accept symfony/console markup: `<b>`, `<i>`, `<u>`, `<dim>`,
`<s>`, `<fg=red>`, `<bg=blue>`, `<fg=#ff5500>`, `<span class="text-sky-400 bold">`, closed by
`</>` or the matching tag, `\<` escapes, `Text::raw($s)` for none. Parsed once per string (cache
by string), rendered through `write()` like everything else; the width of a marked-up string is
the width of its visible text, so layout and truncation see no tags. Malformed markup (unclosed
tag, unknown name) renders the text as written, never throws. Tests: each tag; nesting with the
innermost winning and attributes adding up (the beta3 composition rule); escaping; truncation of
a marked-up label in a narrow Box; a `Log` line with markup; a Markdown code span that contains
`<b>` stays literal.

## 4. A Table with `'auto'` columns re-measures every row on every cursor move

A `Table` of 10,000 rows with the default `'auto'` column widths: holding Down (a key every 30 ms)
costs 59% of a core and frames overrun the key interval; with fixed widths 32% and every key
answered in time. The widths are recomputed over all rows on each selection change. Required:
auto widths are computed when the rows change, not when the cursor or the scroll moves, and a
cursor move on 10,000 rows costs the same as on 100. Test: count rows measured across 200 cursor
moves (one pass at most, after the rows were set), and the timing with headroom as in beta5.

## 5. Small, from use

- Nothing has the focus at start unless the program calls `focus()` in `render()`; the root gives
  the focus to its first focusable component when the program did not say otherwise, and Tab/
  Shift-Tab walk focusable components by default when nothing binds them.
- `ProgressBar` with a value that changes below one cell paints again for nothing; it compares
  what it would draw, so `setValue()` from a 10 Hz coroutine costs nothing when the bar looks
  the same.
- `detach()` with a `Terminal` child still running and no quit path hangs; `detach()` stops the
  tree's children (the documented kept-alive `stop()`), then returns.
- Inline mode with more live rows than the terminal has: the region is the terminal's height and
  the rows above are clipped; document it (the root's own scrolling applies: a `ScrollView` or a
  `Column` of the last N rows), and make sure the clipped rows are not painted at all.
- `Select`'s callback names (`onActivate` vs `onChange`) are stated in one line in docs/widgets.md
  with when each fires.
- CHANGELOG under `## 0.0.3-beta1`, each entry stating what a user sees differently; README gets
  the 9.1 example as its first code block.
