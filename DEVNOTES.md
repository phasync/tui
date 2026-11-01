# DEVNOTES

Working notes for whoever continues this library (design decisions, what is left). Users read
README.md and docs/.

## Status (0.0.4-beta1; TODO-0.0.4-beta1.md)

- [x] 1. emulator: Kitty graphics + iTerm2 inline images (src/Surface/Graphics.php), Image,
      ImageFragment, Style::$image; tests/Surface/ImagesTest.php; blit property test with images
- [x] 2. device: probe (Kitty query + CSI 16 t), images(), kitty placements / iterm2 rectangles,
      quarter-block fallback, inline freeze; tests/Terminal/ImagesTest.php
- [x] 5. closing 0.0.3: Help lines, Ctrl-B Ctrl-Q, goodbyes, Dialog/Confirm timeout, Terminal
      exit event + element attributes, fps, MIT, charm/parsing removed, CHANGELOG 0.0.3 summary;
      tests/Release/Closing03Test.php, tests/Examples/ExamplesTest.php
- [~] branches: widget/* deleted locally (their worktrees removed first). NOT deleted on origin:
      that is a push, and this work pushes nothing. To do: `git push origin --delete` each
      `widget/*` on origin (`git ls-remote origin 'refs/heads/widget/*'`).
- [x] 3. Canvas element (src/Component/Canvas.php, CanvasContext.php); tests/Widgets/CanvasTest.php
- [x] 4. graphs.php, README Graphics paragraph. It runs, but shows no axes, no cpu line and a
      tiny photo until phasync/canvas fixes the gaps below; nothing in the example changes then.
- [x] Sixel (SPEC 10.4's third protocol; asked for by Frode in continuation 3: "build the rest
      into tui for rendering to the protocols we support and are able to detect", the canvas
      fixes coming separately): emulator (DCS q, src/Internal/Sixel.php decode), device
      (DA1 `4` detection, `sixel` painting through GD, encode), FakeTerminal/Tui::headless
      `images: 'sixel'`, tmux probed instead of forced to none; tests in
      tests/Surface/ImagesTest.php (describe Sixel), tests/Terminal/ImagesTest.php,
      tests/Widgets/CanvasTest.php; fixture tests/fixtures/images/sixel.bytes (ImageMagick).
- Left, outside tui: the push (`git push origin --delete widget/Markdown widget/Tabs
  widget/Tree`, and the tag) and the phasync/canvas gaps below. Nothing else.

### phasync/canvas gaps (at 7643f60, 2026-10-31; tests skip through canvasGap() and run once fixed)

Found by tests/Widgets/CanvasTest.php; not worked around in tui (no 2D context of our own):

1. `toDots(w, h)` at the backend's own size segfaults (GD 2.3.3, `imagescale` with
   IMG_BILINEAR_FIXED). The element always rasterises at cells x cell size in pixels, so toDots
   only ever scales down; that is a design choice that also makes markers agree, not a
   workaround, but the segfault is upstream's.
2. `stroke()` with `lineWidth <= 1` (in user units) draws with `imageline` on the untransformed
   points: a line of a dot or two lands at pixel coordinates (top-left, tiny). `lineWidth > 1`
   is transformed (as a polygon) and is in user units as in a browser. `strokeRect()` ignores
   the transform at every width. (graphs.php: the axes and the cpu line are missing.)
3. `clearRect()` does not clear (GD alpha blending is on, so a transparent fill blends to
   nothing) and ignores the transform.
4. `drawImage()` of a path or of bytes calls `imagecreatefromfile()`, which does not exist in
   ext-gd; `drawImage()` of a `GdImage` ignores the transform for the destination rectangle
   (graphs.php: the photo comes out a few pixels in the corner).
5. `fillText()` ignores the transform and uses GD's built-in font 1. Not needed: the element
   puts text in cells as characters (CanvasContext::fillText), which is the TUI's job.
6. `toDots()` scales bilinearly, so an edge bleeds into the next dot (about one dot); tests
   compare braille against pixels with that tolerance. A box filter (or nearest at integer
   ratios) would be exact.
7. There is no way to make a `Canvas` with a `Context` subclass (the context is created inside),
   so the element constructs the backend itself (`Canvas::$backendClass`, else Gd, else Imagick)
   and `CanvasContext extends Context` with it. A factory or a `Context` that takes a backend in
   a public constructor (it does) is enough; a `Canvas::fromBackend()` would make it clean.

### Design decisions (Canvas)

- The raster is cells x cell size in pixels for every marker, glyph markers downscale with
  toDots(): a drawing covers the same cells on braille and on pixels (the test compares them).
- Units are cells: CanvasContext's base transform is the pixel scale, and it keeps its own
  matrix (in cells) beside the backend's for fillText placement; setTransform() takes cells.
- Text is glyph text in the cells (one cell high, textAlign/textBaseline), on every marker.
- `pixels` writes one Kitty image (`Ansi::image`) after `ESC[2J`; the device does the rest, so
  a canvas in a nested window or a pane costs placements only. Writes nothing when the bytes
  equal the last drawing's.
- `auto` is resolved on every render (`markerInUse()`), and the element asks to render again
  after the device's probe answers (`whenProbed`), because images() is only known then.

## Design decisions (Sixel, 0.0.4-beta1 continuation 3)

- **Decoding** (src/Internal/Sixel.php) is a loop over the DCS body in PHP (a payload format
  like Kitty's base64, not a terminal sequence, so not in the grammar; the grammar only hands
  the DCS text to `Emulator::dcs()`, which takes `^[0-9;]*q`). A pixel takes its register's
  colour when it is painted; each band becomes RGBA strings when `-` ends it (a 1000x750
  picture: 0.26 s, 10 MB peak; holding every pixel in arrays was 38 MB). Image size =
  max(raster attributes, what was painted), capped at 10000 each way; aspect ratio 1:1.
- **Placement**: the decoded image is padded with transparent pixels to whole cells at the
  emulator's cell size, so a fragment shows exactly the pixels a terminal would draw there.
  Cursor after: below in the start column (default), right of the last row clamped to the last
  column (8452: no wrap, so an image at the bottom right never scrolls), unchanged with DECSDM
  (drawn at 0,0). These are the forms I could pin down without xterm here; the device never
  depends on them (it saves the cursor around its own images).
- **DA1** is now `CSI ? 62 ; 4 ; 22 c` (Sixel) and XTSMGRAPHICS is answered (1024 registers,
  the screen in pixels), so a hosted program picks Sixel by itself. A nested tui still picks
  Kitty: its probe gets Kitty's OK first, and Kitty is preferred.
- **Device order**: kitty (query OK) > iterm2 (env/name) > sixel (DA1 has 4) > none. Sixel is
  painted like iTerm2 (changed cells, rectangle by rectangle) but scaled through GD to the
  cells' pixels (Sixel cannot scale) and quantised by `imagetruecolortopalette` (dithered, 256);
  encodings cached per image by (placement, rectangle, cell size), 32 per image. Each image is
  wrapped in DECSC/DECRC so the tracked cursor stays right, and `CSI ? 8452 h` is sent before
  the first (reset at detach) because xterm's default moves the cursor below the image, which
  scrolls the screen for an image on the bottom row. Assumption, as for iTerm2: text written
  over a sixel image's cells replaces it there (as terminals that keep images in their cells
  do; a terminal that keeps graphics on a layer until erased would show the old picture under
  text: not verifiable here, no Sixel terminal on this machine).
- No pure-PHP quantiser: Sixel output needs GD, like the quarter fallback and iTerm2 crops; a
  canvas needs GD or Imagick anyway. Without GD, Sixel image cells stay blank (documented).
- No XTSMGRAPHICS probe by the device: `CSI ? 1 ; 1 S` to a terminal that ignores the `?` would
  scroll it (SU). 256 colours is what every Sixel terminal of today has at least.
- `Tty::imagesFromEnvironment()`: inside tmux it returns null (probe) instead of 'none': tmux
  answers DA1 itself, with 4 when it was built with Sixel; the outer terminal's TERM_PROGRAM /
  LC_TERMINAL are not believed there.
- The parser is regenerated with charm-compile from charm/parsing dev-master at 3d9d757 (the
  commit composer.lock had before charm/parsing left composer.json): `composer require
  charm/parsing:dev-master#3d9d75747310832f5dab96670633bb0623c840b4` in a scratch directory with
  minimum-stability dev, its vendor/bin on the PATH, then `composer grammar`; GrammarTest checks it.

## Design decisions (0.0.4-beta1)

- **Image fragments live in the Style**, like the hyperlink: a placed cell's style is the pen
  with `image` set to an `ImageFragment` (image, source rect, the placement's cols x rows, this
  cell's col,row, the producer's placement id). So copyArea/drawArea/shade/scroll/erase need
  nothing new, a glyph over an image drops it (the pen has no image), and the diff compares
  with ===. Image, ImageFragment and image Styles are interned through WeakReferences (the
  plain styles stay interned forever as before): an image redrawn every frame must not leak.
  Style::blank() of an image style is the style itself and is NOT memoised ($blank = $this
  would be a cycle). Ansi::style() and the device's sgr cache key by spl_object_id: they map an
  image style to its style without the image first (an image style's id may be reused).
- **Fragment identity for tests and dumps**: `ImageFragment::__toString()` is the image id and
  the pixels the cell shows (`pixels()`), so two placements that show the same pixels in the
  same cells dump the same (the reference compositor places each cell 1x1 with its pixels).
- **Cursor after an image**: kitty's rule (right of the last row; at the right edge column 0 of
  the next line, scrolling) for both protocols. iTerm2 itself does the same (VT100Screen
  appends the image like characters); imgcat prints a line feed after, so "below" is what a
  user sees. TODO said "moves below as iTerm2 does": this is that.
- **No kitty/imgcat here**: tests/fixtures/images/*.bytes are written by tools/image-fixtures.php
  in the forms icat (direct, chunked, q=2, centred with CUF) and imgcat (name, size, BEL) send.
- A long OSC/APC across many write() chunks: while nothing in a chunk can end it, the chunk is
  appended to the pending tail without parsing (MAX_PENDING raised to 64 MB for images).

## Status (0.0.3-beta8; TODO-0.0.3-beta8.md, SPEC 3 as amended 2026-11-01)

- [x] 1a. Blitting: Area (copyArea/drawArea), Host::compose() makes one frame per host from
      its own cells and every window below it to any depth; per-viewport frames, covers,
      forgetRows, the save/restore wrapper, deferred blanking and per-level damage gone
- [x] 1b. property test against a reference ANSI compositor (tests/Support/AnsiComposite.php,
      validated against the beta7 compositor on 150 seeds before the switch; it found that
      beta7 did not repaint a shadow that came with raise() etc.)
- [x] 1c. one diff at the end by visible identity (Style::blank()), Surface::dump(looks)
- [x] 1d. cost: per-window draw cache, occlusion of a host's own cells, rows made again only
      where something changed (Host::damage(), to any depth); tests/Benchmark/BlitCostTest.php
      (160x50 ten deep: one cell 0.25 ms, every surface 1 ms, own row 0.04 ms; 200 windows of
      which one changed 0.1 ms); bench modes against beta7 (paced): tabs 14.3 -> 8.7 ms,
      scroll 2 3.7 -> 3.0, scroll 200 6.0 -> 5.5, bars 37 -> 29, keys 2.6-3.6 -> 2.8-2.9 (equal
      within noise)
- [ ] 1d'. NOT MET: the 50-bar frame under 8 ms. Continuation 2 (component layer): 29 -> 20 ms
      paced, 11.5 -> 7.8 ms back to back (tools/monitor-bench.php bars; paced costs 2.6x back
      to back on this CPU: schedutil, 1.2-3.6 GHz, the frame runs near the low clock after 50 ms
      idle, so 8 ms paced needs about 3 ms back to back). Done: Tree::unchanged() (a build whose
      elements lay out as the last build's keeps its items: 3 -> 1.2 ms), Component::style()
      memo (styleCounters), grammar first-byte order (CsiPlain first, ESC-led and C0 guarded,
      C1 behind one lookahead: a bar's write 26 -> 17.5 us standalone), Host::place() once per
      frame, ProgressBar shape() once per render and no EL for a label of the same width.
      Measured split of the 7.8 ms (hrtime around each phase, back to back): the closure 0.5,
      the tree walk 1.2 (sameShape + reuse; place()/receive() of 150 components is 0.3 of it),
      50 bar renders 2.6 (of which their writes' parsing 1.4: ~28 us each in situ against 17.5
      standalone, cold caches), compose 1.9, device diff 0.7, FakeTerminal parse 0.8. Nothing
      left is more than a quarter of the frame; what remains is per-item cost (parser items ~4
      us in situ, windows ~8 us to compose). Ideas not done: damage by column spans (blit only
      the changed windows' extents of a row, not every window on it: ~0.7 ms), a viewport
      version so damage() compares numbers instead of building look strings (tried: bars 7.7
      -> 8.0, a Tab 1.05 -> 0.99, keys 2.9 -> 2.8, all noise; and a stylesheet change that
      restyles a border would need its own bump: not kept), Text
      elements without a window when they have no handler (like flat containers; ~0.3 ms).
      None of them reaches 3 ms. `keys`: median 2.9 ms (equal within noise); a Tab is 1.05 ms
      back to back: the dispatch and focus walk 0.25, the restyle of two rows 0.2, compose
      0.2, two Button renders 0.13, the fake terminal 0.07; no one part to cut.
      Continuation 3 (2026-10-31), measured again with excimer (EXCIMER_CPU, 0.1 ms period, 300
      bars frames back to back, 8.0 ms each): user closure and element constructors 7%, Tree
      build (sameShape, reuse, receive) 11.5%, 50 bar renders 22.5% (their writes' parse 16%),
      compose 25% (damage walk 9.4%, blits 15.9%), device diff 8%, FakeTerminal parse 8.6%, the
      rest of the render passes (show, measureFor, setLayout, area()) about 15% spread over
      twenty functions. A floor: the parts no design of this library can remove (the closure
      0.55 ms, parsing 50 bar writes at their standalone 17.5 us 0.9 ms, a terminal parsing the
      output 0.7 ms, a diff of 50 rows 0.4 ms) are 2.5-2.9 ms back to back, 6.5-7.5 ms paced,
      which leaves well under 0.5 ms paced for reconciling 250 elements, 50 component renders
      and composing 50 rows. So 8 ms paced is not reachable on this machine in PHP by any
      change to the layers above the emulator; only a faster parser (charm/parsing's generated
      code is already one preg_match and two calls per item) or a faster machine would. With
      opcache.jit=tracing the bars frame is 13 ms paced (19.9-21.2 without, ext-phasync 19.6).
      `keys` against beta7 in the same session (beta7 in a git worktree, the same vendor):
      beta7 2.6-2.8 ms median, beta8 2.9; but a Tab back to back is 1.06-1.10 (beta7) against
      0.99-1.01 ms (beta8), and paced without the 50 background coroutines 3.0-3.2 against
      2.5-2.8 ms. A Tab is faster; the `keys` median is higher because the background bars
      frame (every 100 ms) went from 37 to 20 ms, so the governor keeps the clock lower
      between them (schedutil follows the load): the bench mode measures the CPU clock there.
      Continuation 4 (2026-10-31), no code changed: the paced cost is the machine, measured
      three ways. (1) getrusage() around each paced bars frame: CPU time 19.7 ms = wall time
      19.7 ms (7.4 = 7.4 back to back), so the frame waits for nothing; it runs slower. (2) A
      fixed pure-PHP loop with no library code costs 7.0 ms back to back, 7.3 after a 5 ms
      sleep, 15.1 after 20 ms or 50 ms: the same 2.2x, with the same cliff the bars frame shows
      (sleep 0: 7.5, 1 ms: 7.8, 5 ms: 8.5, 20 ms: 19.6, 50 ms: 19.7; pinned to one core the
      same). A paced 8 ms is about 3.6 ms of work at full clock. (3) opcache.jit=tracing
      (opcache.enable_cli is off by default here): bars 5.1 back to back, 12.9 paced; keys 4.0
      (worse: compiling). Profile again (excimer, back to back): renderPasses 54% (Tree 11.7,
      50 bar renders 23.4 of which their writes 17.4, show 5, the closure 6.5), compose 25
      (damage 10, blits 14), device diff 8.8, FakeTerminal 8.6. A captured bar write (CUP, SGR
      38;2, a cell, CUP, SGR, the label) is 13-14 us standalone, 23.6 in situ. Upper bound of
      what is left to win, every layer above the emulator tuned to its ideal (Tree -0.5, render
      passes -0.6, bar shape/caching -0.4, compose by column spans -1.1, device by spans
      -0.35): 7.5 -> about 4.5 ms back to back, about 10 ms paced: still over 8. `keys` per key
      (one run): two thirds 2.2-3.2 ms, a third 12-33 ms (the Tabs that meet the background
      frame of all 50 bars, which the 50 coroutines' 10 Hz sleeps line up into one).
- [x] 1e. docs/surface.md Performance, docs/hosting.md ("Compositing is blitting"), README
      model, CHANGELOG
- [x] 2. Quit from a Tui::go() job under headless: the fatal came from the Tui::sleep(0.5) of
      the fiber\run() closure itself (not a job), which threw Quit by the beta5 rule
      "elsewhere: Quit". Now Tui::sleep() throws Quit only in a Tui::go() coroutine (where
      Tui::go() takes it) and returns early elsewhere; tests/Release/QuitBeta8Test.php
      (headless, headless inline, Tui::inline() in a pty)
- [x] 3. small: Confirm binds the first letters of its labels (y/n); cookbook recipe 11;
      fiber-interop api v0.3.2 and phasync v0.4.2 were tagged by continuation 2 (2026-10-31):
      composer.json requires api ^0.3.2, require-dev phasync ^0.4.2, conflict api <0.3.2 and
      phasync <0.4.2; the dev pins, Runtime's warning (CANNOT_AWAIT_STARTER, canWatch,
      warnOnce, $watching, $warn) and the tests of it are gone (RuntimeBeta5Test now checks the
      exact shape ends with its exception and no warning); Runtime::REQUIRED 0.3.2 and check()
      wants fiber\cancel. Tui::go() keeps what fiber\go() returned in its Job (only to pass to
      fiber\cancel(), never compared); Job::end() cancels it with a new Quit (Quit implements
      fiber\CancelledException), except the job that is the current task (Tui::isCurrent() by
      the jobs map keyed by fiber\current()): cancelling the task that called Tui::quit() cut
      the waits of dispose() short, and cancelling a job that failed made the adapter take its
      exception for the cancellation (exit 0 instead of the error). An adapter without
      cancellation (NotSupportedException): the Signal path of Tui::sleep() as before.
- [ ] Left: the 50-bar frame under 8 ms paced (20 ms; see 1d' for why it is out of reach),
      `keys` improving in its bench mode (a Tab is faster, the mode's median is not: see 1d'),
      and so the tag 0.0.3-beta8. (Seen once under load, continuation 3: StateTest's "ends the
      sleep of a component at a tick" woke 10 ms after a tick against its 6 ms; it passed in 5
      runs of the file and the next full run with ext-phasync. A wall-clock bound, not a bug.)

## Design decisions (0.0.3-beta8, continuation 2)

- **Tree::unchanged()**: before a full build, the new element tree is compared with the one
  built last (sameShape(): same classes, attributes with any closure equal to any closure,
  texts, spans, gaps, Box titles; component elements by class and attributes, props ignored,
  a started one only as itself; a Help never), under the same sheet, base style, inline flag
  and Component::styleCounters() (styleVersion, focusVersion, geometryVersion, treeVersion,
  recorded after the build and after a restyle). Then reuse() does only what a build does
  besides laying out: nodes[] and focused/focusing get the new elements (handlers, focus
  callbacks), component elements are placed (receive()) and must land on the component the
  last build had there, else the full build runs (place() is idempotent). claim() runs first
  as before and seals every element: with() on a sealed element bumps the style counter, so a
  kept element changed in place is laid out again. The global counters are coarse: any
  component started anywhere (another program in the same process) makes the next build a full
  one; tests that compare with a fresh program make their fresh programs after the run.
- **Component::style() memo**: per component, per part+states key, valid while
  styleCounters() is the same and no other component's render asks (hasFocus() subscribes a
  reader). styleVersion is bumped by attribute changes (receive(), with()), setStyleState(),
  inheritStyle() with another style, renderSubtree() (theme, stylesheet, background probe) and
  the pointer moving. Surface::geometryVersion() counts geometryChanged().
- **Grammar order for speed** (ansi.cpeg): Item = CsiPlain | Escape | Control | Text | C1Item.
  charm/parsing guards a rule reference with a first-byte check only when its regex analyser
  understands the pattern: \x{..} escapes (code points above 0x7f) and negated classes defeat
  it, and \xhh above 0x7f would be analysed as a byte (wrong under /u), so the ESC-led rules
  are written in ASCII only and the C1 forms are separate rules behind one lookahead.
- **Host::place() per frame**: damage() and the blit after it place every window with the same
  arguments; the result (without the row filter) is kept by viewport id for the compose.

## Status (0.0.3-beta7; TODO-0.0.3-beta7.md)

- [x] bench: tools/monitor-bench.php `scroll [rows] [focus|nofocus]` and `tabs [cols] [rows]`
      (the two programs of the TODO; reproduced 4.8/22/43 ms and 27/19 ms)
- [x] 1. Tab in a ScrollView of rows: 200 rows 6 ms (2 rows 3.7); tests/Release/ScrollTabBeta7Test.php
- [x] 2. tab switch at 120x40: 14-15 ms at the bench's pace (27 before), 80x24 10 ms; Markdown
      not drawn again; tests/Release/TabSwitchBeta7Test.php
- [x] 3. small: Table writes changed rows, cells cached as drawn (3.0 vs 1.8 ms; 18 vs 8 in the
      report); TUI_THEME / --theme=; Component::takesEveryKey() (Terminal); ProgressBar writes
      changed cells; tests/Release/SmallBeta7Test.php. The 50-bar frame: 40 -> 37 ms paced (19
      back to back), 31 / 15 with closure values; what stays is the rows' own windows (onKey:
      makes them focus stops) and the program's render when it reads the values
- [x] CHANGELOG, docs (components.md: flat containers, takesEveryKey, Terminal keys;
      widgets.md: around a Terminal, Table and ProgressBar costs; styling.md: TUI_THEME), README

## Design decisions (0.0.3-beta7)

- **layoutElement() trees restyle** (Component::$laidOut, $restyling): a component that lays
  an element tree out somewhere else (ScrollView) renders again on a focus change with
  `restyling` set, and layoutElement() of the same element object runs Tree::restyle() instead
  of build(); measureElement() of the laid-out element uses the built items. The layout of
  such a tree runs without a reader (a Button's hasFocus() read in the layout subscribed the
  ScrollView to every button's focus: a full render per Tab). ScrollView::onKey() asks a
  restyle, not a render. A restyle-render skips the children cleanup (nothing left the tree).
- **rearrange()**: a child whose size changed (Component::show()) has its parent's layout
  arranged again in the next frame (restyle path, `$rearrange`), not its render run: a Text
  closure growing a column ("sel 9" -> "sel 10") ran the program's closure over 200 rows.
- **Viewport::scrollTo()/pan() to where it is** changes nothing (it repainted the window and
  told every area watcher). ScrollLayout re-sets its content's layout only when it is another,
  the size changed or an item is stale.
- **Flat containers** (LayoutItem::$flat, ElementLayout::arrangeAt()): a Column/Row with no
  handler (onKey/onFocus/onBlur/onMouse), no focused:/state, no border/shadow/title, the same
  fill as its parent's layout, not hover-aware and whose style with `focused` is the same
  object, places its items on its parent's host in its rectangle. Every window is a level of
  compositing (its host parses what it writes); `Row([Tabs, Box])` in a Column cost a whole
  level. The flatness is part of the item signature (it depends on the sheet). Mouse::over()
  still lists them (ElementLayout::flatAt(): host and rectangle). Not inside Layers (z-order
  is hosting order). A container that turns flat gives up its windows (they move to the host).
- **Detached roots announce no focus** (Surface::$detached: shown once, in no window now):
  a hidden tab's pane settling a focus request told its components they had the focus (and
  again that they lost it when shown: joinTree()). Components whose root is detached skip the
  render for an area change; one shown again where it rendered last (`renderedAt`) does not
  render either.
- **Blanking is deferred to compose** (Host::$blanks, blankUncovered()): unhost() and moved()
  queue their rectangles; compose blanks them except where a window that paints anew covers
  (it is diffed against the host's cells). The host's producer writing first (a Split's
  separator) flushes the queue (Host::beforeWrite()).
- **Parser and diff**: the grammar's `Stream = Item* $` (the `!$` predicate called expect() per
  item: 25% of the parse); SGR that starts with a reset memoized per parameter string (pure);
  SGR dispatched before params(); printCells() assigns cells instead of splicing the row;
  Ansi::style() memoized per interned Style; diffAnsi() fills gaps of up to 4 unchanged cells
  (one run per line of words), iterates only the written cells, skips the wide-character pass
  when the row has none.
- **Table draws changed rows** (Table::$paintedRows, $cells): each row is built as one string
  of escape sequences; only rows that differ from what was written are written. Everything is
  written anew (`\e[2J`) when the size, the columns in view, the alignments, the base style or
  the sheet change. Cells are cached as drawn (markup, cut, pad, row style) by row-style
  object, width, alignment and text; the cache is bounded (4096, cleared) and dropped on a full
  repaint. Footer rows (Select's filter line) are cleared and drawn whole every render.
- **TUI_THEME / --theme=** (Tui::themeAsked()): only when the program passes no theme.
- **takesEveryKey()**: at the key end, a component that says so lets its parents run only
  bindings marked `always` (runBinding($key, true)) in the bubbling phase, no elements' onKey,
  no `anywhere`, no non-modal Escape of a parent's overlay; `first` bindings ran on the way
  down as before, and the root's quitKey stays. Terminal says so. examples/tabs.php's Ctrl-N
  had to become `first`. An alternative was to document only; the TODO allowed either, and a
  parent binding taking Ctrl-D from a shell is never what a program means.
- **ProgressBar draws changed cells** (ProgressBar::$cells): with the same cols, bar width and
  styles, only the runs whose fill changed and (if it changed) the label, after `\e[K`; a
  label of another width changes the bar width, so a full drawing. Styles computed once per
  render (look() and drawing() share them).
- Measuring: timings in one process vary 2x with the CPU clock (and with garbage collection
  every couple of switches); the bench's 20 switches at 50 ms are the stable figure.

## Status (0.0.3-beta6; TODO-0.0.3-beta6.md)

- [x] 1. Tab cost O(rows): restyle instead of render (Tree::restyle), interned ComputedStyle,
      component items keyed by their frame, Component::nextStop(), focus-request settle,
      unshownChildren(); tests/Release/KeyCostBeta6Test.php; the bench measures press+settle
- [x] 2. Markdown tab switch: deferred layouts (Component::show(), Surface::deferLayout()),
      measured answers kept until asked again, windows shown anew diffed against the host's
      cells (Surface::cellsAt()), Markdown's part styles cached, Tabs does not hold what its
      panes started; tests/Release/TabSwitchBeta6Test.php
- [x] 3. ScrollView brings what becomes selected into view (Tree::selectedSurfaces(),
      Component::selectedSurfaces()); the `focused:` attribute (an edge, as focus() in a
      render); tests/Release/SelectedBeta6Test.php
- [x] 4. Help() and keys: Component::bubbling() (owners, and before each owner the components
      the focused one is shown in below it); Tabs' bar keys only on the bar;
      tests/Release/HelpBeta6Test.php (fresh and kept, Help(for:))
- [x] 5. small: `'anywhere' => true` bindings (Tabs' Alt-n, Ctrl-Tab), 'quit' on elements in
      docs/components.md, Tui::freeze(); tests/Release/SmallBeta6Test.php
- [x] CHANGELOG, docs (components.md, widgets.md, terminal.md, surface.md), README

## Design decisions (0.0.3-beta6)

- **A focus change is a restyle, not a render.** An element surface's focus event used to
  markDirty() its owner: a closure program re-ran render() and the Tree rebuilt every row for
  each Tab (the per-row cost). Now the element's path is marked stale and the owner gets a
  restyle in the frame (Component::markRestyle(), restyleOnly, restyleNow()): Tree::restyle()
  runs build() over the last root element with `$toward` (stale paths and their ancestors) and
  `$below` (inside stale paths); every other path returns the item the last build made there
  (`$made`), so only the containers above a stale element walk their children (cheap returns).
  Bookkeeping of a full build (claim, seen, cleanup, focusing, hovers, helps) is kept from the
  last build. A component's own focus change marks its place in its parent's tree
  (Tree::focusMoved()); Help elements are paths too (helpMayChange(), on any focus move). A
  full render pending makes the restyle unnecessary; anything else changed (sheet, own style,
  breakpoints, relayout, overlays, a measure that placed things since) falls back to a render.
- **ComputedStyle is interned by value** (Stylesheet::resolve()): an element whose states changed
  but whose look did not gets the same object, so kept() keeps its item and nothing is laid
  out. Because the same style object now stands for different states, the component's
  shownWith also records the breakpoints (they were implied by the style's identity before).
- **Component items are keyed by their frame** (Tree::frameOf()): a component draws itself; a
  style change that does not touch the window's frame (size, border, margin, ...) keeps the item.
- **Tab walk**: Component::nextStop() finds the next stop from the focused one up, instead of
  focusOrder() of the whole screen (which also asked canFocus() -> bindingsInForce() of every
  component); focusOrder() stays the definition, and a test walks both ways comparing them.
- **Focus requests settle the path only** (Surface::settle()): when every change since the last
  settle is a focus()/takeFocus(), the hosts above the requested surfaces (in stamp order) are
  pointed at them; the latest request outranks every other, so this is what rankFocus() would
  find. Anything else settles the whole tree as before.
- **unshownChildren()** caches the started children in no window per (treeVersion,
  Surface::hostingVersion()); unshownFocus()/focusRank() ran over all children on every key.
- **Kept instances**: a new instance notes its first container (claim() calls placedUnder() for
  it too), so an instance kept by the program is recognised from its second render. It used to
  be recognised only because focus events caused an extra render.
- **A size change is laid out once** (Component::show()): a render whose measure answers
  changed (the parent will lay it out at another size in this frame) does not arrange its new
  layout at the old size: Surface::deferLayout() stores it, the parent's resize arranges it,
  and the component renders again after the parent (relayout()) in case the size did not
  change after all; at most one deferral in a row. Before, a Tabs as tall as its content
  switched to a pane that fills laid the pane out in one row, the Markdown in it was resized
  to one row, drawn, resized back and drawn again; switching away shrank it the same way (its
  rows were lost, so the next show repainted everything).
- **Measured answers stand until asked again** (Component::$measured is no longer consumed by
  a render): a parent whose items keep their naturals cached does not ask again, and a size
  change after the first render went unnoticed.
- **A window shown anew is diffed against the host's cells** (Viewport::paint() uses
  Surface::cellsAt() as the last frame of a row it never painted): a pane shown again over the
  same cells, or a blank window over a blank host, writes nothing.
- **Tabs::owns()**: a content instance a pane started (the first render's own instance) is
  the pane's, not another owner's; holding it made the next setTabs() with a fresh instance
  stop it (it showed as a new Tree after a tab switch once layouts were deferred).
- **`focused:` rather than `Tui::focus($element)`**: elements are values rebuilt on every
  render, so a program says which one has the focus the way it says which is selected. It is
  an edge per path (Tree::$wanted/$wanting): the build where it is new (or turned from false
  to true) queues the surface (or component) and the owner focuses it after the render, so a
  render that keeps it true does not take the focus back from where Tab moved it.
- **Selected into view**: ScrollView::follow() brings the first selected surface of its tree
  into view when it is another than the one brought last (`keptSelected`), with the same
  minimal scroll as the focus (bringIntoView()); the wheel may scroll away until the
  selection changes. Only the ScrollView's own tree is looked at (its content as given).
- **Keys go through where a component is shown** (Component::bubbling()): before, a key the
  focused component did not take went up its owner chain only; a Tree the owner kept with
  child() and gave a Tabs skipped the Tabs, a fresh one (started by the Tabs' pane) went
  through it. Now from each component to its owner, the components it is shown in below that
  owner come first (found by walking up its surfaces until the owner's). An overlay's content
  is not shown inside its opener, so it goes to the opener as before. Help walks the same list.
  The item 4 report ("the Help line shows the tab bar's keys") was the fresh shape: the Tree
  has no described keys, so Help showed the next ones up, the bar's; and the bar's Left did
  apply in the tree. Hence also: Tabs::bindings() gives the bar's keys only while the focus is
  not in the content.
- **`anywhere` bindings** (item 5): index 4 of a normalized binding. Components that may have
  one are noted in a WeakMap (start() after mount(), bind()); a key nobody on the way took
  goes to the one shown component on the root that binds it anywhere (none when two do, none
  while a modal overlay is open). Chosen over documenting `keys:` because a second Tabs makes
  a `keys:` binding wrong silently, and other widgets (a command palette's Ctrl-P) want it.
- Measured (this machine): Tab + settle, 160x50 rows of Text+2 Buttons: 2 rows 1.2 ms, 200 rows
  1.8 ms (11.8 before); the bench's 50-row monitor 2.5 ms median (bars moving). What is left
  per frame when all 50 bars move is the emulator parse at each nesting level (bar, row, root,
  device) and the closure's render: about 28 ms per tick here.

## Status (0.0.3-beta5; TODO-0.0.3-beta5.md)

- [x] 1. No silent hang on the tagged runtime: Runtime::watch() warns once on stderr when it
      cannot watch (installed and needed versions), before the terminal is taken over;
      Runtime::owned() for Tui::run()/inline() (no warning, they own their run); device loops
      and the dispatch end the program on an uncontained failure; tests/Release/RuntimeBeta5Test.php
      (the exact shape in a process, canWatch() forced off; owned failures still end)
- [x] 2. 'quit' on an element: the element path ran a string action as a closure (TypeError in
      the input coroutine, which then died: no reader, the screen stood). Component::runAction()
      for every binding; tests/Release/QuitBeta5Test.php (both forms, element and root,
      headless and on a pty via tests/fixtures/programs/quit.php)
- [x] 3. Key cost: Component::focusPath() (one walk per root and focus/tree version),
      LayoutItem caches every natural() until Tree::sizeMayChange(), ElementLayout::arrange()
      arranges again only stale items, State subscriptions per property, Key::parse() memo;
      tests/Release/KeyCostBeta5Test.php (rows measured/painted on a Tab, one render per Tab,
      timing), tools/monitor-bench.php keys
- [x] 4. Small: Tui::go() jobs stop (Tui::sleep(), Quit, Internal\Job); running()/hasFocus()
      of handed-over instances, hasFocus() subscribes renders; Help(for:) resolves; ScrollView
      and Column docs and tests; tests/Release/SmallBeta5Test.php
- [x] CHANGELOG, docs (components.md, terminal.md, widgets.md, surface.md, cookbook.md), README,
      examples

Not reproduced in this tree (tests kept in the reported shape): the Help line showing the tab
bar's keys while a fresh instance in a tab had the focus (the default Help lists the focused
component's keys first, then the Tabs'); ScrollView already scrolled every focus change into
view (Tab, Shift-Tab, focus(), after the user scrolled away, element stops).

## Design decisions (0.0.3-beta5)

- **The warning instead of a conflict.** composer.json could say
  `"conflict": {"fiber-interop/api": "<0.3.2", "fiber-interop/phasync": "<0.4.2"}` and require
  `^0.3.2`, but 0.3.2/0.4.2 are not tagged (vendor has the dev branches, `dev-main`): a
  conflict now would make the package uninstallable. When they are tagged: require
  `fiber-interop/api: ^0.3.2`, add the conflict for the adapter, and drop CANNOT_AWAIT_STARTER,
  warnOnce() and the owned() bookkeeping. The warning is printed only where a watch is needed
  (a start not inside Tui::run()/inline(), which wait for and dispose their program), once per
  process, before the takeover (watch() moved before open() in attach()).
- **Uncontained failures end the program.** TerminalDevice::loop() wraps the frame and input
  loops, dispatch() detaches before passing the exception on. A component failure was already
  contained or ended the root; this covers what is outside any component (bare surfaces) and
  library bugs (the item 2 TypeError would have ended the program visibly).
- **One runAction()** for bindings of components, `keys:` and elements' arrays. A method name
  on an element is a method of the component whose tree made it (the only object there is).
- **Item invalidation instead of uncached focusable items.** LayoutItem::naturalInside() used
  to skip its cache for items with a component below ("a component may want another size"),
  and arrange() re-arranged them always: O(rows) per render. Now a component that gave a
  parent's layout an answer (Component::$measured, every room asked since its last render,
  at most 16) compares them after its render; only when one changed does it invalidate the
  items from its own up its tree's root (Tree::$placedAt: component -> [tree, path];
  sizeMayChange()) and relayout() its parent. Invalidating at markDirty() was tried first: it
  left lingering invalidations (a row measured again a key later). Key segments in paths are
  %-escaped so a key with '/' cannot confuse the prefixes.
- **Focus path once.** hasFocus() for N components walked the focus path N times, and each
  step asked unshownFocus() over all children of that component: O(N^2) for a closure root
  with 150 children. focusPath(root) is cached by (focusVersion, treeVersion), as the per
  component cache already was.
- **State per property.** `$readers[name]` instead of one owners list for reads; holders
  (`watchFor()`) still render on any write. The docs already said "when that property is
  written"; the code now does. It removed the second render of every cross-row Tab (onFocus
  writes `current`, which the closure does not read).
- **Measuring keys.** Wall time with the CPU clocked down (schedutil) between keys is 2.5x the
  back-to-back time; the test budget is 40 ms against ~10-12 ms measured here.
- **Tui::go() jobs**: Internal\Job (program weak ref, ended flag, Signal). Claimed by the
  running root, else by the next program Tui starts (Tui::start() claims self::$unclaimed);
  ended by `$root->stopped()->then(...)` (a Deferred's then(): synchronous, no coroutine), or
  by Tui::starting() when a runner fails before its program starts. The job is found from
  inside by `fiber\current()` as a WeakMap key (identity only), registered by the task itself
  from its own `current()`, never from what go() returned (opaque); on a runtime whose
  current() is null in a task's first stretch, the task sleeps(0) once first. Tui::sleep() waits on the job's
  Signal (the shared clock); outside a job it makes a job for the running root on the spot.
- **running()/handedTo**: receive() notes the running instance on the fresh one; hasFocus() of
  an unstarted instance answers for it. In the render that makes it, it is not handed over yet
  (documented: ask in an element closure). hasFocus() read in a render registers the reader
  (`$focusReaders`) and the component's focus listener marks it dirty, as State does.

## fiber\ finding (0.0.3-beta5)

- **No cancellation.** TODO item 4 asks that a `Tui::go()` coroutine's next `fiber\sleep()` or
  wait throw a `Quit` when its program ends. fiber-interop/api has no way to make another
  task's wait throw (its README: cooperative cancellation is the planned follow-up;
  `CancelledException` exists but nothing raises it portably). The library does without in the
  plain way: its own wait, `Tui::sleep()`, ends with `Quit`; `fiber\sleep()` in a job runs to
  its end and `fiber\run()` waits for the job. The smallest addition that would do it:
  `fiber\cancel(object $task, ?\Throwable $reason = null): void`, making the task's current or
  next wait (`sleep`, `await*`, `readable`, `writable`) throw `$reason` (default a
  `CancelledException`), with the api's `run()` treating a task ended by its own cancellation
  as finished. With it Tui::go() would cancel its jobs on quit and Tui::sleep() could go.
- **Tagged versions.** The beta4 finding (await of a `current()` handle) is fixed on the main
  branches only; see "The warning instead of a conflict" above for what to do when tagged.

## Status (0.0.3-beta4; TODO-0.0.3-beta4.md)

- [x] 1. Tab trapped after a shown and left shell tab, in the exact shape (setTabs() every
      render, a fresh Tree in a fresh Row): Tabs panes (51b1fa2); tests/Release/FocusBeta4Test.php
      (child, keep, prop), the beta3 variant (tests/Component/FocusHiddenTest.php) kept
- [x] 2. onKey: around focusable components is not a Tab stop (Component::focusOrder());
      FocusBeta4Test (three rows, two rounds both ways, onFocus counts, `p` in row 2)
- [x] 3. Small: Tui::quit() and the 'quit' action; Select value:; dialog focus back to the
      deepest window still shown; Alt-number anywhere in Tabs (first bindings, overlays of
      content count as in it); the uncaught handler (Tty::uncaught, TUI_TRACE); println()
      markup and Text::raw(); State arrays in place; settle() line at the top of the testing
      section; failing test code under Tui::headless(); tests/Release/SmallBeta4Test.php,
      FailureTest (concise message, TUI_TRACE)
- [x] CHANGELOG, docs (widgets.md Tabs rule and panes, components.md onKey stops and State
      arrays, terminal.md println/uncaught/testing), README

Not reproduced in this tree, tests kept in the reported shape and variants: the dialog that
gave the focus to the first focusable thing (four openers: keys:, a Button, an onKey card, a
coroutine; also class components with fields in a Box, a Select, Tabs), the Editor completion
that swallowed Tab (single-line, multi-line, in a Box, in a Form, in a Dialog), and the hang
of failing test code under Tui::headless() (run closure, dialog open, a pty that ignores
SIGHUP, inline). The one shape that does hang is test code in a plain `fiber\go()` that fails
while the program is on its terminal: the fiber\ gap of beta3 (below); docs/terminal.md says
to use Tui::go() for test coroutines.

## Design decisions (0.0.3-beta4)

- **Tabs show each tab through a pane** (`Internal\Live` kept under `tab:<key>`, `show` set to
  the content when it is current and changed). The pane's own Tree places the content's
  components by position/key like any render, so a fresh instance per render hands its props
  over (8.1) instead of becoming a new child keyed by object id (the item 1 defect: the focused
  Tree was stopped each render; object ids being reused made it look intermittent). Components
  of other owners (started already) are still held by the Tabs (`held:<object id>`), so the
  owner's render does not dispose them while hidden; they are stopped when their tab goes, as
  documented before. A hidden pane is neither laid out nor rendered.
- **Shown means attached to the root**: `Component::shownFrom()` walks up from a component whose
  surface's root is the surface of a component with a parent (a hidden pane's windows still
  have viewports, in a detached tree). focus() and hasFocus() use it; a component inside a
  hidden pane is not shown, as a component without a viewport was.
- **placedSelf holds the components** (not only ids), so a tree that shows another owner's
  component first-renders it when it is first shown there (the owner's render did not lay it out).
- **Tabs::inContent()** is the pane on the focus path, or a started component of the content
  with the focus (one focused before the pane showed it). focusContent(): the first stop of
  focusOrder() in a laid-out pane, else the first component of the content as given that
  takes keys (it starts when the pane first renders), else the pane.
- **onKey: elements as stops**: in focusOrder() an element with onKey is a stop only when its
  subtree has no stop. keyElements() already ran the onKey of elements on the focus path, so
  nothing else changed; the onFocus diff already describes entering from outside.
- **Alt-1..9 of Tabs are `first` bindings** (closures in bindings()), not onKey(): a `first`
  binding also runs when the key bubbles up from an overlay the content opened (a dropdown's
  list is hosted on the root, outside the Tabs' surface, so onKey() on the way down never saw
  it). hasFocus() is also true while an overlay opened by a component shown inside this one
  has the focus (so inContent() is right there).
- **Overlay focus path**: `Overlay::$focusPath` (the whole path when it opened); closing
  focuses the deepest of them still attached to the root, else the opener.
- **The uncaught handler** is installed by the first `Tty::open()` when the program has none
  (`set_exception_handler` returns the previous one; it is put back when there was one). It
  restores open terminals, prints class/message/file:line per previous exception, the trace
  with `TUI_TRACE`, and exits 255. Headless (FakeTerminal) programs keep PHP's.
- **State arrays in place**: `__get` returns by reference; a read outside a render of an
  array keeps a copy (`$lent`, copy-on-write so free until written) and schedules one check at
  the next tick (`fiber\go` + `sleep(0)`), also run by the component flusher before a frame
  (`State::flush()`); the check compares with `!==` (pointer-equal arrays are compared in O(1)).
  Reads inside a render lend nothing. `++` and assignments still go through `__set`.
- **'quit' as an action**: runBinding maps the method name `quit` to `Tui::quit()` when the
  component has no such method: one place, so keys:, bindings() and bind() all have it.
- **println()** converts markup with the root component's stylesheet (the theme in force), a
  `Text` by its runs (spans computed by the sheet), `Text::raw()` as written.

## Status (0.0.3-beta3; TODO-0.0.3-beta3.md, SPEC 9.1 as amended)

- [x] 1. Input without a class (65c36a7): `keys:` on Tui::run()/inline()/headless() (bind(first:
      true)), closures as binding actions, `onKey:`/`onMouse:` on elements, Mouse::over(),
      Dialog::open()/Confirm::ask(), handlers that wait (TerminalDevice::deliver());
      tests/Release/ClosureInputTest.php (the Kanban)
- [x] 2. Kept instances keep their identity (425a169): Tree::claim()/$present, placedUnder(),
      place() re-keys a kept child instead of handing it a new instance's props; a stopped
      instance placed again is an error that says what to do; TerminalDevice::component(),
      Component::of(); tests/Release/IdentityTest.php
- [x] 3. Hidden components are not in the focus order (df5672f): waitsUnshown()/pendingRank();
      tests/Component/FocusHiddenTest.php
- [x] 4. Small (6205e53): --headless=/TUI_HEADLESS, overlays outside a bordered window's only
      content (Component::framed()), Tui::go(), Table keeps its place, Dialog at most 80%,
      Tabs docs, cookbook plural; tests/Release/SmallBeta3Test.php, FailureTest modes
- [x] docs (components.md "Without a class", cookbook dialog without a class, README), CHANGELOG

## Design decisions (0.0.3-beta3)

- **Input is dispatched in a coroutine of its own** (TerminalDevice::deliver()/dispatch()). A
  handler that waits (`fiber\await(Confirm::ask(...))` in a key handler) parks only its
  dispatcher: when the caller of deliver() runs while `dispatching` is set, that dispatcher is
  waiting in a handler (single-threaded: it is not running), and a new dispatcher (a token,
  not a task handle) takes the events after it. Order is kept: one dispatcher at a time takes
  events, the parked one ends when it resumes. A dispatch from inside a dispatch (reentrant,
  `fiber\current()` === the dispatcher's task) only queues. FakeTerminal sets `untilHandled`:
  its input calls return when the handlers are done, or while a modal dialog is open
  (Component::waitsForAnswer(): the handler waits for the answer the next input gives), or
  after a second; an exception of the dispatch is thrown to the caller as before. So every
  handler (bound methods, onKey, closures) may wait, with no second kind of handler.
- **Bound closures take the key**, whatever they return: `fn() => $s->open = false` returns
  false and did take it. Methods keep "false = not taken". An element's single `onKey`
  closure returns true when it took the key (it sees every key, so Tab and Ctrl-Q must pass).
- **Element handlers live in the Tree.** Every element surface is registered (static WeakMap
  `Tree::$shows`: surface -> [tree, path]) with the element of the last build (`$nodes`), so
  a handler always runs the closures of the latest render. Component::key() at the key end
  runs the onKey of the elements of its tree on the focus path (innermost first) before its
  own bindings; parents run theirs before their bindings in the bubbling walk. focusables()
  returns Component|Surface: element surfaces with onKey are Tab stops. A press on an element
  with onKey focuses it. Root containers ('r', the owner's surface) are not in the static map
  (the owner's surface is the component's in the tree around it).
- **onMouse**: presses/drags/releases are taken unless the handler returns false (else a
  statement closure would let the host start a text selection and steal the release), the
  wheel only on true (it scrolls as usual otherwise). Capture is the hosts' own (press taken
  at every level). `Mouse::over()` is lazy (a finder closure carried by `at()` copies).
- **Kept instances** (item 2): ambiguous by nature: a fresh-per-render `new Editor()` and a
  kept `$bars[$i] ??= new ProgressBar()` look the same in the render that first shows them.
  A started instance in a tree is placed as itself; a new instance does not take over a
  child that the same tree shows as itself (`$present`) or that has been placed under another
  container object than the one before (`placedUnder()`: the program kept it across renders).
  The gap left: an instance shown in exactly one render, left out in the next, whose place a
  new instance of its class takes, is handed that instance's props (as 8.1 matching does).
  Documented: give kept instances a `key:` to be safe from the first frame.
- **A hidden component keeps no keys** (item 3): Component::unshownFocus() considered every
  unshown child, also one a Tabs shows (the owner then passed keys straight to the hidden
  shell, past the Tabs) and one a render stopped showing. A held child routes through its
  holder; a child hidden after its latest focus request (`hiddenRank`, set on `area` when the
  viewport goes) is skipped until focused again.
- **Overlays outside the frame**: Component::framed() extends an overlay's anchor to the
  border of the window the opener fills alone (its host has one viewport and a border), so
  Placement puts a list under the Box, or above it; a cursor anchor inside a multi-line
  editor is not changed.
- **A size in cells is the size it needs** (LayoutItem::measureNow()): a Box of height 8 in
  a Row made the Row as high as the Box's content.
- **Tabs props**: documented (the tabs are given once), not changed: a Tabs built anew per
  render would need its contents matched by tab key and class to keep their state, which the
  childKey by object id does not do. Left for a later version if wanted.

## fiber\ finding (0.0.3-beta3)

- **No way to learn that a task nobody awaits has failed.** TODO item 4 asks that an
  exception in any coroutine the program started inside `fiber\run()` ends the program at
  once, terminal restored. fiber-interop/api catches such an exception in the task wrapper
  (Frame::start(): `$entry->error = $e`) and keeps it for the enclosing `run()`, which throws
  it only when everything inside has finished; there is no hook. A plain `fiber\go()` that
  fails therefore leaves the UI running until the user quits; then (terminal restored) the
  message comes. The library does without, in the plain way: `Tui::go()` starts the program's
  coroutines in a wrapper of its own, disposes the running root on an exception and rethrows
  it out of `Tui::run()`. The smallest fiber\ addition: a per-run failure listener
  (`fiber\run($f, onFailure: fn(\Throwable $e) => ...)`) or a `run()` option to fail fast
  (stop waiting and rethrow at the first unawaited failure).

## Status (0.0.3-beta2; TODO-0.0.3-beta2.md, SPEC 9.4-9.6)

All items of TODO-0.0.3-beta2.md done, in CHANGELOG.md. Tests green with and without
ext-phasync.

- [x] 1. Themes (b1a7326): Stylesheet::theme(dark|light|mono), Component::setTheme()/theme(),
      TerminalDevice::background() (OSC 11 in the attach probe), FakeTerminal background:,
      Tui theme:, dialog shadow (Viewport::setShadow), constructor args; tests/Release/ThemesTest.php
- [x] 2. Cookbook (e603518): docs/cookbook.md, tests/Docs/CookbookTest.php (each recipe eval'd
      with Tui::run/inline replaced by a FakeTerminal runner, at 60x20 and 160x50)
- [x] 3. examples/dashboard.php (d4ddcae, 59 lines), README opens with it; tests/Examples/
      DashboardTest.php (eval, three themes, two sizes, inline, flicker bound) and a pty run in
      ExamplesTest
- [x] 4. freeze(): not reproduced in this tree; tests in the reported shapes (tests/Release/FreezeTest.php)
- [x] 5. Variants on elements, min-width breakpoints, onFocus/onBlur (tests/Release/VariantsTest.php)
- [x] 6. Small items (tests/Release/SmallTest.php)

Item 4 could not be reproduced: two jobs and eight, in a Column and with Rows, with the bar
set to 1.0, a frame's sleep, `freeze($bar->surface)` and then the State change that drops the
row, freeze the right rows at 100%, with and without ext-phasync, and the byte stream replayed
into a fresh emulator shows the same. `freeze()` already lays out pending renders first
(beta5), which is what the report asks for. The tests stay as regression tests.

No gap in fiber\ was met in this version: the theme probe answer (OSC 11) arrives through the
device's input coroutine and `whenProbed()` listeners, timers are `fiber\sleep()`.

## Design decisions (0.0.3-beta2)

- **Themes are stylesheets; a program's sheet is replayed on the theme.** `Stylesheet::theme()`
  builds (and caches) dark/light/mono from one rule list with three variable sets; mono adds
  attribute rules and sets `$mono`, which makes `color()` return DEFAULT for every colour, so no
  colour of a class, rule or markup gets through. `with()` on a themed sheet records its
  `[rules, vars]` in `own`; `onTheme($name)` replays them on that theme (cached per sheet and
  theme). `Component::stylesheet()` resolves the nearest sheet through the nearest theme
  (`setTheme()`, else the device's background, else dark). So the theme can change at run time
  and a program's rules always come after the theme's.
- **OSC 11 is part of the attach probe**, with the kitty query, XTVERSION and DA1: DA1 ends the
  probe, so a terminal that does not answer OSC 11 is known at once to be silent (dark). The
  answer's rgb luminance (> 0.5) decides light. `Component::start()` on a device registers
  `whenProbed()` to render again when it said light: start-up never waits.
- **Shadows** are a viewport property composited by the host: `Host::compose()` collects the
  shadow rectangles of every window and gives each window the shades of the windows above it
  (`Viewport::paint($shades)` dims and sets the background there). A shadow falls on windows
  only, not on what the host draws itself; that keeps it in the one compositing pass and costs
  nothing for screens without one. A changed shadow marks its rows as the host's own rows so
  they repaint.
- **Border colour passes to the inner Box** (`ComputedStyle::$innerBorder`): a border colour
  from an element's own classes/inline style on an element with no border (a component) is
  carried down to the nearest bordered descendant whose own classes set none. A colour alone
  no longer turns a border on (it did in beta1): that made `border-red-500` on a component draw
  a second border. Border values are `[kind, colour, attrs]`; `bold`/`dim`/`plain` tokens.
- **hover on elements**: Tree::states() asks `Component::pointerOver($surface)`, so an element
  is in `hover` when the pointer is over its rectangle. Pointer following is requested by the
  presence of any `hover:` class (Stylesheet sets hoverAware) or `:hover` rule, and the
  component re-lays out only when its hover key (which hovered element, `hoverKey()`) changes,
  not on every motion.
- **onFocus/onBlur** are collected by Tree::states() while building (which elements are on the
  focus path now) and diffed against the previous build (`takeFocusCalls()`); they run after
  the render, blurs before focuses. A focus that comes and goes within one frame is not
  reported, by design: the callbacks describe frames, not every intermediate `focus()`.
- **Min-width breakpoints** (`Stylesheet::breakpoints($cols)`, a list): each breakpoint from its
  width up is a state; classes are applied ordered by breakpoint rank (`byBreakpoint()`), so
  `md:x lg:y` gives y from 100 whatever the written order. Supersedes the beta1 exclusive
  ranges below.
- **Inline roots at natural height**: Tree::build() lays the root of an inline device's tree
  out in a Column item at its natural height, so `Tui::inline(fn() => new Row(...))` is as tall
  as the boxes and not the terminal (a `'1fr'` there would otherwise take all rows).
- **Row children measured at their share width**: containerMeasure for a Row measures each
  child's height at the width the layout gives it (`ElementLayout::rects()`), not at the row's
  width. This measures a child twice when its share is narrower than its natural width
  (RenderCostTest's probe bound is 2 for that); the alternative, a one-pass measure, gets
  wrapped text wrong.
- **Constructor args are inline style** (`Children::style($style, [...])`): `padding:`,
  `align:` are written into the element's inline style, an explicit `style:` winning, so they
  follow the cascade like everything else and need no new fields.
- **Tabs remembers closed keys** until the program stops listing them or names one as
  `$current`; `replace()` is the internal setter so `close()` does not go through the filter.
- **Select::setItems()/Table rows** compare with `===` (value equality on arrays) before
  remeasuring; O(n) comparison, far cheaper than measuring markup.

## Status (0.0.3-beta1; TODO-0.0.3-beta1.md, SPEC 9.1-9.3)

All items of TODO-0.0.3-beta1.md done, in CHANGELOG.md. Tests green with and without
ext-phasync. SPEC 9.4 (good by default, beyond the default focus and Tab walk done here), 9.5
(what a programmer no longer meets) and 9.6 (the sixty-line dashboard acceptance test) are left
for the next version.

The 9.1 example cannot run as SPEC writes it: `fiber\go()` at the top level of a script throws
`NotInRunException` (fiber-interop/api allows `go()` only inside `run()`). Everywhere the
example appears (README, Tui docblock, docs/components.md, examples/working.php, the tests) it
is wrapped in `fiber\run(function () use ($s) { ... })`; the runners nest inside it. See
"fiber\ notes".

Item 5's detach() hang ("detach() with a Terminal child returns") could not be reproduced:
a Terminal in a child, in a hidden tab, in a shown tab, and a program that ignores SIGHUP all
return at once and leave no process (tests/Component/Small031Test.php covers each shape).

## Design decisions (0.0.3-beta1)

- **A closure root is a component (`Internal\Live`).** `Tui::start()` calls the closure once,
  as `Live`'s render would (`Component::readAs($live, $closure)`, so the State reads subscribe
  the Live). If it returned a component and read no State (`State::reads()` unchanged), that
  component is the root (the old `fn() => new App()` factory); otherwise the Live is the root,
  primed with the first result (`Live::prime()`) so the closure is not called twice for the
  first frame. An element given directly is wrapped in a Live too.
- **State reads subscribe the component rendering.** `Component::$reader` is set only around
  `render()` (not hooks, not handlers): a read there subscribes the reader to that State (weakly,
  as `$state` did). So any State read in render works, and the closure root needs nothing more.
  Reads outside render subscribe nothing (a handler reading state must not make its component
  render on every write).
- **Element closures** (`Text` content, `Box` title, `ProgressBar` value): Tree places a `Live`
  for a Text/Box whose content is a closure (`Text::resolved()`/`Box::resolved()` give the
  static element), so only that Live re-renders when its State changes; the placing component
  does not. ProgressBar is already a component: it evaluates its closure value in its own
  render.
- **Utility classes resolve to stylesheet properties** (`Stylesheet::utilityProperties()`,
  cached per class string). The cascade is rules by specificity, then the classes in written
  order, then inline style. Variants are states (`focus` → `focused`, `sm`/`md`/`lg`).
- **(Superseded in 0.0.3-beta2 by min-width breakpoints.) Width variants are exclusive ranges** (sm < 60, md 60-99, lg >= 100), not Tailwind's
  min-width cascade: each is a state of the screen (`Stylesheet::widthClass()` of the root's
  width) that Component::states()/Tree::states() add, so `flex-col lg:flex-row` works by
  ordinary state matching and a resize across a breakpoint re-renders through the existing
  state machinery. Tailwind's "md and up" is written `md:x lg:x`.
- **Padding and margin sides** are stored in the merge as `['side' => i, 'of' => value]` so a
  `$var` or an array value resolves after the cascade; `p-1 pt-2` gives top 2.
- **Colour depth in one place**: cells keep the chosen colour; `TerminalDevice::sgr()` maps it
  with `Color::nearest()` (redmean distance, cached) when the device has fewer colours. The
  Tailwind palette is data (src/Style/tailwind.php), loaded on first use.
- **Markup** (`Markup::toAnsi`) converts tags to SGR once per (text, base style) and caches it
  (4096 entries); unknown or unbalanced tags stay literal, so text that only looks like markup
  survives. `<span class>` computes through the stylesheet as a `Span` element, so a theme's
  `Span.x` rules and utilities apply. Text::raw() opts out. Log keeps raw lines (lines()) and
  converts per shown line; a line still open is rewritten whole when it contains '<' so a tag
  may span appends. Markdown converts markup before entity decoding; markdown's `\<` becomes
  `&lt;` so it stays literal. A Box title with markup is rendered into a scratch Surface and its
  cells spliced into the border row (`Viewport::titleCells()`).
- **Table measures on row change**: `shown()` (order after sort and filter) and `widest()`
  (auto widths) are cached by the identity of `rows` plus the sort and filter keys; a move only
  maps key ↔ position. Subclasses filter through `filterRows()`/`filterKey()` (the name
  `filter()` was taken by Select's public API). A program that mutates rows in place must assign
  `rows` anew (or requestRender after setting) as with any prop.
- **Default focus**: after the root's first render and after each frame, when the default key
  end (`keyEnd(root)`) does not take keys itself, the first focusable component (by viewport y,
  x; skipping zero-size, disabled, non-component surfaces) is focused; a program's own
  focus() on something that takes keys is never overridden. **Tab walk** only when nothing on the key's way handles Tab and the
  focused component does not take its own keys (`ownKeys()`, the default of `canFocus()`), so a
  shell or an editor keeps Tab. `Terminal::canFocus()` is true.
- **ProgressBar** compares the string it would draw (`drawing()`) with the one it drew and
  writes nothing when equal; `setValue()` requests a render only when the drawing differs.
- **Inline taller than the terminal**: no new mechanism; the region is rows-1 high, the layout
  decides what is left out, and zero-size windows are not painted (ElementLayout skips paint for
  w or h 0). Documented in docs/terminal.md.

## Status (0.0.2-beta5; TODO-0.0.2-beta5.md)

All items of TODO-0.0.2-beta5.md done, in CHANGELOG.md, except one target of item 1 (the
50-row monitor at "a few percent of a core": 12-15% now, with a runtime floor of 6.5%; see
the findings below). Tests green with and without ext-phasync (fiber-interop/api 0.3.x-dev and
fiber-interop/phasync 0.4.x-dev: still no tags newer than 0.3.1 / 0.4.1, so the dev pins
stay). Tagged 0.0.2-beta5 with that target runtime-bounded (an earlier tag at ba85f5b was
moved to the final commit).

Re-measured at the end (tools/monitor-bench.php, 6 s each, idle machine):
bare 6.4% (2.5% with zend.enable_gc=0), some 12.6% (8.3% without GC), all 53.7%. So of
"some", ~4% is phasync's idle collection, ~2.5% the runtime's wakes, ~6% the library
(governor-inflated; ~2-3% back to back). Profile of "some" (excimer, GC off): the step
renders that return an equal tree (showsAgain/sameValue ~9%), Signal::wait and fiber\await,
the changed rows' writes and parsing (~20%). Even a library costing nothing would leave the
runtime's 6.4%: the target needs the runtime's idle collection to change (a finding for
phasync, not something to work around from here).

Left for item 1, all structural (each a change of the layer, not a patch):
- Text elements painted into their container's surface instead of a surface of their own
  (one level less of write, parse and diff for every Text; Layers and focus would need care).
- The generated parser costs about 2.5 µs per item beside about 1 µs in the emulator's action;
  a compiler that emitted a first-character dispatch for Item would take most of it (that is
  charm/parsing's code generation, not the grammar).
- About 6600 closures (listeners) in the monitor's heap make each idle collection 4-5 ms; fewer
  would shorten the runs, but phasync's 5% duty cap would stay while the loop idles often.

- [~] 1. Render cost (624ba14, a9a05e6, 3946cc2; continued in 8c15d07, b6114ea):
      Stylesheet::compute() memo, Tree items and ElementLayouts reused by path and signature,
      ElementLayout keeps placed windows, measure by the built items, LayoutItem::natural()
      memo, Component::measureFor() remembered until markDirty()/render, focus settled only
      when it may move, hasFocus() cache, Host composes only stale windows, one-terminal CSI
      and ESC in the grammar, runs of single-width cells printed at once, showsAgain() (an
      equal tree keeps layout and paint), Signal timeouts on one shared clock; then: renders
      with the device's frames, re-renders of the same frame in that frame, Component::sleep()
      on a 60 Hz frame clock, a determinate ProgressBar without a coroutine, whole-row copy and
      compare in Viewport::paint(), covers filtered by overlap, a minimal compositing wrapper
      and paint preludes. Numbers (FakeTerminal 120x60): flat 50x Row[Text,Text] 1.3 ms a frame;
      monitor of 50 row components at 10 Hz: 14-15% of a core with one row in 20 changing
      (was 20%), 55-57% with every row every tick; key to frame 0.2 ms median, 2 ms max.
      **Not met: "a few percent" for the monitor.** It cannot be on phasync: see the finding
      below (the runtime alone is 6.5% for this program, 4.5% of it idle GC). Tests:
      tests/Benchmark/RenderCostTest.php (budget, work counts, frames at most 60/s, a parent's
      relayout in the same frame), StateTest (frame clock), SmallWidgetsTest (ProgressBar
      idle), SequencesTest (ESC with and without embedded controls); docs/surface.md
      "Performance" (numbers, measuring, big trees), docs/components.md "Frames".
- [x] 2. Tabs: components inside element contents kept (tabComponents()) and focused
      (focusContent(): first canFocus(), else first; else the bar); content($key);
      tests/Widgets/TabsFocusTest.php (both shapes, keys, select(), click, child() ownership).
- [x] 3. Tall dialogs (a71ea0e): LayoutItem::least()/ElementLayout::least()/
      Component::leastRows() give the fewest rows content needs, ScrollView sizes its content
      at least that; Size::distribute() gives 'auto' items their need when all fit with the fr
      items' least; ScrollView::follow() shows the largest frame around the focused field that
      holds no other field; Viewport::setFollowOnInput(false) for the ScrollView's window;
      tests/Widgets/DialogTallTest.php (80x14, 80x16, 40x12, scrolled dropdown, 80x30->80x14).
- [x] 4. Component::device() / TerminalDevice::of(); Surface::flush() (pending renders before
      freeze()/refresh()/text()); child() given to Tabs stays the owner's (heldBy); final
      library methods, bindings invoked by reflection (a user's key()/end()); focus() before
      start(); docs/components.md on where a coroutine lives; tests/Component/SmallBeta5Test.php.

## Design decisions (0.0.2-beta5)

- **Renders go with the device's frames.** markDirty() of a component whose surface root is
  attached to a running TerminalDevice only damages the device (TerminalDevice::drawsFrames());
  the device's frame loop refreshes at most 60 times a second, and its refresh flushes (renders)
  first. Rendering faster than the terminal draws is wasted, and fifty coroutine steps that
  fell in one frame were fifty render passes, composes and draws, each with a go() of ~30-45 µs.
  Trees not on a device keep the go(sleep(0)) frame. A request during a refresh sets
  `frameAgain` (the next frame). The cost: a render may come up to 1/60 s after the change
  instead of in the next tick; tests that slept 5-10 ms before looking at a component (not the
  screen) now use frame() = 1/60 + 5 ms. Key latency is not worse: draws were paced before too,
  and a frame after a quiet 1/60 s is drawn at once.
- **Re-render in the same frame.** A component asked for again by a render of the frame (a
  child whose size changed relayouts its parent after the parent rendered) renders again in up
  to three more passes of the same frame; before, it waited for the next frame, which drew the
  half-done layout for one frame (with device frames that would have been visible for 16 ms).
- **Sleeps on a frame clock.** Signal::wait(inStep: true) (Component::sleep()) ends at a 1/60 s
  tick: the first at or after (end - 2 ms). Loops begun at different moments wake together (the
  runtime then wakes once per tick group instead of once per coroutine), and a sleep(0.1) loop
  stays at ten steps a second (rounding up alone made it 8.6). Not for Pty, Input or the
  device's own waits.
- **No skipping of step renders.** A step that wrote no state still renders: a coroutine that
  polls data outside the component (a clock, a job object) relies on it. Comparing the
  component's properties would miss changes inside objects it holds. The step render is cheap
  instead (an equal tree: ~35 µs) and batched per frame.
- **Compositing wrapper.** DECRC restores the pen, so the trailing SGR 0 went; origin mode, SI,
  G0 and the link close are written only when the host's producer has them on. Text paints and
  the ProgressBar set `?7;25l` in one sequence and restore nothing (their surfaces are theirs).
- **Finding (runtime cost, phasync adapter, PHP 8.5 without JIT):** `fiber\go()` about 29 µs
  (with a sleep(0) in it 45 µs), resolving a Deferred and resuming its waiter about 12 µs,
  `fiber\sleep()` about 7 µs. 50 coroutines in a `fiber\sleep(0.1)` loop with phases spread
  over the 100 ms cost 6.5-6.9% of a core even without any TUI in the process; in phase, 1.1%.
  Of that, phasync's idle collection (EventLoop::tick: gc_collect_cycles() whenever the loop is
  about to idle, roots exist and 20x the last collection's time has passed, i.e. up to 5% of
  the time) is about 4-4.5%: in the monitor each run takes 4-5 ms over ~150k zvals and collects
  nothing. The library cannot avoid roots (any decref of an array or object makes one) and
  should not touch the collector (gc_disable() would be going beneath the runtime). So the
  50-row monitor has a floor of about 6.5% of a core on phasync before it renders anything,
  which is why item 1's "a few percent" is not reachable; the library's own share is about 8%.
- **Finding (measuring):** the machine's CPU governor is schedutil. The same frame of 20
  changed rows cost 412 µs a row back to back and 1035 µs a row with 40 ms of idle between
  frames (tested with the same code in one process). Percentages of a core for bursty loads
  here overstate the work about 2.5 times; frame costs measured back to back are the honest
  numbers.

- **Least height, not a minimum attribute.** A ScrollView must not squeeze, but its content
  may hold something that takes any room (measure null), which made the whole content measure
  null and get laid out at the view's height. `least()` walks the items: a known measure is its
  height, a container sums (Column) or maxes (Row, Layers) its items' least, a component asks
  its built tree (leastRows()), a filling leaf is one row. A component whose least changes
  after a render relayouts its parent like a changed measure.
- **Distribution when it fits.** The old rule capped 'auto' items at a fair share even when
  the room sufficed for all of them, so a 3-row Box beside a filling Editor got 2 rows. Now the
  fair-share rule only applies when the auto needs plus the fr items' least do not fit.
- **A measure stands until a render.** Component::measureFor() caches per room and is cleared
  by markDirty() and after each render pass; the size check after a render uses it too. The
  contract: measure() depends on what goes with a render. This is what keeps a parent's render
  from measuring fifty unchanged row components.
- **Typing follows the bottom, except in a ScrollView.** Host::route() makes a viewport follow
  the source's bottom on keys (terminal behaviour). The ScrollView's window was thereby at its
  bottom between a key and the next arrange, and an overlay anchored then saw its field
  outside the view and closed. The window now opts out (Viewport::setFollowOnInput(false)).
- **Finding (fiber\ costs):** on the phasync adapter `fiber\await($d, $timeout)` costs about
  4x a `fiber\sleep()` and `fiber\go()` about 30-45 µs; 50 coroutines each with a timed wait
  per tick dominated the monitor's idle cost. Worked around inside the API: Signal keeps one
  clock coroutine for all timed waits (a static deadline list), waits are plain Deferreds.
  The remaining idle cost of 500 wakes/s is the runtime's loop and fiber switches.

## Status (0.0.2-beta4; TODO-0.0.2-beta4.md)

All items of TODO-0.0.2-beta4.md done, in CHANGELOG.md; tests green with and without
ext-phasync (on fiber-interop/api and fiber-interop/phasync main, see the fiber\ notes).

- [x] 1. A failing starter ends the program: Internal\Runtime::watch() from Component::start()
      (non-device targets) and TerminalDevice::attach(); Tui::run()/inline()/headless() with
      quitOn('ctrl+q'); tests/Component/FailureTest.php (FakeTerminal and a forked pty process,
      tests/fixtures/programs/crash.php: stderr message, modes reset, non-zero exit within 1s,
      for the run closure, attach(), a run() coroutine, a go() coroutine and Tui::run())
- [x] 2. Overlays in a dialog across resizes: placement passes in renderFrame(), immediate
      arrangeOverlays(), the side kept per [anchor, root], Placement::for(keep:) for the
      dialog's buttons, Select's list width following the field, ScrollView onResize;
      tests/Widgets/DialogResizeTest.php (sizes, too small, nested instance, storm)
- [x] 3. widget/Tree and widget/Tabs merged (5e8b7e0, b6d1c3f) and aligned in 3f3bba8; README
      tour entries (Tabs with a Terminal, Tree of 100k), run in tests/Examples/ReadmeTest.php
- [x] 4. wantsPointer() and part :hover; keep() and public final stop(); 'first' bindings and
      the key order paragraph; parent/child reactivity rule; parts in the row state;
      awaitables convention; focusNext()/focusPrevious(); Column of row components (default
      measure() by the shown tree); tests/Elements/ContractTest.php
- [x] 5. Dialog accept/submit guard (Form::hold()), Form::values() after close, popup side
      stickiness, ProgressBar label + percent + placeholders + no overdraw, freeze();
      tests/Widgets/SmallFixesTest.php

## Design decisions (0.0.2-beta4)

- **The watcher is gated by version, not probed.** Awaiting `fiber\current()` from a task
  started inside it re-runs the run() closure on api 0.3.1 / adapter 0.4.1 (the beta2 finding),
  so `Internal\Runtime::canWatch()` reads InstalledVersions and refuses numeric versions at or
  below those; dev branches pass. A probe would have to run the broken path to find out.
  `Runtime::$watching` overrides it for tests. Tui::run() owns the run closure and so needs no
  watcher at all; it is the documented fallback. The watcher rethrows after disposing, so
  fiber\run() reports the original exception, not a wrapped one.
- **Tui is only the runner** (SPEC 9.1's runner part); the rest of SPEC 9 is still 0.0.3.
  The quit key is a property of the root (quitOn()), handled after every binding and onKey
  had their turn, so a component binding Ctrl-Q wins.
- **Default measure() measures the shown tree dry.** A component without measure() is measured
  by Tree::measure() of the element it rendered last, with `place: false`: nothing is mounted
  or placed, unmounted components measure as empty items. The first version placed components
  from a stale tree and disposed a Markdown child of a hidden tab.
- **Overlay placement in passes.** renderFrame() renders the dirty components, then places the
  overlays in opening order; an overlay whose rect changed is arranged at once (so an overlay
  opened from it anchors to the new position in the same pass) and, when that made something
  dirty, the frame renders again (at most 16 passes). The side an overlay opened on is kept
  while [anchor, root] are unchanged; a resize chooses again.
- **'first' bindings** are the 4th element of a normalized binding. On the way down, a
  component that is not where the key ends runs its first bindings before onKey(); after the
  focused component, the parents' walk skips first bindings of parents the key already passed.
  One flag rather than a second bindings() method, so Help and bind() need nothing new.
- **keep()** records keys in `$kept`; renderNow() does not dispose kept children it did not
  see. stop() unsets the key in the parent. Tabs uses it for component tabs and stops the
  children of tabs setTabs() dropped.
- **Form::hold()** is set by Dialog::choose() around accept(); a held submit() validates
  nothing new and returns whether the last one was valid. values() keeps the last submitted
  values for after unmount.
- **freeze()** inline pushes the row's cells (as rowData) into the printed queue, exactly
  what a scrolled-off row does; full screen it holds the row's text with SGR for detach, as
  println() does.

## Left for later versions (0.0.2-beta4)

- Tag-based requirement: once fiber-interop/api > 0.3.1 and fiber-interop/phasync > 0.4.1 are
  tagged, require them (`^0.3.2` / `^0.4.2`, whatever they are), drop the dev require-dev and
  the gate's version table can stay as is.
- ReadmeTest's first program (a real shell in a pty) failed twice in about ten full runs
  during beta4, both times inside its run closure (once the shell's echo took the whole 5 s);
  it passed on every rerun, and 8 isolated runs under 4 busy CPU cores did not reproduce it.
  Cause not found; suspect the shell's startup under the suite's other ptys.
- SPEC 9 apart from the runner (utility classes, markup, themes, the dashboard test): 0.0.3.
- Table and ScrollView still scroll with their own code.

## Status (0.0.2-beta3; TODO-0.0.2-beta3.md)

All items of TODO-0.0.2-beta3.md done, in CHANGELOG.md; tests green with and without
ext-phasync. SPEC section 9 (commit 5110407, "phase 3, from 0.0.3") arrived during this
version; it is for 0.0.3 and not started.

- [x] 1. Defects, tests in tests/Widgets/DefectsTest.php and tests/Elements/SizingTest.php:
      overlays measured every frame (the Editor pinned its completion's height; now
      max-height), sized Dialog (mount() measures the tree), container 'auto' from children
      (Tree makes containers 'auto'), Form in Dialog (Form::enter(), `submit` event,
      Dialog::choose() submits its forms)
- [x] 2. Inline mode: TerminalDevice::drawInline(), println(), Tty::inline(),
      FakeTerminal::inline()/frozen()/region(); tests/Terminal/InlineTest.php;
      docs/terminal.md; examples/inline-progress.php
- [x] 3. widget/Markdown merged (ff695b1) and moved onto Scrollable and composed parts; OSC 8
      in the cells; supportsHyperlinks(); Scrollable used by Log and Markdown; docs
- [x] 4. dialog(key:); overlays on the root documented; CHANGELOG; README tour (Markdown pane,
      inline progress; both run in tests/Examples/ReadmeTest.php)

## Design decisions (0.0.2-beta3)

- **Inline model.** The root is cols x (rows - 1): the row under the region is where the
  cursor parks, so a region as high as the terminal never scrolls the terminal by itself (the
  first try sized the root rows x cols and a height shrink duplicated a row). The region is the
  rows down to the last non-blank one, or the cursor's row when the cursor shows. Rows that
  leave the root's top (Surface::linesScrolled() deltas) and println() lines are written at the
  region's top with LF pushing the frame down, then the region is redrawn from there. Only
  CUU/CUD/CR/CHA/LF: no CUP, no DECSTBM, so the shell's output above is never touched. A width
  change redraws the region; a height change shifts it by what the terminal lost.
- **Inline restore after a crash** can not put the cursor back under the region (the device
  does not know where the terminal's cursor is); it restores modes and moves to column 0.
- **println() full screen** holds the lines until detach, then writes them on the normal
  screen; detached it writes at once.
- **Hyperlinks** are part of Style (interned with link and id) so the cell model needs no
  second array; SGR 0 does not end a link (as in terminals). DECSC/DECRC save it with the pen.
  A host's wrap() closes a link the producer left open at its window's edge. The device
  writes links only when supportsHyperlinks(); Tty decides from the environment (a table in
  Tty::hyperlinksFromEnvironment()) and XTVERSION, false inside tmux/screen even when the
  outer terminal could (tmux drops them unless configured).
- **Scrollable** keeps [top, following] in the trait by default; Log overrides
  scrollState()/setScrollState() to keep them in its Viewport (absolute = top() +
  scrollbackSize), so the emulator's own scrollback stays the storage. Moves return false at
  the edges so the keys go on (to the next field, to the parent).
- **Composed parts**: Stylesheet::compute() with a list folds resolve() over the parts, each
  inheriting from the one before, so "attributes add up, innermost colour wins" is the
  inheritance rule and nothing new. A part may turn an attribute off (`bold: false`).
- **Markdown review**: own scroll code replaced by Scrollable (j/k/Space dropped as defaults,
  bindable); combine() replaced by style([...]); `\e[0m` resets replaced by the Markdown's own
  style so a themed background fills; links through Ansi::link() (control characters
  stripped); key/id/class/style taken by the constructor like the other widgets.
- **A root's dispose renders pending frames first** (Component::dispose()), so the last state
  a program set before quitting is the frame an inline device leaves. Only from dispose(), not
  from the failure path, so an error in that render reaches the caller.
- **Form in a Dialog**: the Dialog subscribes to each Form's `submit` (found by walking its
  children at mount and every render) and answers its first button; its buttons submit the
  forms first unless the button is the cancel answer. Form's Enter is bound to enter(), which
  returns nothing: a failed validation must not let Enter go on to the Dialog.

## Left for later versions (0.0.2-beta3)

- SPEC 9 (Tui::run, utility classes, markup, themes, the dashboard test): 0.0.3.
- Table and ScrollView still scroll with their own code; they could use Scrollable.
- `echo` from coroutines is not captured into the inline scrollback (println() is the way).
- Markdown: reference links, footnotes, task boxes, raw HTML (shown as text).

## Status (0.0.2-beta2; TODO-0.0.2-beta2.md, SPEC 8.5)

All items of TODO-0.0.2-beta2.md done, in CHANGELOG.md; tests green with and without
ext-phasync (503 tests).

- [x] 1. Quitting with dialogs open (tests/Component/QuitWithDialogsTest.php: the exact shapes,
      a forked child so a hang fails, a randomized round of opens/typing/quit moments); the
      key rule under a modal (opener suspended except `always` bindings; parents' work)
- [x] 2. Overlays: src/Overlay.php, src/Placement.php (tests/Layout/PlacementTest.php),
      Component::overlay()/dialog()/inOverlay(), Internal/OverlayLayout, Internal/Floating;
      tests/Elements/OverlayTest.php; Dialog/Confirm as overlays; Select dropdown; Editor
      completion
- [x] 3. Size::distribute(): 'auto' = what it needs, capped at a 1fr share (tests/Elements/SizingTest.php)
- [x] 4. canFocus(); drags unclamped to the pressed window (tests/Component/FocusSelfTest.php)
- [x] 5. Editor: maxLines = rows shown, no joining; grows; history; complete; not final; insert()
- [x] 6. Dialog::accept(), Tab content-then-buttons (Buttons), content-sized, ScrollView;
      Select filter mode
- [x] 7. Button, Checkbox (toggle), ProgressBar, Spinner, Form + Field; README + examples/form.php
- [x] 8. setStyleState()/hasStyleState(); pen = component style before render(); Help one row, `show:`
- [x] 9. docs on `new` children not re-rendering; FakeTerminal::settle()/run(); children()
- [x] 10. docs, CHANGELOG

## Left for later versions (0.0.2)

- (done in beta3) SPEC 4.1 **inline mode** (`Tty::inline()`, `FakeTerminal::inline()`, the root's scrollback as
  the terminal's) was added to the spec while this version was being built (commit 3281ee8)
  and is not in TODO-0.0.2-beta2; not started. Nothing in this version is in its way: the
  device layer is unchanged, so it is a second painting mode of TerminalDevice (relative
  cursor motion, rows scrolled off the root's top written once above the region).
- Widgets of SPEC 8.6 not in this TODO: `Radio`, `Tabs`, `Tree`, draggable `Split`
  separators, `Terminal` `onOutput`, `Markdown`.
- Overlays are hosted on the root component, not on "the nearest `Layers` above" (SPEC 8.5):
  the root always has one and is the boundary; a nested instance's root is its pane. A
  `Layers` element in the middle of a tree does not host overlays of its own.
- `ScrollView` scrolls by whole rows of its content surface; a focused element taller than
  the view shows its top.
- `:hover` only for components; Stylesheet has no combinators (`Dialog Button`).
- Text elements do not scroll by themselves (put them in a `ScrollView`).
- Editor: no double-click word selection; a drag above or below it does not scroll it.

## Design decisions (0.0.2-beta2)

- **Overlays live on the root component.** `overlay()` makes the element a child of the opener
  (key "\0o:N"; a plain element is wrapped in Internal\Floating, which renders it in a
  ScrollView) and registers it with the root (`$floating`, in open order). The root's layout
  is wrapped in an OverlayLayout (inner layout, or none for a self-drawing root, then each
  overlay at its rect, raised in order). Placement runs at the end of every renderFrame for
  every root with overlays (`$floatingRoots`), i.e. after the layout pass and before the
  device paints; it computes the anchor from the opener's `area()` relative to the root's,
  the desired size from `width`/`height` attributes or `measureFor()` (so a content whose
  measure changes makes the opener render, and the next pass re-decides), and arranges only
  when a rect changed. OverlayLayout clamps the stored rect to the host, so a resize that
  arranges before the pass never puts anything outside. The same pass cancels overlays whose
  opener is gone, not shown (`isShownIn()` or empty area), or (non-modal) lost the focus after
  having had it (`wasFocused`).
- **Ownership = lifetime.** The overlay's content is the opener's child, so disposing the
  opener stops it; Component::stop() of a content cancels its overlay first (closes with the
  cancel value, unhosts at once, restores the focus if it was in the overlay or it was
  modal). That is what makes quitting with dialogs open end everything: the Dialog's unmount
  resolves its result with `$cancel`, the awaiting coroutine goes on, the opener's end follows.
- **Keys under a modal.** `suspended()` = an open modal overlay of this component. key(): the
  key end's own bindings/onKey unless suspended; then up the parent chain: each parent's
  bindings (only `always` ones when that parent is suspended), and Escape cancels a non-modal
  overlay when the walk passes its content. Overlay contents' parent is the opener, so a
  dialog's keys reach the opener's parents (the application) without the opener being on the
  surface focus path. The root's capture onKey is skipped while the root is suspended.
- **Mouse.** The root's mouse listener (`overlayMouse()`, after the hover pointer) takes
  everything outside the topmost modal overlay, and cancels non-modal ones on a press outside
  them and their opener. Captured drags: Host passes unclamped coordinates to the captured
  window; Surface::dispatch() clamps only when encoding for a program.
- **Focus.** `focus()` = `takeFocus()` for components that `canFocus()` (default: bindings in
  force, or an onKey/onPaste override, by reflection, cached per class), else `focus()` on
  the surface (keys go on to the latest request below). Dialog and Form override it (false).
  `hasFocus()` is also true while one of the component's overlays has the focus (a dropdown
  field looks focused while its list has the keys).
- **Sizes.** Size::distribute() treats ['auto', n] as weight 1 capped at n (water-filling:
  settle every auto that fits its current share, repeat, then split the rest by weight).
  Before, autos were fixed cells and swallowed the room. Table::measure() gives its natural
  width (fixed columns, the widest cell/title of the others).
- **Dialog** renders Box(Column[title, ScrollView(content), Row(Buttons)]); measure() is the
  tree's natural size via `measureElement()` (a message laid out at 60 columns). mount()
  measures once so the content's components exist and the first field can be focused before
  the first render. Buttons are children "button$i".
- **ScrollView** is a component: its render returns a ScrollLayout that hosts an inner surface
  (the view's width x the content's natural height, `setFit(false)`) and sets on it the layout
  `layoutElement()` built; `follow()` keeps the top and scrolls to the focused leaf when that
  changed. onKey (capture) marks it dirty so a focus move inside is followed.
- **Form** finds its fields with `children()` recursively (Field + `id`); validation sets the
  `invalid` style state; a Button without onPress walks up to the nearest Form.
- **Pen before render.** renderNow() writes `ESC[0m` + the component's style to its surface
  before render(); SGR-only writes change no cells.
- **Child keys** made by the Tree: "\0k:<key>" for `key:` elements, "\0p:<path>" for the rest;
  `children()` shows the former as `<key>`.
- **FakeTerminal::run()** instead of a watcher in attach(): see the fiber\ finding below.
  `settle()` loops `sleep(0)` + refresh until the output length and the root version were
  unchanged three times (1 s at most).

## Status (0.0.2-beta1; TODO-0.0.2-beta1.md, SPEC section 8)

All items of TODO-0.0.2-beta1.md done, in CHANGELOG.md; tests green with and without
ext-phasync (444 tests).

- [x] 1. Element tree (src/Element.php, src/Element/*, src/Internal/{Tree,ElementLayout,LayoutItem}.php);
      tests/Elements/ElementTreeTest.php (rebuilt tree keeps focus+scroll, keys, mixed with
      imperative drawing, random rebuild property test, device replay)
- [x] 2. Reactive state (src/State.php; Component::renderFrame()); tests/Elements/StateTest.php
- [x] 3. Stylesheet (src/Stylesheet.php, src/ComputedStyle.php), :hover; StyleTest.php
- [x] 4. Bindings + Help; BindingsTest.php
- [x] 5. Widgets Editor, Table, Select (extends Table), Dialog/Confirm; tests/Widgets/*
- [x] 6. Ansi::clearHome(), version check (Internal/Runtime, at start() and attach()),
      docs/components.md, docs/widgets.md, README tour (tests/Examples/ReadmeTest.php),
      examples/form.php (ExamplesTest), CHANGELOG

## Left for later versions (0.0.2)

- Widgets 5-8 of SPEC 8.5 (Form, Checkbox/Radio/Button, Tabs, ProgressBar, Spinner, Tree,
  ScrollView, draggable separators, Terminal onOutput, Markdown).
- `:hover` only for components (elements are values; a Text has no state of its own).
- Stylesheet has no combinators (descendant selectors); inheritance covers the common case.
- Text elements do not scroll when longer than their box (ScrollView is a later widget).
- Editor: no double-click word selection; a hanging space at a wrap point puts the cursor one
  cell past the text at the row's end (clamped to the last column).
- Table scrolls sideways by whole columns, not by cells.
- Dialog with element content needs a size (`with(width:, height:)`) or gets 60x12 at most.

## Design decisions (0.0.2-beta1)

- **Component extends Element.** Every component is an element; attributes (key, id, class,
  style, width, height, disabled) live in one private array of Element, set with `with()` (and
  by element constructors' named arguments). Public methods added to every component:
  `with()`, `attribute()`, `measure()`, `types()`, `style()`, `stylesheet()`,
  `setStylesheet()`, `bind()`, `bindingsInForce()`; protected `bindings()`, `call()`,
  `dialog()`, `propsChanged()`; property `protected State $state`. None clashed with the
  built-ins (checked).
- **No `Component::set()`**: StatusBar has a public `set(string, string)` with a signature a
  variadic `set(mixed ...)` in the base would break. `State::set(...)` instead
  (`$this->state->set(a: 1)`), one way to do it.
- **Element tree -> surfaces.** Each container element (Column/Row/Layers, not the root one) and
  each Box/Text/Help gets a Surface kept by path (positions + class names, or `key`) in
  Internal\Tree; the root container arranges the component surface itself. Each container is
  an ElementLayout (Layout) that sizes ('auto' measures: text by wrapped lines, components by
  `measure()`, null = '1fr'), hosts at the final geometry (hosting at 0,0 first and moving
  blanked the host's own drawing), sets the nested layouts and paints text only when its
  signature (size, style, content) changed. Component elements: started ones are placed as
  they are; a `new X()` element is the child "\0<path or key>"; a later fresh instance of the
  same class there gives its props (public non-readonly properties declared below Component)
  and attributes to the kept one (`propsChanged()`), else replaces it.
- **Focusable surfaces.** Text/Help surfaces and containers with no component below refuse the
  focus (`Surface::setFocusable(false)`): rankFocus treats a never-focused, unfocusable window
  as rank -1; focusNext/focusToward skip them. Without this the first Text of a Column took the
  keys.
- **Reactivity.** requestRender() puts the component in a static dirty set and spawns one
  go(sleep(0)); the first such task to run renders the whole set, shallowest first, each at
  most once (asked again during the frame -> next frame). Triggers: State writes (State moves
  declared props into an array at watchFor() so __set sees every write), handlers returning
  true, bound keys, `sleep()` (before waiting) and a go() task ending after it had suspended
  (flag set after go() returns: a task that finished synchronously renders nothing), emit()
  and call() (the closure's bound $this, by ReflectionFunction::getClosureThis(), renders).
  Writes during the component's own render are ignored (no loops).
- **Key order with bindings.** Capture as before for onKey() (root first) at components that
  are not the key end; at the key end (no component further on the key path): own bindings,
  own onKey(), then parents' bindings, nearest first. So a TextInput types 'q' that the root
  binds, and the root's ctrl+q works while a Terminal is focused (it declines all keys).
  Bound methods run synchronously (typing order), returning false = not taken.
- **Styles.** Stylesheet selectors without combinators (inheritance of colours/attributes
  covers it); parts `Type::part:state` for what widgets draw. Box focus border: Tree sets the
  same style as normal and focus style and re-renders on Focus events of its surfaces, so a
  Box in an unfocused pane is not drawn focused (the compositor's isFocused() is per host).
- **focus() inside render().** With reactive renders, a render that calls `$input->focus()`
  (README's first program) ran again after the key that moved the focus (Alt-Right) and took
  it back: a full-suite hang (the README test failing inside run()) found it. The old test
  LifecycleTest "focuses ... across renders" pins that an explicit requestRender() re-applies
  the render's focus. Rule now: Component records the focus() calls made during its render
  (static `$inRender`); a later render skips those it asked before, unless that render was
  asked for with requestRender() (`$asked`). All library-made renders go through
  `markDirty()` (@internal, final public: State and Tree call it); `requestRender()` = asked +
  markDirty().
- **Help** lists only bindings with a description (`[method, description]`): an Editor binds
  ~50 keys, a footer of all of them is noise.
- **Dialogs** (`Component::dialog()`): the dialog is a child "\0dialogN"; the render wraps the
  tree in `Layers([tree, ...dialogs])` (a Layout in a Stack; a null render hosts the dialog
  surface directly), the border comes from the dialog's style; the close event (a listener of
  the opener: renders it) unhosts at once and refocuses the surface focused before. Modality:
  the opener's own onKey/bindings/paste are skipped while a dialog is open, its mouse listener
  takes events outside the dialog. Bound methods run synchronously, so awaiting the answer is
  done in a go() (documented). A dialog disposed unanswered resolves with `$cancel` in
  unmount() (else the awaiting coroutine hung: phasync reported a deadlock).
- **measure() changes**: Tree measures components through `measureFor()`, which remembers the
  room and answer; after the component's own render, a different answer makes the parent
  render (an Editor with maxLines that grew a line).
- **:hover** only for components: any component's mouse listener records the pointer (root,
  x, y); components whose computed style considered a :hover rule are kept in a WeakMap and
  render again when their hover state flips. Such a component writes ?1003h to its surface
  once so the device asks for motion reports (it then takes its mouse events itself).

## Status (0.0.1-beta5; TODO-0.0.1-beta5.md)

All items of TODO-0.0.1-beta5.md done, in CHANGELOG.md; tests green with and without ext-phasync
(phasync 2.0.7, fiber-interop/api 0.3.1, fiber-interop/phasync 0.4.1).

- [x] 1. Focus survives any rebuild (tests/Component/FocusRebuildTest.php: the exact shape, a
      property test of random layouts around a focused leaf, component and plain surface)
- [x] 2. dispose() waits, except from the subtree's own coroutines (fiber\current());
      resolve()/reject() instead of settle() (DisposeTest.php)
- [x] 3. Log::setLines() by position; child($key) forgets disposed children; component events
      (Log/ListView `scroll`); containers accept an existing child; FakeTerminal::type() doc +
      guard; acceptance tests green

## Design decisions (0.0.1-beta5)

- **The beta5 focus bug was a lifetime bug.** A render disposed every child it had not asked
  for with `child()`; a dialog added with `->add($dialog->surface)` was disposed by the render
  that showed it (its listeners gone, its surface still hosted and focused). Rule now: after a
  render, a child is kept if it was asked for **or its surface shows in the component's
  surface** (`isShownIn()`, walking `parent()` up). Found two more defects with the property
  test: given-up nested surfaces kept their own nested surfaces hosting windows (orphan
  subtrees whose windows said hasFocus() true) -> `Host::giveUp()` recursive; a bordered
  window 1 cell wide painted 2 cells (compositor warning).
- **hasFocus() = the key path.** Walks from the root of the nearest shown ancestor's tree down
  exactly as dispatch() delivers: at a surface with a component (static WeakMap surface ->
  component) take its `unshownFocus()` child, else `focused()`. No second derivation.
- **dispose() waits** for `ended` unless `fiber\current()` is null or one of the subtree's
  task handles (own tasks, children's, and ending children's). Each go() wrapper records its
  handle with `current()` at task start (the adapter knows it before go() returns), falling
  back to go()'s return value. Handles are only compared with `current()` (as the api README
  0.3.1 documents: `$h === current()`), never awaited, so a failing root task is still
  rethrown by run(). Internal disposals (render drop, failure, close/detach) use `stop()`,
  the non-waiting half. The wait is bounded: the end runs `$timeout` after stop() at the
  latest (the beta4 background end).
- **Children vs. shown components.** `children` holds owned children and components of another
  owner a container shows (`child($key, fn() => $started)`); `parent` is the owner. Stop/end/
  runs() use owned ones only; focus routing (unshownFocus/focusRank) uses all. A disposed child
  leaves its parent's `children` at once and waits in `ending` until it ended (the parent's end
  waits for those).
- **Component events**: `on()`/`emit()`/`hasListeners()` on Component. Built-in callbacks given
  to constructors (onSubmit, onSelect, onActivate, onExit) stay as they are (the owner's
  reaction); events are for other components that show the state. Log computes
  [topLine, bottomLine, following] only with a listener; the line walk is a generator from the
  newest line that stops early (it was a full array per call). Log handles the wheel itself
  (was the host's) so every scroll goes through scrolled().
- **Compositor**: scrollback rows are not damage-tracked, so a pinned view (top < 0) has
  linesScrolled/scrollbackSize in its paint key.
- **ext-phasync 2.0.7** changed `sapi()`: the object needs `write(string): bool` (no more
  `output()` stream); SurfaceSapi passes output to Surface::write(), the stream wrapper is gone.
  Older ext versions count as absent (canCaptureOutput() checks the version).
- **SPEC vs TODO**: SPEC says go() handles are never compared; TODO-beta5 and the api README
  (0.3.1) prescribe `$handle === fiber\current()`. Followed the TODO; the comparison is the
  only use.

## Status (0.0.1-beta4; TODO-0.0.1-beta4.md)

All items of TODO-0.0.1-beta4.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] 1. One focus model before and after a component is shown (tests/Component/FocusModelTest.php)
- [x] 2. `area` event on Surface; a component renders again when its area changed (AreaTest.php)
- [x] 3. Log::scrollToLine(), topLine(), bottomLine(); scrollTo()/scrollBy() documented as rows
- [x] 4. child($key) lookup; FakeTerminal::feed() documented + tested; dispose() without
      debug_backtrace (DisposeTest.php); acceptance tests green (Benchmark/)

## Design decisions (0.0.1-beta4)

- **Focus, one rule for shown and unshown components.** The Surface tree's rule (a host focuses
  the window whose subtree holds the latest request, unless its own takeFocus() is later) is
  applied by Component to children not shown yet: `unshownFocus()` picks the unshown child whose
  subtree (`focusRank()`: its surface's `latestFocus()` plus its own unshown children) is later
  than everything the surface shows (`Surface::latestFocusBelow()`, keepStamp included). A
  component's key/paste listener (after its onKey) passes the event to that child's surface,
  where the same happens again, so keys reach the leaf the request names. `focus()` still stamps
  the nearest shown ancestor first, so the keys arrive there on the surface path. No per-request
  state (beta3's `focusPending` + `isLatestFocus()` are gone): when the render shows the
  component, the same stamps give the same path. Fallback: a component not rendered yet with
  nothing focused below passes keys to its first child (= the first window, as a host does);
  Box adopts its content in mount() for that. `Component::hasFocus()` walks up the component
  tree: on the surface path, no ancestor passing keys to another unshown child, and the top
  (an unshown ancestor) is its parent's `unshownFocus()`.
- **Area event.** `Surface::geometryChanged()` (static, @internal) is called by Host on
  host/unhost/moved/changed (border, scroll, title) and by Surface::resize(); the check runs at
  the end of the outermost `changeTree()` (or at once outside one): every surface with an `area`
  listener (static WeakMap) compares `area()` with the one told last. Re-entrant changes by
  listeners loop until quiet. Component listens and requestRender()s (batched). Cost: one area()
  walk per listening surface per tree change.
- **dispose() does not wait.** It settles stopped, wakes sleep(), disposes children (which
  return at once too), and the end (`end()`: unmount, then settle `ended`) runs when the
  component's coroutines are done (`idle` Deferred, settled by the last go() wrapper's finally)
  and every child's `ended` resolved: directly when nothing is pending, else in a fiber\go()
  that `await_all([idle, ...children ended], $timeout)`. Task handles are never awaited (that
  would mark a failing root task as awaited and keep fiber\run() from rethrowing it), stored or
  compared; only Deferreds of the library are awaited. So no self-wait is possible and no
  current-task identity is needed.

## Status (0.0.1-beta3; TODO-0.0.1-beta3.md)

All items of TODO-0.0.1-beta3.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] 1. Component::sized() (mount = props/state, sized = rectangle); Terminal starts its pty
      there; children are sized/rendered only by a parent render that shows them; a resize
      before the program's first output is followed by a second SIGWINCH at its first output
- [x] 2. Pty::hangUp()/kill() signal every process group of the session; shutdown hang-up
- [x] 3. Focus by stamps settled over the tree after every tree change
- [x] 4. Component::focus() on an unshown child routes keys through the nearest shown ancestor
- [x] 5. border/title on Split::add()/Stack::add(); Log::setLines() keeps the place; docs
- [x] acceptance: tests/Benchmark/PanesTest.php (vim/htop/less size + clean exit, quit with
      foreground jobs as string/vector, nested split-shells), BenchmarkTest still green

## Design decisions (0.0.1-beta3)

- **Focus = the latest request.** `Surface::focus()`/`takeFocus()` stamp the surface from a
  static clock. Every change to the tree (Surface::host/unhost/setLayout/resize-arrange and
  focus itself) runs through `Surface::changeTree()`; when the outermost change ends, the root
  is settled: `rankFocus()` walks the tree once, each host points at the child whose subtree
  has the highest stamp (ties keep the current one; all zero = the first window; a host's
  `keepStamp` from takeFocus() beats older children = focus on itself). The root remembers the
  path it announced (`announced`); the set difference old/new gets Focus(false)/(true), each
  surface once, the 1004 report only at a path end. A root that gets hosted (`joinTree()`)
  tells its old path it lost the focus. Host::setFocus is a plain pointer + title write.
  Replaces beta2's `focusWanted`/`Host::focusStamp`, which lost the focus when a render moved the
  focused window into another nested surface.
- **hasFocus() of an unhosted surface is true** (it is a root of its own). Unchanged; tests
  check routing instead.
- **Pending focus (item 4)** lives in Component, where batching happens: focus() on a child
  whose surface has no viewport sets `focusPending` on each ancestor up to the first shown one,
  focuses that one, then the child (so the child holds the latest stamp). The ancestor's
  key/paste listener (after its own onKey) forwards to the pending child while it is mounted,
  unshown and `isLatestFocus()`. Plain surfaces need no such thing: whoever hosts them does it
  synchronously.
- **Pty session.** The pid is the session leader (setsid -c, no fork). `processGroups()` walks
  /proc/<pid>/task/*/children recursively (a full /proc scan cost 40 ms per call with 1500
  processes and blocked the loop) and keeps every group seen in `$groups`, so the SIGKILL phase
  still reaches jobs whose shell died on SIGHUP (they are reparented and no longer below the
  pid). Children in another session (nested Ptys, daemons) are not descended into: a nested
  program ends its own ptys (Tty's signal handler exits; Pty's shutdown function hangs up).
- **Early resize**: `resizedEarly` when resize() comes before the program's first output; at the
  first output, after any stty in flight, SIGWINCH to the tty's foreground group (tpgid from
  /proc/<pid>/stat). The kernel sends no SIGWINCH for an unchanged size, hence the explicit one.
- **sized()**: firstRender() = sized(), render, go(run). A child's firstRender is only called by
  the parent's renderNow() for children whose surface has a viewport; the beta2 fallback that
  rendered unshown children at 80x24 is gone.
- **Log place**: rows of each line are computed back from the cursor row (the open line ends
  there) with the width last laid out (`laidCols`); the line at the view's top is found before
  the re-layout and its new first row scrolled to after. setLines() looks the old top line's text
  up in the new lines.

## Left for later versions

- Word wrap does not break between CJK ideographs (UAX #14).
- Log's open-line rewrite in word-wrap mode erases with the current pen (BCE).
- Ctrl-C forwarding into Claude's login screen did not exit it (also under tmux).
- Non-Linux: Pty signals only the program's own group (no /proc children walk).

## Status (0.0.1-beta2; TODO-0.0.1-beta2.md)

All items of TODO-0.0.1-beta2.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] Text: escape-aware width/truncate/pad, `wrap()` (char + word)
- [x] Surface tree: parent()/viewport()/root()/area() (Rect), focus() through any host,
      takeFocus(), hasFocus(), focusToward(), close(); click-to-focus inherited down the tree
- [x] Layout: Split/Stack nest layouts directly; items()/host()
- [x] Pty: onExit (start param) + exited() awaitable
- [x] Device: 1003 union of the tree; detach closes the root, closing the root detaches
- [x] Component: start(device), close -> dispose, root dispose closes, root failure disposes the
      tree + closes + rethrows, failing child disposes its children, deferred first render
- [x] Terminal exited()/restart(), Log model, TextInput history, ListView
- [x] docs, README (first program tested by tests/Examples/ReadmeTest.php), CHANGELOG, examples,
      Claude Code first-run test, tag 0.0.1-beta2

## Design decisions (0.0.1-beta2)

- **Focus by stamps.** `Surface::focus()` numbers each request (static clock). The chain from
  the top host down is set silently, then the one level where the path changed dispatches
  Focus(false) to the old branch and Focus(true) to the new one (each travels down its own focus
  path), so every leaf gets exactly one event. A surface without a viewport records the stamp
  (`focusWanted`); `Surface::host()` applies it when the host's `focusStamp` is older, so the
  latest request wins when several surfaces wait to be shown. The root records a stamp too
  (harmless: it is never hosted). Component::focusLater is gone.
- **Mirrors.** A surface may still be hosted by several hosts (the compose property test does
  this); `parent()` is the host that showed it last (`Surface::$views`, last entry).
- **Nested layouts** get their surface from `Host::nested($i)` by position among the nested items
  of the arranging layout (React-style, no keys): a component's render that builds new layout
  objects each time keeps the same intermediate surfaces, so windows, focus and geometry survive.
  Surfaces of nested layouts not placed by an arrange are given up (layout removed, windows
  unhosted). Custom layouts use the same `Surface::nestedSurface($i, $layout)`.
- **close() is a hang-up event**, not a state. Device detach -> root close (unless the detach came
  from the close); every component listens to its surface's close and disposes; root dispose
  closes first (terminal restored before children hang up). App-started Ptys do not listen.
- **Root failure** = guard() for a parent-less component: record error, dispose (which closes),
  rethrow. In start() that reaches the caller; in a coroutine fiber\run() rethrows it once all
  else ended (fiber-interop rule: unawaited task exceptions are rethrown by run()); in a key
  handler it reaches whoever delivered the key (FakeTerminal::press(), or the device's input
  coroutine and so run()).
- **First render of children** happens in the parent's renderNow() right after setLayout (sized
  by fit); a fallback go() renders children no render placed. requestRender() before the first
  render is ignored (the first render is coming).
- **Terminal** hangs its program up in unmount() (not in run(): run() never starts when the
  first render failed, which left ptys running for 30 s). onExit runs in a component go().
- **Log** keeps `lines` + `open` (the unfinished last line) and writes them into its content
  surface: character wrap is the emulator's own autowrap; word wrap writes `Text::wrap()` rows
  joined by CRLF, and the open line is erased (CR, CUU, ED) and rewritten as it grows (a full
  layout when it is taller than the window). A layout writes only the lines that fit in the
  content's rows + scrollback (rows, not lines), prefixed by the SGRs of the lines left out. On
  resize: the last QUICK_ROWS rows at once, the full layout 0.1 s later in a coroutine (a resize
  storm costs one full layout).
- **ListView** draws the selected row by re-applying the selected style (without its reset)
  after every SGR in the label, so the label's colours stay under the inverse.
- **1003**: the device walks the tree each frame (`mode(1003)` anywhere) and toggles `?1003h`;
  leaving 1003 must send `?1002h` again (mouse modes are one setting in xterm and here).
- **Claude Code test** runs it with HOME/CLAUDE_CONFIG_DIR/XDG_CONFIG_HOME in a temp dir (first
  run: theme picker, login method), never touching the user's config. Ctrl-C twice does not end
  its login screen (also under tmux), so the test disposes it.

## Left for later versions

- Word wrap does not break between CJK ideographs (UAX #14 line breaking); a run of CJK without
  spaces is one word.
- Log's open-line rewrite in word-wrap mode erases with the current pen (BCE): a background
  colour left on by an earlier line shows in the erased cells until rewritten.
- (fixed in beta3) A pinned Log kept its row index across a re-layout.
- Ctrl-C forwarding into Claude's login screen did not exit it under our emulator nor tmux;
  not investigated further.

## Status (0.0.1-beta1)

Built bottom-up in the order of SPEC.md. Done layers are committed one by one.

- [x] Surface, VT parser (charm/parsing grammar), Ansi, tests incl. tmux reference + recorded programs
- [x] Pty, Surface::run() coroutine producer (echo capture via ext-phasync sapi())
- [x] Viewport (+ Text width utility)
- [x] Hosting (spec rev. 59802cf, self-similar): Surface::host() / Viewport, compositing by
      writing, dispatch() down the focus path, input decoder + encoder. The old Screen is gone.
- [x] Terminal devices: TerminalDevice (abstract) + Tty + FakeTerminal
- [x] Layout (Split, Stack); focus is on Surface (focus, focusNext, setFocusOnClick)
- [x] Component + built-ins (Terminal, Log, TextInput, StatusBar, Box)
- [x] examples (split-shells, host, coroutine-pane, dashboard), README, docs/, CHANGELOG
- [x] benchmark tests: split-shells nested 3 levels; Claude Code next to a shell
- [x] tag 0.0.1-beta1

## Left for later versions (known limits)

- (fixed in beta2) Mouse 1003 passed through.
- The Claude Code test stops at the folder-trust prompt (accepting it would write the user's
  Claude config); typing into its prompt, its bracketed paste and its redraw loop are covered
  only by the generic tests and by hand (`php examples/host.php claude`).
- No reflow on resize (non-goal); DECSLRM, double-width lines, OSC 4, DECRQSS not implemented.
- `fiber\Deferred` is `@internal` in fiber-interop/api 0.3.0 but SPEC names it; used for
  `Component::stopped()` and in `Internal\Signal`. Any then-able would do.
- charm/parsing is not a dependency (0.0.4-beta1): `composer grammar` and GrammarTest use a
  `charm-compile` on the PATH (a global install); GrammarTest skips without one.
- (0.0.3-beta8: compositing no longer grows with nesting; it costs the damaged rows.)

## Surface / emulator

- `src/Surface/ansi.cpeg` is the grammar; `AnsiParser.php` is generated (`composer grammar`),
  `tests/Surface/GrammarTest.php` fails when it is stale (where `charm-compile` is on the PATH;
  skipped elsewhere). The generated class is standalone; the package is MIT.
- The grammar runs on valid UTF-8: `Surface::write()` holds back a code point cut at the end of
  a chunk and scrubs malformed bytes to U+FFFD *before* parsing (compiled regexes always carry
  the `/u` flag, and PCRE refuses invalid UTF-8 subjects). C1 controls are U+0080-U+009F as in
  xterm's UTF-8 mode.
- Every sequence rule is total (complete, cancelled by CAN/SUB, or aborted by ESC/C1), so a
  rule fails only at end of input, and the last rule `Incomplete` keeps the tail
  (`Emulator::$pending`, capped at 1 MiB). Semantic actions only run for complete top-level
  items, so a retried tail never acts twice. Embedded C0 controls in sequences are collected and
  executed by the action before dispatch (VT500 behaviour).
- charm/parsing compiler findings (worked around, worth fixing upstream):
  - a snippet returning `false` does **not** fail the alternative in compiled parsers (the
    README says it should); the grammar is written so no action needs to refuse.
  - `...name:Clause` (spread + named capture) leaves `$name` undefined in compiled snippets.
  - string literals only know `\\ \" \' \n \r \t`; ESC must be written as the regex `/\x1b/`.
- Cell storage: `Line` = parallel arrays of graphemes and interned `Style` objects. A wide char
  is the grapheme followed by a '' continuation cell. Width table generated from ICU into
  `Surface/Width.php` (`tools/generate-widths.php`).
- Damage: per-row version numbers (`version()`, `damagedSince()`), so several readers can
  track one surface; `takeDamage()` is the single-reader convenience.
- xterm vs tmux differences found by the reference tests (Surface follows xterm): BS with
  wrap pending moves to cols-2; HPR/VPR exist; tmux capture shows raw ACS chars.
- Not implemented (on purpose, rare): left/right margins (DECSLRM), double-width lines,
  reverse wrap, 132-column mode, OSC 4 palette queries (no reply), DECRQSS.
- Speed: ~5 MB/s plain text, ~1.5-2 MB/s heavily coloured (PHP 8.5, no JIT). The ASCII fast path
  stores a row's share with array_splice.

## Hosting (the self-similar structure)

- One viewport per hosted surface per host (`host()` returns the existing one). Viewports are
  created only by `Surface::host()`; geometry is set by the caller or a layout, presentation
  (border, title, scroll, selection) lives on the viewport.
- Compositing is blitting (0.0.3-beta8, SPEC 3): a child's `change` marks the host dirty and
  the host emits `change` up to the root (every time; `markDirty()` also bumps the host's
  `vt->version`, which is the surface's `changeCount()`, so a window showing it is drawn anew).
  The host's frame (`Host::$frame`, list<Line>, with its own `rowVersion`) is made when read:
  its own cells (`vt->lines`, never written to by compositing) with every window blitted in
  z-order to any depth (`blitAll`/`blit`/`draw`, `Area::cut()`/`put()`), clipped by each
  window's inner rect and its source's bounds; borders and titles drawn, the selection inverted
  over the window's whole content (nested windows included), shadows shading the windows below
  the casting one at the same level, wide characters cut at an edge made blanks, a wide
  character shown in the style of its first half.
- Only damaged rows are made again: `Host::damage()` walks the tree with a per-host WeakMap
  `$seen` (Viewport => [key, look, top, bottom, inner]); a window whose key (`place()`: source
  changeCount + frame rect + clip + `lookKey()` + shades) is unchanged is skipped with its
  subtree (any change below bumps the changeCount); one whose look part changed (or new, gone,
  or its source's window list changed) damages all its rows old and new and is walked in
  "record" mode; one whose changeCount alone changed is looked into: its source's own rows by
  rowVersion (scrollback rows always), its windows recursively. The memo is per composing host,
  because the same viewport is drawn by its own host's frame and by every ancestor's in other
  coordinates. Then each band of damaged rows is composed from the own lines with every window
  on it (`place()` skips windows outside the band before computing a key).
- Per-window draw cache `Viewport::$drawn = [key, rows, styles]`: kept only after the key stayed
  the same for a frame (no copy for a window that changes every frame), never while a window
  inside holds back a synchronized update, and only when it was drawn whole (not in part, for a
  band). A host's own cells are copied only where its windows do not cover them
  (`uncovered()`).
- One diff at the end: the device compares the root's frame with what it showed by visible
  identity (`Style::blank()`: a space looks like its bg and link alone, unless INVERSE,
  UNDERLINE or STRIKE), so equal-looking frames cost no byte; the blank pen: keep the current
  pen if the blank looks the same in it, else the next non-blank's style in the run if that
  looks the same, else the blank's own `blank()` style (so a blank sets no foreground).
  `Surface::dump(looks: true)` normalises blanks the same way (each joins the run before it,
  else the one after, else its own look; trailing plain-looking blanks are plain);
  `FakeTerminal::dump()` uses it.
- Mode 2026: `Emulator::$synced` snapshots the screen rows when synchronized output starts; a
  host shows them (`Surface::heldLines()`) for at most 0.5 s; while it does, `Host::holding()`
  makes `needsCompose()` true (the device re-frames) and the next frame is made whole.
- tests/Support/AnsiComposite.php is the pre-beta8 compositor as a reference (writing ANSI to a
  fresh surface, with twins for hosts' own cells); tests/Hosting/BlitTest.php compares it with
  the blitter on random trees (moves, raises, scrolls, borders, shadows, selection, unhost,
  writes; hosts below composed on their own between steps).
- PCRE2 < 10.43 `\X` joins consecutive pictographs ("👍👍" one cluster): `Text::graphemes()`
  splits again before each Extended_Pictographic not after ZWJ; the emulator uses it.
- Title: the host relays the focused child's title (`Surface::relay()`, which sets its own
  title) on change and on focus change; bell and OSC 52 are relayed up unconditionally.
- Cursor: not written up (it would break the host producer's cursor); `activeCursor()` walks
  the focus path. The device shows the root's activeCursor.
- Input: `dispatch()` = listeners (`on('key')` ... returning true consumes), then Host::route
  (focused child; mouse to the topmost window under the pointer with capture from press to
  release), then the leaf encodes. A host uses unwanted mouse events itself: wheel scrolls
  the viewport by 3, a left drag selects (copied on release with OSC 52 written to itself).
  Typing or pasting into a window clears its selection and makes it follow again.
- Tab cycling is not bound by default (shells need Tab): `focusNext()` is bound by the
  program. split-shells uses a prefix key (Ctrl-B) so it nests like tmux in tmux.

## Terminal device

- `TerminalDevice` is abstract (frame loop, differ, decode, probe); `Tty` and `FakeTerminal`
  implement open/close/send/read/size. Kitty: query `CSI ? u` + DA1 at attach, push `CSI > 1 u`
  if the answer has `CSI ? n u`; the restore string always pops (`CSI < u`), harmless.
- FakeTerminal pipes its surface's input straight to `receive()`, so input is synchronous;
  its read() only idles. `text()`/`dump()` refresh first; `waitFor()` for pty output.
- Mouse: 1002 (button events), and 1003 while any surface in the tree has it (beta2).

## Components

- `render(): ?Layout` returning a layout -> `setLayout()`; children are reconciled by key
  (`child()`), unused keys disposed only after a render that returned a layout.
- Event hooks are surface input listeners registered at `start()`, so they run on the way
  down (capture order): the root component sees keys before its focused child.
- `requestRender()` = one `go()` that `sleep(0)`s then renders: batching without a frame clock.
- dispose() waits for the end (beta5) except from the subtree's own coroutines; the end
  itself is the beta4 mechanism: unmount() follows when the component's go() tasks have
  returned and its children have ended, at most $timeout later.
- Error boundary: a failing child unhosts its windows, draws the error (again on resize) and is
  disposed in a coroutine; the root rethrows (so `Tty` restores the terminal on the way out).
- `focus()` before the parent shows the child: see beta4 (one focus model).
- Terminal: run() waits for the pty; a second go() sleeps until disposal, then SIGHUP, 0.5 s,
  SIGKILL. Log: its own content surface hosted with a Stack, so it scrolls by itself.

## Producers

- Pty: proc_open with three 'pty' descriptors gives no controlling terminal and no new session
  (checked: `ps` shows TT `?`), so Ctrl-C, job control and SIGWINCH would not work. On Linux the
  command is wrapped in `setsid -c` (same pid, pty becomes the controlling tty). Initial size is
  set by `stty cols C rows R; exec "$@"` before the program starts; later resizes by
  `stty -F <slave>` in a helper process, serialised (latest size wins). The slave path comes
  from /proc/<pid>/fd/0 (fallback `ps -o tty=`).
- proc_open() passes all descriptors of the PHP process to the child (other ptys' masters
  included), so a hung-up program would never see EOF/SIGHUP. The wrapper (bash when found,
  else sh which can only close 3-9) closes every inherited descriptor > 2 before exec.
- The wrapper prints `OSC 7701;phasync-tui-ready BEL` after its initial `stty`; the pump strips
  it and only then applies resizes (a resize before it was overwritten by the wrapper's stty).
- The master reads return EIO when the program exits; that is the end of the stream, read
  under a temporary error handler so it is not reported as a notice.
- Surface::run(): with ext-phasync, `phasync\ext\sapi($code, $sapi)` (the sapi object needs
  name(), output() returning a stream, send_headers()); the stream is a tiny userland wrapper
  writing to the Surface. Output of coroutines started inside sapi() is lost once sapi() returns,
  so the closure is wrapped in a nested `fiber\run()` (children finish inside the capture).

## fiber\ notes

- **Finding (0.0.3-beta1): `fiber\go()` outside `run()` throws `NotInRunException`.** SPEC 9.1's
  whole program starts its coroutine at the script's top level, then calls `Tui::inline()`
  (which runs its own `fiber\run()`). The api forbids that, so the example is written inside a
  `fiber\run()` of the program's. The smallest addition that would let the example run as
  written: a `go()` in {main} attached to the next `run()` (or {main} as an implicit run frame
  that the first `run()` drives). Nothing in the library works around it.

- **Finding (beta2): awaiting a handle from `fiber\current()` misbehaves under
  fiber-interop/phasync 0.4.1.** Item 9 of TODO-beta2 (a failing headless test must not hang)
  was first done in `TerminalDevice::attach()`: remember `current()` (the attaching task) and
  start a watcher `go(fn() => await($attacher))` that detaches the device when the attacher
  throws, then rethrows. Probed in a scratch script: (a) when the attacher is the closure of
  `fiber\run()`, `await()` of its `current()` handle runs that closure again (the closure
  printed its first line four times), and (b) when the attacher is a `go()` task and the
  watcher is started from inside that task, `await()` returns normally although the task
  throws. Awaiting the same task from a sibling started outside it works (rethrows). So the
  watcher was dropped and `FakeTerminal::run()` does the plain thing (detach in `finally`).
  The api README allows awaiting go() handles and says `current()` is such a handle; either
  the adapter should honour that for the run() task and for awaits from a child task, or the
  api should say `current()` is for `===` only. Nothing of the library depends on it now.
  **Status (beta4):** fixed on fiber-interop/api main (4dc3d41, conformance example 23) and
  fiber-interop/phasync main (8445790), not tagged yet. The library's watcher
  (Internal\Runtime::watch()) is used only on versions newer than 0.3.1 / 0.4.1 or dev
  branches; on the tagged ones start() documents the limit and Tui::run() is the way.

- **Finding (beta3, resolved in api 0.3.1 by `fiber\current()`, used since beta5):** fiber-interop/api had no way to ask "which task am I" (a
  current-task handle comparable with what go() returned). A component disposing itself from
  one of its own coroutines must not wait for that coroutine. beta3 found the component's task
  wrapper on the call stack (debug_backtrace), which is going beneath the API like
  Fiber::getCurrent(); beta4 removed it: dispose() never waits, the component's end waits for
  its coroutines in a coroutine of its own (see beta4 decisions). The cost: dispose() can no
  longer promise that unmount() has run when it returns. The smallest addition that would let
  it: `fiber\current(): ?object` returning the handle go() gave for the running task,
  comparable by identity.

- `fiber\Deferred` is public since fiber-interop/api 0.3.1 (`resolve()`, `reject()`,
  `isSettled()`); the library uses those, no longer `settle()`.
- `await_any([$work, $stopped])` starts one racer task per awaitable; a racer waiting on a
  long-lived `$stopped` stays suspended until it settles. In a loop (frame loop, component
  sleeps) that accumulates tasks. `Internal\Signal` (one fresh Deferred per wait, dropped after)
  is used instead everywhere a loop waits.
