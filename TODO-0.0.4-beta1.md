# 0.0.4-beta1: images in the cells (Kitty, iTerm2), and a Canvas that draws on every terminal

Everything from 0.0.3-beta8 keeps working. Items 1-4 are SPEC section 10 (10.1 and 10.4) in their
first form: the Kitty and iTerm2 protocols, no Sixel yet; the canvas on `braille` and `pixels`,
no charts, gradients or fonts yet. Item 5 is the small list that closes 0.0.3 (its own TODO file
is folded in here to save a round). No critics follow this round: the author tests by hand on a
kitty or WezTerm window, so examples and `--headless` output matter.

## 1. The emulator understands two image protocols

- **Kitty graphics** (`ESC _ G <key=value,...> ; <base64> ESC \`): transmit (`a=t`, chunked with
  `m=1`/`m=0`, formats `f=100` PNG and `f=24`/`f=32` raw, direct `t=d` only), transmit-and-display
  (`a=T`), put (`a=p`), delete (`a=d`, the common forms), with `i=`/`I=` ids, `c=`/`r=` cell sizes
  and `x=`/`y=`/`w=`/`h=` source rectangles, placement at the cursor, cursor movement after a
  placement as kitty does it unless `C=1`, and the query/response the protocol expects (`a=q`, and
  an `OK` reply for transmits with `q=0`) so a hosted program's own probe works. Unsupported keys
  are ignored, never leaked as text.
- **iTerm2 inline images** (`OSC 1337 ; File=<args>:<base64> ST`): `inline=1`, `width`/`height` in
  cells, percent or `auto` (auto from the PNG header's pixel size and the device's cell size,
  default 8x16), `preserveAspectRatio`, placed at the cursor spanning the computed rectangle; the
  cursor moves below as iTerm2 does.
- The image bytes are kept by the Surface, by id, with their pixel size (read from the PNG header
  or the raw format; GD is not required for this); the cells of a placement carry an **image
  fragment id**, interned exactly like a style and a hyperlink: one small int per `(image id,
  column offset, row offset)`, so `copyArea`/`drawArea` copy it unchanged and the diff compares it
  with `===`. A glyph written over an image cell drops the fragment (the glyph wins, as in kitty).
  Erasing (ED/EL/ECH) drops fragments. Scrolling moves the cells, fragments and offsets included.
  Images nobody references any more are forgotten.
- Tests: known-answer tests per command form; a recorded `kitty +kitten icat` transmission and a
  recorded iTerm2 `imgcat` output replayed into a Surface give the expected fragment rectangles;
  the blit property test gains image cells (overlap, clipping, a glyph over a fragment); a hosted
  program emitting either protocol shows the image in its pane.

## 2. The device shows them

- Probe once at attach, never blocking start-up (like OSC 11): Kitty by its own query (`a=q`
  with a 1x1 image, answered with `OK`), iTerm2 by `TERM_PROGRAM`/`LC_TERMINAL`/`XTVERSION`
  (iTerm2, WezTerm, Konsole, VS Code, xterm.js); `TerminalDevice::images()` answers
  `kitty|iterm2|none`; `FakeTerminal` takes `images:`.
- After the diff, the device groups the frame's changed image cells into rectangles of
  contiguous fragments of one image: on Kitty it transmits each image once per id (cached) and
  emits one placement per rectangle with `c=`, `r=`, `x=`, `y=`, `w=`, `h=`, deleting placements
  whose cells changed; on iTerm2, which cannot place fragments, a fully visible image is emitted
  whole, a partly covered one is cropped through GD when loaded and otherwise emitted whole only
  when its whole rectangle is visible; with `none`, image cells are drawn as the fallback the
  canvas chooses (item 4) — a hosted program's image on a device without a protocol shows as
  quarter blocks when GD can decode it, else as blank cells.
- Byte tests on FakeTerminal with `images: kitty`: an image placed once costs one transmit and
  one placement; a dialog sliding over it costs placements only; two identical frames cost zero
  bytes; the recorded streams round-trip (the device's output replayed into a Surface gives the
  same fragment cells).

## 3. `Canvas` with the browser's 2D context (SPEC 10.1)

`new Canvas(draw: fn(Context $c) => ..., marker: 'auto')`, an element like any other (size from
the layout, `class:` utilities apply). The context API as SPEC 10.1 lists it: paths (`beginPath`,
`moveTo`, `lineTo`, `arc`, `arcTo`, `bezierCurveTo`, `quadraticCurveTo`, `rect`, `closePath`,
`stroke`, `fill`, `fillRect`, `strokeRect`, `clearRect`), state (`fillStyle`/`strokeStyle`
colours — gradients come in beta2 —, `lineWidth`, `globalAlpha`, `save`/`restore`, `translate`/
`scale`/`rotate`/`transform`, `clip`), `fillText` (real glyphs at the nearest cell, one cell
high), `drawImage` (a `GdImage`, an `Imagick`, a path, or PNG bytes, with the destination
rectangle), `getImageData`/`putImageData` on the raster. Coordinates are floats in the canvas's
own units (cells with fractions by default; `scale()` for anything else), identical on every
backend. `$c->resolution()` tells dots or pixels per cell. Markers: `braille` (2x4 dots per cell;
a cell's dots share one foreground: the last drawn colour wins), `quarter`, `half`, `block`,
`pixels`, and `auto` = `pixels` when the device has images, else `braille`; `pixels` on a device
without images degrades to `quarter` without the program changing. The glyph backends rasterise
into a dot bitmap in pure PHP (Bresenham lines, scanline fills, midpoint arcs; no anti-aliasing,
`lineWidth` quantised to dots). The `pixels` backend rasterises into a `GdImage` when `ext-gd` is
loaded (the context methods map onto GD's drawing; `imagepng` out) and into a pure-PHP RGBA
buffer with a built-in PNG encoder (zlib + CRC) otherwise; the canvas writes the result as one
Kitty or iTerm2 placement through `write()`, like every producer. A canvas whose `draw:` closure
reads a `State` redraws when it changes, and one whose drawing did not change paints nothing.
Pointer: a canvas with `onMouse:` asks for any-motion tracking; its events carry `x`, `y` in
canvas units (cell centre; SGR-Pixels 1016 comes in beta2).

Tests: each context method on `braille` against recorded dot grids; the same drawing on
`braille` and `pixels` (FakeTerminal `images: kitty`) covering the same cells; `auto` picking per
device; a State-driven redraw count; `drawImage` of a small PNG on both backends; the blit
property test with a canvas inside nested windows.

## 4. The acceptance example

`examples/graphs.php` is rewritten: a live line graph (two series from coroutines), a filled area
under one of them, axis lines and labels through `fillText`, a small logo drawn with paths, and a
`drawImage` of a PNG from `examples/assets/`, in a dashboard with a `Help` line; `--marker=` forces
a backend; `--headless=COLSxROWS` prints the Braille version; three themes; inline mode shows the
graph only. README gets a "Graphics" paragraph with the headless screenshot and a sentence on
which terminals show pixels.

## 5. Closing 0.0.3 (small)

- Every example shows its quit key in a `Help` line; `coroutine-pane.php` and `split-shells.php`
  keep Ctrl-B as prefix and also quit on Ctrl-B Ctrl-Q; `coroutine-pane.php` and `host.php` end by
  themselves when the hosted process exits: a goodbye with the exit status for three seconds, then
  the program ends (`Pty::exited()` exists; add what is missing: a floating overlay on a bare
  Surface root, and `Dialog`/`Confirm(timeout:)` that close themselves).
- `Terminal` emits an `exit` event with the status (`onExit:` on the element).
- `Tui::run/inline/headless(fps:)` caps the frame rate (default 60).
- The parser is ours: `src/Surface/AnsiParser.php` is part of this MIT package. Remove every
  licence caveat about it (README "Licence" section becomes "MIT."; the DEVNOTES note about the
  generator's licence goes), remove `charm/parsing` from `composer.json` entirely, keep
  `ansi.cpeg` and the `grammar` script for regeneration with a globally installed `charm-compile`
  (say so in DEVNOTES), and make `tests/Surface/GrammarTest.php` skip when the compiler is absent.
- Delete the merged `widget/*` branches locally and on origin after checking each is merged.
- CHANGELOG: a `## 0.0.3` summary (ten lines, what 0.0.3 brought over 0.0.2) above the beta
  sections, then `## 0.0.4-beta1` with each entry stating what a user sees differently.
