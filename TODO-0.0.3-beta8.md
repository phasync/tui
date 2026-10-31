# 0.0.3-beta8: compositing becomes blitting, one diff at the end

Everything from 0.0.3-beta7 keeps working. Item 1 is SPEC section 3 as amended ("Compositing is
blitting", "One diff, at the end"): a change of the layer, not a patch, and the whole of this
version. Item 2 is a defect with the exact shape from use; 3 is small.

## 1. Blit, then diff once (SPEC 3)

Today a host repaints a changed child by serialising the child's changed cells to ANSI and
parsing them into its own cells, at every nesting level, and each level keeps a frame to diff
against. Replace it as the SPEC says:

- `Surface::copyArea(int $x, int $y, int $w, int $h): Area` and `Surface::drawArea(Area $a, int
  $x, int $y): void`. An `Area` is rows of two parallel arrays, graphemes and interned style ids
  (style ids are small ints from one process-wide intern table so comparing cells is `===`;
  hyperlink id folds into the style). Wide characters are clipped to whole cells; an orphaned half
  becomes a space. `drawArea` clips to the destination. Implementation: `array_slice`/
  `array_splice` per row; nothing cleverer in this version.
- Each frame the root composes one offscreen Surface by blitting every visible window in z-order
  with its viewport clipping, overlays and the dialog shade (a style transform applied during the
  blit); containers that only arrange have no window (beta7 did this already). Then the device
  diffs that frame against the one it last wrote and emits ANSI for the changed runs only.
- Remove what the ladder needed: per-viewport `frame`s, `$covers`, `forgetRows`, the save/restore
  wrapper around every paint, damage propagation between levels, the deferred blanking. A damage
  anywhere means "recompose"; recomposing is cheap.
- The diff compares visible identity (SPEC 3): blank cells ignore fg/bold/dim/italic; a blank with
  default bg equals an empty cell; the ANSI for a blank run sets no fg. Intern blanks so equal
  visible identity gives the same id.
- ANSI stays the only way to render into a Surface. Hosted programs (pty panes) are unchanged
  except for how their cells reach the screen. Inline mode: the live region is the same frame
  diffed against the terminal's bottom rows; a frozen row is a row of cells serialised once.

Tests: a property test that the blit composite of random window trees (nesting, overlaps,
z-order, partial clipping, wide chars at edges, shadows) equals the cell-for-cell result of the
pre-beta8 ANSI composite (keep the old compositor in tests/ as the oracle until the test is
stable, then delete it); the existing replay and byte-count tests unchanged; two frames with the
same visible glyphs and attributes emit zero bytes; compositing a 160x50 frame with a tree ten
levels deep costs about 1 ms and the same with every surface changed; the beta7 bench modes
(`scroll`, `tabs`, `keys`) all improve, and the frame where all 50 bars move at once (37 ms in
beta7) is under 8 ms. docs/surface.md Performance table and docs/hosting.md are rewritten for the
new model; CHANGELOG says what a user sees differently (latency; any byte-stream difference).

## 2. `Tui::quit()` from a `Tui::go()` job under `Tui::headless()` is a fatal

Exactly:

```php
fiber\run(function () {
    $t = Tui::headless(fn() => new Text('hi'), 20, 3);
    Tui::go(function () { while (true) { Tui::sleep(0.05); } });
    Tui::go(function () { Tui::sleep(0.2); Tui::quit(); });
    $t->settle();
    Tui::sleep(0.5);
    $t->detach();
});
```

dies with "Uncaught phasync\Tui\Quit: The program has ended" from the first job's `Tui::sleep()`.
The same under `Tui::inline()` on a pty, and a quit by bound key under headless, work. Required:
`Quit` thrown by `Tui::sleep()` in a `Tui::go()` coroutine ends that coroutine quietly under every
runner, headless included, and `fiber\run()` returns. Test the shape above headless and inline.

## 3. Small, from use

- `Confirm::ask()` ignores `y`/`n`; accept them (and Enter/Escape) as the docs for Confirm say or
  document that they are not bound.
- The fiber-interop version warning is one line, once per process, on stderr; keep it until the
  versions below are required.
- Keeping per-row component instances across renders (`$bars[$i] ??= ...`) is something every
  program writes itself; the cookbook gets one recipe "rows that keep their widgets" showing the
  idiom and `key:`.
- When fiber-interop/api 0.3.2 and fiber-interop/phasync 0.4.2 are on Packagist (check at the
  start of this version), require `^0.3.2` / `^0.4.2`, drop the dev pins and the version warning,
  add `conflict` entries for 0.3.1/0.4.1, and let `Tui::sleep()` use `fiber\cancel()` for ending
  `Tui::go()` coroutines (the `Quit` exception stays as the reason).
- CHANGELOG under `## 0.0.3-beta8`.
