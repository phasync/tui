# phasync/tui — specification

A terminal UI library for PHP, built bottom-up from one primitive: a **Surface** that is a terminal
emulator. Everything that shows on screen is a Surface; a shell in a pty and a PHP coroutine that
`echo`s are the same kind of producer, writing the same bytes into it. On top: viewports onto
surfaces, a compositor for the real terminal, layouts (splits, stacks), and components with a
lifecycle and a coroutine of their own. Think tmux as a library, with Tether-style components.

Package `phasync/tui`, namespace `phasync\Tui`. PHP 8.3+. Depends on `phasync/phasync` (^2.0.7)
for coroutines (`phasync::go/service/readable/writable/sleep/awaitFlag/raiseFlag`) and
`phasync\Util\StringBuffer`. ext-phasync is optional: with it, `phasync\ext\sapi()` routes a
coroutine's `echo` into a Surface; without it, the application calls `$surface->write()`. Nothing
else is platform-specific beyond what is stated below. MIT.

Audience: any PHP developer writing a command-line program, not only phasync users. The API must
read naturally to someone who knows the terminal and PHP, with no knowledge of the internals.
Design for the general case; no API exists for one demo.

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
  bracketed paste 2004, mouse modes 1000/1002/1006, application cursor keys), save/restore cursor
  (DECSC/DECRC and CSI s/u), tab stops, OSC strings (title: stored; others parsed and ignored),
  DCS/APC/PM/SOS strings parsed and ignored, and ESC sequences (RIS, IND, RI, NEL, HTS,
  charset designations parsed, DEC line drawing set honoured). Unknown sequences are consumed
  without corrupting the output. An escape sequence split across two `write()` calls is handled.
  Scrolling off the top goes to scrollback (configurable limit, default a few thousand lines).
- Replies: a Surface answers the few queries a program may send (DSR 5/6, DA primary) by handing
  the reply bytes to its producer (see `Input`). Title changes are observable.
- Parsing must be efficient on large streams: find the next control byte with `strcspn`, copy
  runs of printable text in one step, never copy the remainder of a large buffer per step.
  `cat` of a 10 MB file into a Surface must run at a sensible fraction of the I/O speed.
- Direct drawing, bypassing the parser, into the same cells: `put($x, $y, string $text, ?Style)`,
  `fill(rect, char, Style)`, `clear()`, `scroll($n)`, `resize($cols, $rows)` (reflow is not
  required; preserve what fits), `cell($x, $y)`, `line($y)` as text and as styled runs.
- Damage tracking: which rows (or cells) changed since the last `takeDamage()`, so a compositor
  repaints only what changed.
- Cursor: position, visibility, shape (as far as reported by the stream).
- Input side: a Surface has an input channel that the focused producer reads keys from. For a pty
  the library writes to the master; for a coroutine the bytes are readable from a stream or
  `StringBuffer` of the Surface. Terminal replies (DSR, DA) go to the same channel.
- `toString()`/`dump()` for tests and headless use: the visible text, and a styled form.

## 2. Producers

- **Pty**: runs a command with `proc_open(..., [['pty'], ['pty'], ['pty']])` and pumps its output
  into a Surface, keys from the Surface's input to the process, with phasync coroutines
  (`readable()`/`writable()`), never blocking the loop. Resize: `stty -F <slave> cols C rows R`
  from a helper process (sets the size and raises SIGWINCH in the child); the slave path comes
  from the child or `/proc`. Exposes exit status, `wait()`, `kill()`. The environment sets
  `TERM=xterm-256color` and `COLORTERM` to match what the Surface implements.
- **Coroutine**: `Surface::run(Closure)` runs the closure in a coroutine of the Surface's. With
  ext-phasync the closure runs under `phasync\ext\sapi()` so its `echo`/`print`/`printf` and
  `fwrite(STDOUT)`-free output lands in the Surface; without it, the closure receives the Surface
  and writes with `$surface->write()`. Document the difference plainly. Either way `symfony/console`
  or `laravel/prompts`-style ANSI output in the closure renders correctly, because the Surface is a
  terminal.
- **Direct drawing**: the cell API above (graphs, tables, borders), used by widgets and apps.

## 3. Viewport

A rectangle on a parent surface that shows a rectangle of a child Surface: offset (x, y), clipping,
scroll position that may follow the tail or stay pinned in scrollback, and optional borders/title
drawn by the viewport, not the child. A Surface may be larger than its viewport in both axes.

## 4. Screen (the real terminal)

- Takes over the terminal: raw mode (via `stty` or ext-phasync when available), alternate screen,
  cursor hidden while composing, restored on exit and on exceptions/signals.
- Holds a Surface of the terminal's size; composites viewports in z-order into it; a differ emits
  the minimum cursor moves and SGR runs against the previous frame. Frames at most 60/s, only when
  damaged; a `phasync::service()` coroutine drives it.
- Input decoder: keys (legacy, SS3, CSI, CSI-u/kitty basics, alt-modified, UTF-8 text), mouse
  (SGR 1006, buttons, wheel, drag), paste (bracketed), focus in/out. Delivered as typed events
  (`Key`, `Mouse`, `Paste`, `Resize`) to the focus owner; a Surface receiving a Key/Mouse encodes
  it to the bytes its producer expects (application cursor key mode, mouse mode of that Surface).
- Resize: SIGWINCH via pcntl when available, otherwise polling `stty size`; propagates through the
  layout to Surfaces and ptys.
- **Headless backend**: `Screen` must work against an in-memory terminal (`FakeTerminal`) with a
  given size, where tests and headless programs read the composed frame as text and the emitted
  byte stream, and inject input events. Every feature must be testable without a tty.

## 5. Layout

- `Split` (horizontal/vertical) with children sized fixed, percentage or weight, with optional
  separators; `Stack` (z-order, for overlays and modal dialogs, with placement and size); nesting.
  Resizing re-lays children and resizes their Surfaces and ptys.
- Focus: one focus owner; `Tab`/`Shift-Tab` order, programmatic focus, mouse click to focus
  (opt-in).

## 6. Component

A class owning a Surface and a lifecycle, after Tether: `mount()`, `render()` (either draw into
`$this->surface` directly or return a child layout), `run()` (a coroutine running while mounted;
`go()` for more, all cancelled on unmount), `requestRender()` (batched into the next frame),
`dispose()`, children with keys, event handlers for keys/mouse/focus/resize, and an error boundary
that contains a child's failure to its rectangle. Built-in components, minimal but complete:
`Terminal` (a pty in a component), `Log` (append-only text with scrollback and follow),
`TextInput` (single line with editing keys and cursor), `StatusBar`, `Box` (border + title around
a child). More widgets belong to applications or later versions.

## 7. Deliverables for every version

- `composer.json` (`phasync/tui`, PSR-4 `phasync\Tui\` → `src/`), `composer install` works
  offline-friendly (phasync from Packagist).
- `src/`, `tests/` (Pest, run with `vendor/bin/pest`, green; also green with ext-phasync loaded via
  `php -d extension=vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so`). The VT parser has
  known-answer tests for every sequence class above and tests from recorded real program output
  (e.g. `ls --color`, a `symfony/console` progress bar, `vim` startup/exit, `htop` frames captured
  with `script`). A throughput test for large streams.
- `examples/`: at least `split-shells.php` (two shells side by side, Tab to switch, status bar,
  resizes with the terminal, scrollback with the wheel), `coroutine-pane.php` (one pane a shell,
  one a PHP closure with `echo`, a progress bar and colors), `dashboard.php` (direct drawing).
- `README.md` (what it is, install, the three-minute tour, the model: Surface → producers →
  viewport → screen → layout → component), `docs/` one page per layer with every public method
  described, and `CHANGELOG.md` with a section per version (`## 0.0.1-betaN (date)`), each entry
  stating what changed and what it fixes in terms a user recognises. Every public class and method
  has a docblock that says what it does and when to use it.
- A git tag for the version, locally.

## Non-goals (for now)

Windows console, Unicode bidi, true reflow on resize, an HTML/CSS layer, a widget catalogue.
