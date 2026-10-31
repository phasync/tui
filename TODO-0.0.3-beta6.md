# 0.0.3-beta6: a key costs the same at 200 rows as at 2, and three defects from use

Everything from 0.0.3-beta5 keeps working. Item 1 is performance, measured the way a program
measures it (not the way the bench does); 2-4 are defects with the exact shapes from use. If this
round finds nothing new, 0.0.3 is done.

## 1. Tab still costs O(rows), measured as a program sees it

`tools/monitor-bench.php keys` says 8.9 ms. A program under `Tui::headless()` at 160x50 doing
`$t->press('tab'); $t->settle();` around a `microtime()` pair sees, for rows of `Text` + two
`Button`s with `onFocus:` only, no coroutines, no bars: 2 rows 5.4 ms, 50 rows 30.7 ms, 200 rows
49 ms. The full 50-row monitor with bars at 10 Hz: 58 ms median (max 122); paused 51 ms. So the
cost still grows with the row count, and whatever the bench measures is not what a program pays.
Required: measure exactly as above (press + settle, the whole frame including compositing and
the FakeTerminal's parse), find where the per-row cost is now (candidates: the focus walk,
`onFocus:`/`hasFocus()` re-rendering closure rows, the Column measuring every row, the Surface
diff over the whole screen, `settle()` itself waiting a full frame), and bring 200 rows to within
a few ms of 2 rows, with the full monitor under 20 ms median with the bars moving. Make the bench
measure press + settle the same way so the two numbers agree, and keep the count test from beta5.

## 2. Switching to a tab that holds a Markdown costs 18 ms more than any other tab

`Tabs`, 120x40, one tab's content a kept, **empty** `Markdown(tail: true)`: Alt-n to it is
23 ms median; the same switch to a `Text` or a `ScrollView(Text)` tab is 5 ms; the cost grows
with the screen size (80x24: 13-17 ms). The switched-in Markdown is laid out again and its
viewport rebuilt on every show. Required: showing a kept component again whose size did not
change reuses its layout and its surface; a tab switch costs the same for Markdown as for Text.
Test: count Markdown layouts over ten switches (one), and the timing with headroom.

## 3. The selected element in a `ScrollView` cannot be scrolled into view

A one-file program: a lane `ScrollView` of 200 `Text` cards with `selected:` set from a `State`
by arrow keys bound in `keys:`. The selection moves (highlight changes) but the view stays at the
top; `ScrollView` follows only the focus, and an element has no `focus()`; `scrollTo($row)` needs
the instance and the view height, which are not public. Required: an element in the `selected`
state inside a `ScrollView` is scrolled into view when it becomes selected (the same rule as
focus: minimal scroll), and elements with `onKey:` can be given the focus from a program
(`Tui::focus($element)` or a `focused:` argument driven by state, decided here and documented).
Test: 200 cards, select 50 → visible; select 0 → top; selection moves one row at a time at the
view's edge scrolls one row.

## 4. The `Help` line shows the tab bar's hints while a fresh `Tree` has the focus

The admin shape: an owner's `render()` calls `setTabs()` every render with `new Row([$keptTable,
new Box(new Tree(...))])` as a tab's content, and a plain `new Help()` (no `for:`) at the bottom.
Tab to the fresh Tree: `hasFocus()` is 1 but the Help line is the tab bar's ("left previous tab
right next tab …"). With the Tree kept via `child()` the Help line is right. `Help(for:)` was
fixed in beta5; `new Help()` with no argument must follow the running instance too. Test both.

## 5. Small, from use

- Alt-1..9 switch tabs only while the focus is inside the `Tabs`; a program with one `Tabs` on
  screen expects them anywhere. Make them `always` bindings when the Tabs is the only one on
  screen, or document the one-line `keys:` a program adds.
- An element-level `'quit'` binding fires only while the focus is inside the element; say so in
  docs/components.md next to `onKey:`.
- Freezing a row still needs `$bar->device()->freeze($bar->surface)`; offer `Tui::freeze($component)`.
- CHANGELOG under `## 0.0.3-beta6`, each entry stating what a user sees differently.
