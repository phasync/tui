# 0.0.3-beta7: the two latency shapes the bench does not see

Everything from 0.0.3-beta6 keeps working. Both items are performance, both measured by a
program as `press()` + `settle()` around `hrtime()` on a FakeTerminal, and both are shapes the
library's own bench reports as fast (2.4 ms and "like a Text tab") while the program sees 20-45
ms. So this version starts by putting the two programs below into `tools/monitor-bench.php`
**verbatim** as modes, reproducing the numbers, and only then fixing. The old modes stay. If this
round finds nothing new, 0.0.3 is done.

## 1. Tab inside a `ScrollView` of rows costs O(rows)

The program, exactly:

```php
$s = new State(['sel' => 0]); $btns = [];
$screen = function () use ($n, $s, &$btns) {
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $btns[$i] ??= [new Button('Pause', fn() => null), new Button('Cancel', fn() => null)];
        $rows[] = new Row([new Text("file$i"), ...$btns[$i]], class: 'gap-1', key: "r$i",
            onFocus: fn() => $s->sel = $i);                  // also measured without onFocus
    }
    return new Column([new ScrollView(new Column($rows)), new Text(fn() => "sel {$s->sel}")]);
};
$t = Tui::headless($screen, 160, 50); $t->settle(); fiber\sleep(0.3);
// 20 times: $a = hrtime(true); $t->press('tab'); $t->settle(); record (hrtime(true) - $a) / 1e6; fiber\sleep(0.05);
```

Median: 2 rows 4.6 ms, 50 rows 22.4-22.9 ms, 200 rows 42.7-44.6 ms (max 80), with or without
`onFocus:`; renders of the closure over 20 Tabs: 2-3, so the render is not it. The beta6 bench
(`keys` mode) says 2.4 ms for what it measures; the differences from it are the `ScrollView`
around the Column, the `key:` on each row, `gap-1`, and the footer `Text` closure reading the
State. Required: this program, 200 rows, within a few ms of 2 rows; find which of those four
makes the per-row cost (likely the ScrollView laying out or painting all rows of its content on
every focus change instead of only the visible ones and the two rows that changed, or the keyed
rows being re-matched), and fix the cause, not the shape. Test: this shape as a timing test with
headroom at 2, 50 and 200 rows, and a count of rows laid out and painted per Tab (two).

## 2. A tab switch in a realistic layout costs 25-30 ms at 120x40

Reduced: `Tabs` with `Row([Markdown, Box(Select)])` in one tab and `Box(Terminal)` in the other,
inside `Column([Row([Tabs, Box(tools)]), Box(Editor), Text(closure)])`, no streaming, nothing
changing. Alt-1/Alt-2 (`press('alt+1')` then `text()`): 120x40 26-28 ms median, 80x24 18.5-19.5
ms. The reduced Tabs+Markdown+Text case went from 23 to 15 ms in beta6 while a Text tab is 5 ms,
so Markdown still costs three times a Text on show, and the full layout adds the rest. The cost
grows with the screen area, not with content. Required: a tab switch at 120x40 in this layout
under 15 ms median, and a Markdown tab costing what a Text tab costs; profile the show path
(parsing the shown surface's escape sequences again at each surface level is the known suspect
from DEVNOTES: a window shown again whose cells the host already has must not be re-parsed, and
the Markdown must not be re-laid-out when its size did not change). Test: the reduced layout at
80x24 and 120x40, ten switches, with a count of Markdown layouts (zero after the first show) and
the timing with headroom.

## 3. Small, from use

- A `Table` with markup cells and 6 columns costs 18 ms per key against 8 ms for 2 plain columns
  at 120x40 (not growing with rows): cache the parsed markup per cell string (beta1 said markup is
  parsed once per string; check that Table cells go through that cache) and paint only the rows
  that changed.
- Cookbook screens have no way to choose the theme under `TUI_HEADLESS`; `TUI_THEME` env var.
- A parent's `bindings()` take Ctrl-D before a focused `Terminal`; document in widgets.md that
  a Terminal needs the keys a shell uses, and list which keys a program should avoid binding
  around a Terminal (or make non-`always` parent bindings yield to a focused Terminal).
- When all 50 bars move in the same frame it costs 28 ms (the 90th percentile of a Tab that lands
  on it): the closure re-render plus re-parsing the escape sequences at each surface level; the
  second part is the same cause as item 2.
- CHANGELOG under `## 0.0.3-beta7`, each entry stating what a user sees differently.
