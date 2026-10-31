# phasync/tui — specification

A terminal UI library for PHP, built bottom-up from one primitive: a **Surface** that is a terminal
emulator. Everything that shows on screen is a Surface; a shell in a pty and a PHP coroutine that
`echo`s are the same kind of producer, writing the same bytes into it. On top: viewports onto
surfaces, a compositor for the real terminal, layouts (splits, stacks), and components with a
lifecycle and a coroutine of their own. Think tmux as a library, with Tether-style components.

Package `phasync/tui`, namespace `phasync\Tui`. PHP 8.3+. MIT.

**Runtime: `fiber-interop/api` only.** All concurrency goes through the `fiber\` functions
(`run`, `go`, `await`, `await_all`, `await_any`, `service`, `sleep`, `readable`, `writable`,
`fiber\Deferred`, `fiber\Timeout`), so the library runs unchanged on phasync, Revolt/AMPHP and
ReactPHP. Its README and docs are in `vendor/fiber-interop/api` after install. The library's
`composer.json` requires `fiber-interop/api` and, as a dev dependency, the adapter
`fiber-interop/phasync` (which brings `phasync/phasync`); the tests run under that adapter. Do not
use the `phasync` class or `phasync\Util\*` in `src/` (phasync's own types would tie the library to
one runtime). Two things `fiber\` lacks are to be solved inside the library in the plain way:
- **Cancellation** is not in `fiber\`: a component's coroutines end cooperatively. The component
  exposes a "stopped" awaitable (a `Deferred`) that resolves on unmount; the frame loop and other
  waits use `await_any([$work, $stopped])` or short sleeps that check `isMounted()`; a `Pty` ends
  by killing its process, which ends the pump's `readable()`.
- **Byte queues between coroutines** (pty output → Surface, keys → producer): a small internal
  buffer class of this library, with `await`-able readiness via `Deferred`.
If the `fiber\` surface proves genuinely insufficient for something the spec requires, say exactly
what and why in DEVNOTES.md and in the report, with the smallest `fiber\` addition that would do;
do not quietly reach for phasync, and **do not work around the API's limits by going beneath it**:
no `\Fiber` API (`Fiber::getCurrent()` and the like), no Swoole or Revolt calls, no assumption about
what a task is. What `fiber\go()` returns is opaque: it is only ever passed to `await`, `await_all`
or `await_any`, or compared with `===` against `fiber\current()` (api 0.3.1), never inspected. A gap is a finding to report
(the owner of `fiber-interop/api` would rather add the primitive than have libraries guess), and
until it is filled the library does without, in the plain way, even at some cost.

ext-phasync is optional: with it, `phasync\ext\sapi()` routes a coroutine's `echo` into a Surface
(the one place that may mention phasync, guarded by `function_exists`); without it, the application
calls `$surface->write()`.

Audience: any PHP developer writing a command-line program, not only phasync users. The API must
read naturally to someone who knows the terminal and PHP, with no knowledge of the internals.
Design for the general case; no API exists for one demo.

## Design principles

- **Structurally better than what PHP has.** symfony/console, laravel/prompts and termwind print.
  symfony/tui (2026, experimental; widgets with a CSS-like stylesheet and Twig templates on Revolt)
  is a widget toolkit that paints cells: it has no terminal emulator and cannot host a program in a
  window. This library emulates: every window is a terminal, and widgets are programs writing to
  one. It also runs on any fiber-interop runtime, Revolt included. The yardsticks are the best of
  other languages: tmux and Zellij for
  windowed terminals and layouts, Textual (Python) and Ink (Node) for the component model,
  Ratatui and Bubble Tea for how widgets and events are shaped. Take their best idea per layer and
  make it read like PHP, not like a port.
- **A real terminal in every window, no cheating.** The Surface is a faithful xterm-class emulator
  and is the only way pixels get on screen. No special cases for known programs, no shortcuts that
  make the library's own output look right while a foreign program's would not, no shelling out to
  `tput`/`clear` for drawing. The windowed shell command (vim, htop, a build, an ssh session in a
  pane of a PHP program) is the differentiator; it only works if the emulator is honest.
- **Clean layers, one structure.** Each layer depends only on the one below it and is usable
  alone: a Surface without a terminal, a Pty without a layout, hosting without components. And the
  structure is self-similar: the root is a Surface hosting Surfaces, each of which may host
  Surfaces; the real terminal is only a device attached to the root. No layer knows about
  phasync, Swerve or any application.
- **Small, complete, consistent API.** One way to do each thing; names a terminal person expects
  (rows/cols, scrollback, focus, cursor); the same shape for every producer. Documented so a
  program can be written from the docs alone.

## 1. Surface (the primitive)

A grid of cells with its own `cols` × `rows`, independent of the screen, plus scrollback.

- A cell: grapheme (one or more code points; wide East-Asian characters occupy two cells, the
  second a continuation), fg, bg (default / 16 / 256 / truecolor), attributes (bold, dim, italic,
  underline, blink, inverse, strikethrough, hidden).
- `write(string $bytes)`: the VT state machine. It parses ANSI/VT100–xterm byte streams and
  mutates the cells: printable text with autowrap; C0 controls (BS, HT, LF, CR, BEL); CSI cursor
  movement (CUU/CUD/CUF/CUB/CNL/CPL/CHA/CUP/VPA/HPA), ED/EL erase, IL/DL, ICH/DCH, ECH, SU/SD,
  DECSTBM scroll region, SGR (all attributes, 16/256/truecolor, reset), DECSET/DECRST private
  modes needed by common programs (DECAWM autowrap, DECTCEM cursor visibility, alt screen 1049,
  bracketed paste 2004, mouse modes 1000/1002/1003/1006, focus events 1004, synchronized output
  2026, application cursor keys, Kitty keyboard protocol push/pop/query (`CSI > u`, `CSI < u`,
  `CSI ? u`) and modifyOtherKeys as state the Surface's key encoder honours), save/restore cursor
  (DECSC/DECRC and CSI s/u), tab stops, OSC strings (title: stored; others parsed and ignored),
  DCS/APC/PM/SOS strings parsed and ignored, and ESC sequences (RIS, IND, RI, NEL, HTS,
  charset designations parsed, DEC line drawing set honoured). Unknown sequences are consumed
  without corrupting the output. An escape sequence split across two `write()` calls is handled.
  Scrolling off the top goes to scrollback (configurable limit, default a few thousand lines).
- Replies: a Surface answers the queries a program may send (DSR 5/6, DA primary/secondary,
  XTVERSION, DECRQM mode queries, Kitty keyboard query, OSC 10/11 colour queries with a sensible
  answer) by handing the reply bytes to its producer (see `Input`), as the terminal it claims to
  be in `TERM`. Title changes are observable. OSC 52 clipboard writes from a program are passed up
  to the host terminal.
- Widths: grapheme clusters by Unicode rules (combining marks, ZWJ emoji sequences, variation
  selectors), East Asian wide and emoji presentation as two cells, as modern terminals do; a
  program that assumes those widths (Ink does) must line up.
- **ANSI is the only way to render into a Surface.** There is no direct cell-drawing API
  (no `put()`/`fill()`): widgets, borders, graphs and the compositor's own drawing all go through
  `write()` as escape sequences, so that every bug in the parser shows up at once in the library's
  own output. Correctness of the emulator comes before everything else in this library; a sequence
  that a real xterm handles must produce the same cells here. A small `Ansi` helper that *builds*
  sequences (cursor to x,y; SGR for a style; erase) is welcome, as the producer side of the same
  protocol.
- Parse character by character, properly. The parser is generated from a PEG grammar with
  `charm/parsing` (Packagist; `vendor/bin/charm-compile grammar.cpeg -o ... -c ...` produces a
  standalone PHP class): the grammar `src/Surface/ansi.cpeg` is the readable definition of what the
  emulator accepts (the VT500 structure: C0 controls, ESC sequences, CSI with params and
  intermediates, OSC/DCS/APC/PM/SOS strings with ST or BEL, UTF-8 text runs), the compiled class is
  committed next to it, and a test asserts the committed class is current. Each `write()` chunk is
  parsed as a sequence of items with semantic actions applying them to the cells; a sequence cut
  off at the end of a chunk is matched by a lowest-priority "incomplete tail" rule and kept for the
  next chunk. Do not take shortcuts for speed; PHP is fast enough when the parser is written well
  (a cursor into the input, no copying of the remainder per step). Correctness first; measure later.
  If charm/parsing turns out unable to express or run this, say exactly why in DEVNOTES.md and in
  your report, and only then hand-write the state machine.
- Reading the cells: `cell($x, $y)`, `line($y)` as text and as styled runs, `resize($cols, $rows)`
  (reflow is not required; preserve what fits).
- Damage tracking: which rows (or cells) changed since the last `takeDamage()`, so a compositor
  repaints only what changed.
- Cursor: position, visibility, shape (as far as reported by the stream).
- Input side: a Surface has an input channel that the focused producer reads keys from. For a pty
  the library writes to the master; for a coroutine the bytes are readable from a stream or
  `StringBuffer` of the Surface. Terminal replies (DSR, DA) go to the same channel.
- `toString()`/`dump()` for tests and headless use: the visible text, and a styled form.

## 2. Producers

- **Pty**: runs a command with `proc_open(..., [['pty'], ['pty'], ['pty']])` and pumps its output
  into a Surface, keys from the Surface's input to the process, with `fiber\go()` coroutines and
  `fiber\readable()`/`writable()`, never blocking the loop. Resize: `stty -F <slave> cols C rows R`
  from a helper process (sets the size and raises SIGWINCH in the child); the slave path comes
  from the child or `/proc`. Exposes exit status, `wait()`, `kill()`. The environment sets
  `TERM=xterm-256color` and `COLORTERM` to match what the Surface implements.
- **Coroutine**: `Surface::run(Closure)` runs the closure in a coroutine of the Surface's. With
  ext-phasync the closure runs under `phasync\ext\sapi()` so its `echo`/`print`/`printf` and
  `fwrite(STDOUT)`-free output lands in the Surface; without it, the closure receives the Surface
  and writes with `$surface->write()`. Document the difference plainly. Either way `symfony/console`
  or `laravel/prompts`-style ANSI output in the closure renders correctly, because the Surface is a
  terminal.
- **Program-generated output**: widgets and applications that draw (graphs, tables, borders)
  produce escape sequences, with the `Ansi` helper, and `write()` them like any other producer.

## 3. Surfaces hosting surfaces (the structure)

**The design is self-similar: the root is a Surface hosting Surfaces.** There is no separate
"screen" kind of thing. A Surface can host child Surfaces, each placed through a **Viewport**
(a rectangle on the host showing a rectangle of the child: offset, clipping, z-order, a scroll
position that follows the tail or stays pinned in scrollback, optional border and title drawn by
the host). A hosted Surface can itself host Surfaces, to any depth: a pane of a multiplexer running
another multiplexer is the same structure one level down, and so is a component tree.

- **Compositing is writing.** When a child is damaged, its host repaints the child's visible,
  damaged cells into its own cells **by writing escape sequences to itself** (cursor positioning
  and SGR runs, through the same `write()` as every producer), clipped to the viewport. Damage then
  propagates up: the host is damaged where it repainted, so its host repaints, up to the root.
  Nothing paints cells directly; the ANSI-only rule holds for the compositor too.
- **Always a diff, never a full re-render.** Every level works from damage: a Surface records which
  cells changed since its damage was last taken; a host writes only those (coalesced into runs of
  same style per row), and the device writes only the root's cells that differ from the previous
  frame. A whole viewport is repainted only when all of it did change: newly shown, scrolled,
  resized, or its z-order neighbour moved. A pane printing continuously costs its changed cells per
  frame, whatever the byte volume; an idle pane costs nothing. Tests assert the byte count the
  device emits for a one-cell change is a few dozen bytes, not a frame.
- **Input goes down the same tree.** A host has one focused child (or itself). Key, mouse and paste
  events arrive at the root and are passed down the focus path; at the focused leaf, the Surface
  encodes them to the bytes its producer expects (application cursor keys, Kitty keyboard
  state, mouse mode, bracketed paste state of *that* Surface) and puts them on its input channel.
  Mouse coordinates are translated by each viewport on the way down. A host may handle an event
  itself first (a multiplexer's prefix key, a mouse drag for selection when the child has not asked
  for mouse reporting).
- **Replies go up.** A hosted Surface's replies to its producer's queries (DSR, DA, Kitty query,
  OSC 52 clipboard) stay with that Surface's producer; OSC 52 and title changes bubble to the root
  so the real terminal gets them.
- **Resize goes down.** Resizing a host re-lays its viewports (section 5), which resizes the
  children, which resize their ptys.

## 4. The real terminal

The root Surface is attached to the terminal by a **Terminal device**, which is only an I/O
adapter: it writes the root's damage to the tty as the minimum cursor moves and SGR runs against
the previous frame (at most 60 frames a second, only when damaged, driven by a `fiber\go()`
coroutine of the device that ends when it detaches), and decodes the tty's input into events for
the root (keys: legacy, SS3, CSI, CSI-u/Kitty, alt-modified, UTF-8 text; mouse: SGR 1006, buttons,
wheel, drag; bracketed paste; focus in/out; resize via SIGWINCH with pcntl or polling `stty size`).
It takes over the terminal (raw mode via `stty`, alternate screen, cursor hidden while composing,
mouse and bracketed paste and focus reporting enabled, the Kitty keyboard protocol pushed if the
host supports it) and restores it on exit, exceptions and signals.

- **Headless**: a `FakeTerminal` device of a given size, for tests and headless programs: it
  exposes the root's frame as text and the emitted byte stream, and injects input events. Every
  feature is testable without a tty.
- Self-hosting is the structure's own test: a root attached to a `FakeTerminal` whose byte stream
  is written into a Surface must yield the same cells as the root.

### 4.1 Inline mode: a live region at the bottom of the terminal's own scrollback

A program that runs several processes and shows a progress line for each, or a few progress bars
and a total, should not take the terminal over. `Tty::inline()` shows the root Surface **in the
terminal's normal screen**: no alternate screen, mouse reporting off unless the program asks (the
terminal's own selection and scrolling keep working), raw mode only while the region is live, the
terminal restored on exit with the last frame left standing (or erased, `keep: false`).

The model is the Surface's own: the root Surface's visible rows are the live region, and **the
root's scrollback is the terminal's scrollback**. The region sits at the bottom, under everything
the terminal already shows, and is as tall as the root's content, at most the terminal's height.
When the content grows past that, the root scrolls: the rows that leave the top of the Surface are
written once more into the terminal above the region, as they last looked, and are never touched
again. So a progress line that runs from 1% to 100% stays live while it is in the region and
freezes at whatever it showed when newer lines pushed it out, exactly as if the program had printed
it. Plain output from the program (`$tty->println(...)`, or `echo` captured the same way as a
coroutine's into a Surface) goes to the same place: into the scrollback above the region, which is
redrawn under it.

The device paints with relative cursor motion only (the region moves whenever the terminal
scrolls), repaints the damaged cells against the previous frame as in fullscreen mode, and parks
the cursor on the line under the region. A width change repaints the whole region; a height change
of the content scrolls the terminal by the difference and repaints. Resizing the terminal below the
region's height scrolls the top rows out like content growth does.

Nothing above the device changes: the same component tree runs fullscreen with `Tty::attach()`,
inline with `Tty::inline()`, and headless under `FakeTerminal` (which gains the same mode, exposing
the frozen lines and the live region separately). Limits to state in the docs: while the region is
live the program reads its keys through the library, not `fgets(STDIN)`; a hosted alternate-screen
program (vim) inside an inline region is possible but pointless.

Tests: five progress bars and a total under `FakeTerminal::inline()`; twenty process lines on a
10-row terminal, finishing in random order, where the frozen text of each line is what it showed
when it left the region; `println()` between frames puts its lines above the region and the region
is intact after; a byte-stream replay into a Surface of the same size shows the same frozen lines
and the same region.

## 5. Layout

- A layout arranges a host's viewports: `Split` (horizontal/vertical) with children sized fixed,
  percentage or weight, with optional separators; `Stack` (z-order, for overlays and modal dialogs,
  with placement and size); nesting by giving a child Surface a layout of its own. Resizing re-lays
  children and resizes their Surfaces and ptys.
- Focus: one focus owner; `Tab`/`Shift-Tab` order, programmatic focus, mouse click to focus
  (opt-in).

## 6. Component

A class owning a Surface and a lifecycle, after Tether: `mount()`, `render()` (either draw into
`$this->surface` directly or return a child layout), `run()` (a coroutine running while mounted;
`go()` for more, all told to stop on unmount through the component's stopped awaitable, and the
component's disposal waits for them briefly), `requestRender()` (batched into the next frame),
`dispose()`, children with keys, event handlers for keys/mouse/focus/resize, and an error boundary
that contains a child's failure to its rectangle. Built-in components, minimal but complete:
`Terminal` (a pty in a component), `Log` (append-only text with scrollback and follow),
`TextInput` (single line with editing keys and cursor), `StatusBar`, `Box` (border + title around
a child). More widgets belong to applications or later versions.

## 8. The components layer (phase 2, from 0.0.2)

Phase 1 proved the base: a real terminal in every window. Phase 2 builds the layer applications
are written in. Four decisions, then the widgets.

### 8.1 Declarative rendering on the same ANSI path

`render()` may still draw with `Ansi` (right for a graph), or **return an element tree** of plain
PHP objects: `Column`/`Row` (the Splits), `Layers` (the Stack), `Box`, `Text`, and every widget
below is an element. The library lays the tree out into the component's surface tree and paints
each element **through `write()`**, exactly as any producer; the ANSI-only rule holds. Elements
are cheap values rebuilt on every render; the library keys them (by position, or an explicit
`key:`) so that the Surfaces, scroll positions, focus and child components behind them persist
across renders, as Tether keys children. A child component is placed in the tree as an element
(`$this->child('log', fn () => new Log())` returned where it goes). No markup parser in this
phase; the tree is the model a Twig/HTML front-end can target later.

### 8.2 Reactive: state changes re-render, nobody asks

A component is rendered again in the next frame after: an event handler of its ran (key, mouse,
focus, a child's event); one of its coroutines (`run()`, `go()`) resumed from a wait and returned
to the scheduler; `$this->state->set(...)` or an assignment on `$this->state` (a small object the
component owns, with typed public properties, whose writes mark the component dirty); a child
emitted an event it listens to; its props changed (`propsChanged()`). Many changes in a tick are
one render; renders are batched into the frame as today. Computed values are methods; there are
no signals, observables or dependency tracking. Document the rule in one paragraph, and make
`requestRender()` the rare explicit call it should be.

### 8.3 A small stylesheet

Elements take a `style:` (or `class:`) and the application a `Stylesheet`: properties `color`,
`background`, `bold`, `dim`, `italic`, `underline`, `inverse`, `border` (style and colour),
`padding`, `align`, `width`/`height` constraints where the element allows; selectors by element
type, `.class`, `#id`, and states `:focused`, `:disabled`, `:selected`, `:hover` (mouse);
theme variables (`$accent`) and a default theme that looks good on dark and light terminals;
cascade: stylesheet rules → classes → inline. Not CSS layout; layout is the tree above.

### 8.4 Key bindings as data

A component declares `bindings(): array` (`['ctrl+s' => 'save', 'enter' => 'submit']`) mapping
to its public methods; the application overrides per component class or instance; the library
dispatches, and a `Help` element can list the active bindings. Handlers remain possible for
anything a map can't express.

### 8.5 Overlays: anything can float

A widget may show a surface on top of everything, outside its own rectangle and its parents'
clipping: a dropdown's list, autocomplete under an `Editor`, a context menu, a tooltip, a command
palette, and `Dialog` itself. One primitive, on the component:
`$this->overlay($element, anchor: Rect|null, place: 'below'|'above'|'center'|Rect, modal: bool)`
returns an `Overlay` handle (`close()`, `closed()` awaitable, `result`). The library hosts the
element on the root component (in a nested instance, the inner root) at absolute coordinates from
the anchor, and orders later overlays above earlier ones, keeping the order across renders. An
overlay's desired size is measured again every frame: when its content grows or shrinks it is
placed again and the cells it no longer covers are repainted by what is under them. **The root's rectangle is a hard boundary**: nothing of an overlay is ever placed outside
it (in a nested instance the inner root's pane is that boundary, as in tmux). Placement, in
order: the preferred side of the anchor if the overlay fits there; else the opposite side if it
fits; else the side with more room, with the overlay **shrunk to that room** and its content
scrolling (a dropdown of 30 items in 6 free rows shows 6 and scrolls; it never clips silently);
horizontally the overlay is shifted left or right to stay inside, and narrowed only when wider
than the root. Every overlay element therefore supports a height and width smaller than its
content (a `ScrollView` inside, or the element's own scrolling), and is re-placed by the same rules
when the root or the anchor changes. The decision is a **pure function** of what a floater needs to
know: the anchor's absolute rectangle (`Surface::area()`), the root's size, the overlay's desired
size and its placement preference; it returns the rectangle to host at. Implement it as a small
value class (`Placement::for(anchor, root, desired, place)`), tested on a table of cases (bottom
row, right edge, no room on either side, a terminal smaller than the overlay), used by `overlay()`
and available to any widget that wants to decide for itself; the `area` event is what triggers a
re-decision. **Resizes**, which happen at any time, go through the same path: the root's size
changes, the layout re-lays the tree, anchors move or disappear, and overlays are re-placed *after
the layout pass of the frame that handles the resize and before painting* (an anchor's rectangle
is not final earlier), once per frame however many resizes arrived. An overlay keeps its state
across re-placement (selection kept, the scroll offset adjusted so the selected row stays visible
in the new height); one whose anchor is no longer shown closes, with its result cancelled. Test:
open a dropdown at the bottom, shrink the terminal under it, grow it again; open a dialog, shrink
the terminal below the dialog's size. Focus moves
to the overlay by the surface tree's own rule; Escape, a click outside, or the anchor's component
losing focus closes a non-modal overlay; a modal one takes all input until closed; closing returns
the focus to where it was. The overlay follows its anchor when the anchor's area changes (resize,
scroll, layout rebuild) and closes when the anchor is no longer shown. `Dialog` is an overlay with
`place: 'center'` and `modal: true`; `Select`'s popup and `Editor`'s autocomplete are the first
non-modal users.

### 8.6 Widgets, in this order

Each one complete, documented, with its states styled and its keyboard and mouse behaviour the
one a terminal user expects:
1. `Editor`: TextInput grown up: multi-line, word wrap, undo/redo, kill ring, selection with
   shift+arrows and mouse, bracketed paste, a `maxLines`/single-line mode that replaces TextInput.
2. `Select` (the ListView grown up): header row, sortable columns, filter-as-you-type,
   multi-select, `onSelect`/`onActivate`, keeps selection across `setItems()`.
3. `Table`: columns with widths, alignment, truncation, horizontal scrolling of wide tables.
4. `Dialog`: modal over a `Layers`, title, buttons, returns its result as an awaitable
   (`$ok = await($this->dialog(new Confirm('Delete?')))`), Escape cancels, focus returns.
5. `Form`: fields (`Editor`, `Checkbox`, `Radio`, `Select`, `Button`), tab order, validation
   messages, submit.
6. `Tabs`, `ProgressBar`, `Spinner` (driven from a coroutine), `Tree` (expand/collapse, lazy
   children), `ScrollView` (any content larger than its box, both axes).
7. `Split` separators draggable with the mouse; `Terminal` gets a raw-output tap (`onOutput`).
8. `Markdown` viewer (headings, lists, code blocks with ANSI highlighting through a pluggable
   highlighter, links), streaming-friendly: appending text re-renders only the tail.

Also: `Ansi::clearHome()` and the small helpers users kept writing; a clear error when a program
is started with a `fiber-interop/api` older than required.

## 9. Simple to build, good-looking by default (phase 3, from 0.0.3)

The emulator, the hosting and the widgets exist. The next measure is how little a programmer has
to know and write to get a dynamic UI that looks good. symfony/tui is the reference for this
part: its Tailwind-style utility classes, its one-object entry point and its markup in text are
what PHP programmers will expect; this library offers the same vocabulary on top of its own
model, and is judged by the sixty-line dashboard at the end of this section.

### 9.1 One file, no class

A program is an element tree and a runner. `Tui::run($root)` takes an element, a component or a
closure returning either, attaches the `Tty`, runs until the root quits (Ctrl-Q by default when
nothing binds it) and gives the terminal back; `Tui::inline($root)` does the same in inline
mode (4.1); `Tui::headless($root, cols, rows)` returns the `FakeTerminal`. A closure is a
component: it is rendered again whenever state it reads changes, so

```php
fiber\run(function () {
    $s = new State(['done' => 0]);
    fiber\go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { fiber\sleep(0.05); $s->done = $i; } });
    Tui::inline(fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
    ]));
});
```

is a whole program (`fiber\run()` is the one entry point of `fiber\`; `go()` outside it throws, and
`run()` returns when the program's own coroutines have finished, so a coroutine that should stop
with the screen checks a flag or the runner's result). Element content may be a closure (`new Text(fn() => ...)`) so a single
element can be dynamic without the tree around it being rebuilt. Classes stay for anything with
behaviour of its own; a closure component is the same thing without a name.

### 9.2 Utility classes

`class:` takes utility classes next to the program's own, resolved by the stylesheet with the
Tailwind vocabulary (as symfony/tui spells it, so what people know works here):

- spacing: `p-{n}`, `px-`, `py-`, `pt-`/`pr-`/`pb-`/`pl-`, `gap-{n}`, `m-{n}` (margin, the
  element's space inside its parent);
- border: `border`, `border-round`, `border-double`, `border-heavy`, `border-ascii`,
  `border-none`, `border-{color}`, `title-center`;
- colour: `text-{color}` and `bg-{color}`, where a colour is a Tailwind family and shade
  (`sky-400`, `slate-800`), a hex (`[#ff5500]`), a palette index, or one of the 16 names
  (`red`, `bright-blue`); the device degrades truecolour to 256 or 16 colours when the terminal
  has no more, by nearest colour, in one place;
- text: `bold`, `dim`, `italic`, `underline`, `strike`, `inverse`, `text-left/center/right`,
  `truncate`, `wrap`/`nowrap`;
- size and layout: `w-{n}`, `h-{n}`, `w-1/2`, `w-full`, `grow`, `grow-{n}` (fr), `shrink-0`,
  `items-start/center/end`, `justify-start/center/end/between`, `hidden`;
- variants: `focus:`, `hover:`, `disabled:`, `selected:` prefixes for states, on elements as well
  as components (an element enters `selected` through a `selected:` argument or a `state:` list,
  `hover` through the pointer over it, `focus` through the focused component containing it); and
  `sm:`, `md:`, `lg:`, `xl:` for the root's width with Tailwind's min-width meaning: the base
  class applies at every width, `sm:` from 60 columns, `md:` from 80, `lg:` from 100, `xl:` from
  140, later breakpoints overriding earlier ones, so `class: "flex-col md:flex-row"` is a column
  under 80 columns and a row from 80 on.

Order in the cascade: stylesheet rules, then utility classes (later ones win), then `style:`.
Everything a utility class does is a stylesheet property too, so themes and utilities compose.

### 9.3 Markup in text

`Text` and every label (`Box` titles, `Button` labels, `Table` cells, `Help`, `Log` lines)
accept the markup PHP programmers know from symfony/console: `<b>`, `<i>`, `<u>`, `<dim>`,
`<s>`, `<fg=red>`, `<bg=blue>`, `<fg=#ff5500>`, `<span class="text-sky-400 bold">`, closed by
`</>`, with `\<` escaping and `Text::raw()` for none. Markup is parsed once per string and
renders through the same `write()` path as everything else.

### 9.4 Good by default

`Stylesheet::default()` looks like a modern tool with no styling at all: rounded borders,
one accent colour, the focused window's border in the accent, dimmed titles elsewhere, a
visible cursor only where text is edited, `Select` rows in inverse, dialogs with a shadow.
Three themes ship (`dark`, `light`, `mono`), chosen by `Tui::run($root, theme: ...)` or
automatically from the terminal's background (OSC 11, dark by default), and a theme is a
stylesheet, so a program's own rules refine it. Every widget takes the common things as
constructor arguments (`Box(title:, class:)`, `Column(gap:, padding:, align:)`,
`Text(align:)`) so no stylesheet is needed for the first version of anything.

### 9.5 What a programmer no longer meets

No `child()` for elements placed with `new` (keys do it), no `requestRender()` (state does it),
no `Split`/`Stack` for ordinary screens (Row/Column/Layers), no sizes in most places (9.2's
auto rules), no `Surface` unless hosting a program or writing a widget. docs/ gets a
"Cookbook" of ten screens, each under forty lines, and the README opens with the dashboard.

### 9.6 The acceptance test: a sixty-line dashboard

`examples/dashboard.php`, under sixty lines, no class: a header row with a `Spinner` and a title,
three `Box`es side by side with a `ProgressBar` and a `Text` each, updated by three coroutines at
different rates, a `Log` that receives events from them, a `Markdown` pane with release notes,
and a `Help` line; styled with utility classes only, stacked vertically under 80 columns,
good-looking in all three themes, correct under `FakeTerminal` at 60×20 and 160×50, flicker-free
at 10 Hz, and the same program runs in inline mode with `Tui::inline()` showing the three
boxes only. The README shows it with a screenshot.

## The benchmark: an advanced program in a window

The library is judged first by hosting a demanding full-screen program in a window, with
everything working, before any UI component matters. Two programs are the acceptance test:

- **Claude Code** (`claude`, installed here: a Node/Ink program), in a pane next to a shell: typing,
  its redraws (cursor-up/erase-line repaints, no alt screen), colours, emoji and wide characters
  with correct widths, bracketed paste in, its cursor-position and terminal queries answered,
  keyboard protocol negotiation (Kitty keyboard / modifyOtherKeys: pass the host terminal's
  support through, or answer as a terminal that lacks it, consistently), synchronized output
  (mode 2026), focus events (1004), title changes, mouse (when the program asks for it) and
  scrolling, and **copy and paste**: when the pane has not asked for mouse reporting, mouse drag in
  a window selects text in that Surface, highlighted, and copies it to the system clipboard via
  OSC 52 to the host terminal (with `xclip`/`wl-copy`/`pbcopy` as fallback); the host's bracketed
  paste goes to the focused pane as a paste (bracketed if the pane enabled it). What makes this
  benchmark hard is exactly what the emulator must get right.
- **Self-hosting**: `examples/split-shells.php` running inside a pane of another instance of
  itself (as tmux inside tmux), nested twice, with resizes, focus and scrollback working at every
  level. Everything the library emits must be exactly what its own Surface understands.

A general `examples/host.php <command...>` runs any command in a window (with a status bar) so
both can be tried by hand; tests drive them through a root on a `FakeTerminal` and a pty.

## 7. Deliverables for every version

- `composer.json` (`phasync/tui`, PSR-4 `phasync\Tui\` → `src/`; requires `fiber-interop/api`,
  `charm/parsing` only if the compiled parser needs it at runtime; dev: `fiber-interop/phasync`,
  `pestphp/pest`), all from Packagist.
- `src/`, `tests/` (Pest, run with `vendor/bin/pest` under the phasync adapter, green; also green
  with ext-phasync loaded via `php -d extension=vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so`,
  where the `echo`-capture path is exercised). The VT parser has
  known-answer tests for every sequence class above and tests from recorded real program output
  (e.g. `ls --color`, a `symfony/console` progress bar, `vim` startup/exit, `htop` frames captured
  with `script`), and tests comparing the Surface against a reference where one is available
  (e.g. the same bytes through `tmux`/`script` and `tmux capture-pane`, if tmux is installed).
- `examples/`: at least `split-shells.php` (two shells side by side, Tab to switch, status bar,
  resizes with the terminal, scrollback with the wheel), `coroutine-pane.php` (one pane a shell,
  one a PHP closure with `echo`, a progress bar and colors), `dashboard.php` (graphs and boxes
  drawn with escape sequences from coroutines).
- `README.md` (what it is, install, the three-minute tour, the model: Surface → producers →
  viewport → screen → layout → component), `docs/` one page per layer with every public method
  described, and `CHANGELOG.md` with a section per version (`## 0.0.1-betaN (date)`), each entry
  stating what changed and what it fixes in terms a user recognises. Every public class and method
  has a docblock that says what it does and when to use it.
- A git tag for the version, locally.

## Non-goals (for now)

Windows console, Unicode bidi, true reflow on resize, an HTML/CSS layer, a widget catalogue, a
direct cell-drawing API (deliberately: not until the emulator is proven rock solid).
