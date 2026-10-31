# Surface: a terminal in memory

A `Surface` is a terminal emulator without a screen: a grid of cells (`cols` x `rows`) with a
cursor, scrollback, modes and an input channel. Everything that shows on screen in this library
is a Surface, and the only way to put anything on one is the way a terminal allows: writing
bytes (text, control characters, escape sequences) with `write()`. There is no cell-drawing
API; widgets write escape sequences too, so the emulator is tested by everything the library
draws. Cells that are on a Surface can be copied to another one (`copyArea()`, `drawArea()`):
that is how a host shows the surfaces it hosts (see [hosting](hosting.md)).

```php
use phasync\Tui\Surface;

$s = new Surface(40, 10);                 // cols, rows, scrollback lines (default 5000)
$s->write("\e[1;31merror\e[0m: disk full\r\n");
echo $s->line(0);                         // "error: disk full"
echo $s->cell(0, 0)->style;               // "bold fg=1"
echo $s->dump();                          // "[bold fg=1]error[/]: disk full" ...
```

Coordinates are 0-based: `x` is the column, `y` the row. Row 0 is the top of the screen;
negative rows are the scrollback, `-1` being the line that most recently scrolled off the top.

## What the emulator understands

It emulates xterm as `TERM=xterm-256color` describes it, parsed character by character by a
parser generated from the PEG grammar in `src/Surface/ansi.cpeg` (with
[charm/parsing](https://packagist.org/packages/charm/parsing); the compiled parser is committed and
needs nothing at runtime):

- UTF-8 text with autowrap, grapheme clusters (combining marks, ZWJ emoji, variation selectors),
  East Asian wide characters and emoji presentation as two cells. Malformed UTF-8 shows as U+FFFD.
- C0 controls: BS, HT, LF/VT/FF, CR, BEL, SO/SI; C1 controls in their UTF-8 form.
- CSI: cursor movement (CUU, CUD, CUF, CUB, CNL, CPL, CHA, CUP, HVP, VPA, HPA, HPR, VPR, CBT,
  CHT), ED and EL (including selective erase), IL, DL, ICH, DCH, ECH, SU, SD, REP, DECSTBM,
  tab stops (HTS, TBC), SGR (bold, dim, italic, underline, blink, inverse, hidden, strikethrough,
  16/256/truecolor in both `;` and `:` forms, reset), save/restore cursor (CSI s/u), DECSCUSR
  cursor shape, IRM insert mode, LNM, soft reset (DECSTR).
- Private modes: DECCKM application cursor keys, DECOM origin, DECAWM autowrap, DECTCEM cursor
  visibility, alternate screens 47/1047/1049, mouse tracking 9/1000/1002/1003 with SGR encoding
  1006, focus events 1004, bracketed paste 2004, synchronized output 2026, DECRQM queries.
- Keyboard protocols as state for the key encoder: the kitty keyboard protocol (`CSI > flags u`
  push, `CSI < n u` pop, `CSI = flags ; mode u` set, `CSI ? u` query) and xterm modifyOtherKeys
  (`CSI > 4 ; n m`).
- ESC sequences: RIS, IND, RI, NEL, HTS, DECSC/DECRC, DECKPAM/DECKPNM, DECALN, charset
  designation (the DEC line drawing set is honoured).
- OSC: window title (0, 2) stored, colour queries (10, 11, 12) answered, clipboard writes (52)
  passed up, hyperlinks (8) kept in the cells (see below); other OSC, DCS, APC, PM and SOS
  strings are consumed and ignored.
- Queries answered on the input channel: DSR 5 and 6 (cursor position), DA1, DA2, XTVERSION,
  DECRQM, kitty keyboard flags, OSC 10/11 colours.

Unknown sequences are consumed without corrupting the screen. A sequence or a UTF-8 character
split across two `write()` calls is completed by the next call.

**The last column.** As in xterm, a character written into the last column leaves the cursor
on that column, with the wrap pending: the next character goes to the next row (with autowrap
on), or over the last column again (autowrap off, `ESC [ ? 7 l`). So a drawing that writes a
row to its very end and then `ESC [ K` (erase to the end of the row) erases the character it
has just written, because the cursor is still on it. That is xterm's behaviour, not a bug:
write the row and stop, or erase first (`ESC [ 2 K`, then the text).

**Hyperlinks** (OSC 8, `ESC ] 8 ; id=x ; URI ST`, ended by `ESC ] 8 ; ; ST`): the text written
in between belongs to the link. Each cell carries it in its style (`$cell->style->link`, the
URI, and `linkId`), apart from the colours: SGR, `ESC [ 0 m` included, leaves it on, and
erased or inserted blank cells are not in it. A host passes its windows' links on when it
composites them, and a terminal device writes them to the real terminal when that terminal
supports them (see [terminal](terminal.md)). Build them with `Ansi::link()` or
`Ansi::hyperlink()`. Not implemented, on purpose for
now: left/right margins (DECSLRM), double-width lines, reverse wrap, 132-column mode, palette
queries (OSC 4), DECRQSS, and reflow on resize.

## Constructing and writing

| Method | What it does |
|---|---|
| `new Surface(int $cols = 80, int $rows = 24, int $scrollback = 5000)` | An empty surface; `$scrollback` is how many lines that scroll off the top are kept (0 for none). |
| `write(string $bytes)` | Feed bytes to the terminal, exactly as a program's output reaches a terminal. |
| `resize(int $cols, int $rows)` | Change the size. No reflow: what fits is kept, rows that no longer fit go to scrollback (blank rows below the cursor first), the scroll region is reset. A `Pty` on the surface follows; a host re-lays its windows. |

## Reading

| Method | What it returns |
|---|---|
| `cols()`, `rows()` | The size. |
| `cell(int $x, int $y): Cell` | The cell at a position (negative rows: scrollback). `Cell` has `char` (a grapheme, `''` for the second half of a wide character), `width` (0, 1 or 2) and `style`. Throws `OutOfRangeException` outside. |
| `line(int $y): string` | Row `$y` as text without trailing blanks. |
| `runs(int $y): list<Run>` | Row `$y` as runs of cells sharing a style, covering the full width. `Run` has `x`, `text`, `width`, `style`. Use it to render a row elsewhere or check colours. |
| `isWrapped(int $y): bool` | True if row `$y` continues on the next row because the text wrapped there. |
| `text(): string` / `(string) $surface` | The visible screen: every row without trailing blanks, joined with `"\n"`. |
| `dump(bool $looks = false): string` | The visible screen with styles marked up, for tests: `[bold fg=1]error[/] in line 3`; a literal `[` is doubled. With `$looks`, what a person sees: a blank (which shows a background, but no foreground, bold or italic: `Style::blank()`) is shown in the style of the text next to it when it looks the same in that, so two screens that look the same give the same dump however they were drawn (`FakeTerminal::dump()` is this). |
| `copyArea(int $x, int $y, int $width, int $height): Area` | The cells of a rectangle as the surface shows them (its windows included; negative rows: scrollback; outside it: blanks), to put somewhere else. `Area` has `width`, `height`, `chars` and `styles` (rows of graphemes, `''` for the second half of a wide character, and their styles), `text()`, and `shaded(int $color = Color::DEFAULT)`: the same cells dimmed, as under a shadow. A wide character cut by the edge is a blank in it. |
| `drawArea(Area $area, int $x, int $y)` | Put an `Area` (of this or another surface) on this surface at column `$x`, row `$y`, as its own cells, as if its producer had written them; what falls outside is left out, and the cursor and pen stay as they are (as with a terminal's rectangular copy). |
| `cursor(): Cursor` | The cursor: `x`, `y`, `visible`, `shape` (`Cursor::BLOCK`, `UNDERLINE`, `BAR`), `blinking`. |
| `title(): string` | The window title last set by the program (OSC 0 or 2). |
| `scrollbackSize(): int` | How many lines the scrollback holds (rows -1 down to -size). |
| `linesScrolled(): int` | How many lines have ever scrolled into the scrollback; a view that stays put adjusts by the difference. |
| `isAltScreen(): bool` | True while the program uses the alternate screen (vim, less, htop). |
| `mode(int $mode): bool` | Whether DEC private mode `$mode` is set, e.g. 1 (application cursor keys), 25 (cursor visible), 1000/1002/1003 (mouse), 1006 (SGR mouse), 2004 (bracketed paste), 2026 (synchronized output). |
| `wantsMouse(): bool` | True if the program asked for mouse reports (9, 1000, 1002 or 1003). |
| `keyboardFlags(): int` | The kitty keyboard flags the program enabled, 0 for legacy keys. |
| `modifyOtherKeys(): int` | The xterm modifyOtherKeys level the program set: 0, 1 or 2. |
| `isKeypadApplication(): bool` | Whether the keypad is in application mode (DECKPAM). |
| `setDefaultColors(int $fg, int $bg)` | The colours reported to programs asking for the default foreground and background (OSC 10/11); many choose a light or dark theme from them. Default: light grey on black. |

## Damage: what changed

| Method | What it does |
|---|---|
| `version(): int` | A number that grows with every change. Remember it; `damagedSince()` later says which rows changed after it. Any number of readers can track one surface. |
| `damagedSince(int $version): list<int>` | The rows (ascending) changed after `$version`. |
| `takeDamage(): list<int>` | The rows changed since the previous call (all rows the first time): the simple form for one reader. |

## Events

`on(string $event, Closure $listener): Closure` adds a listener and returns a closure that
removes it.

- `change`: after every `write()` and `resize()`, and when a hosted surface changed
- `resize`: receives a `Resize` (`cols`, `rows`)
- `title`: receives the new title
- `bell`: the program rang the bell
- `clipboard`: the program asked to copy text to the clipboard (OSC 52); receives the text
- `close`: `close()` was called: the session on this surface is over
- `area`: where the surface shows on its root (`area()`, see [hosting](hosting.md)) changed:
  its window, or a window it is in, was moved, resized or scrolled, or it was hosted or
  unhosted; receives the new `Rect`. Told once per change of the tree (a layout's whole arrange
  is one change), only when the rectangle differs from the one told last.
- `key`, `mouse`, `paste`, `focus`: input events on their way through `dispatch()` (see
  [hosting](hosting.md)); a listener returning `true` takes the event.

`close()` hangs a surface up, as a terminal window closing does: everything attached to it is
told the session is over. A terminal device attached to it detaches, a component on it is
disposed (with its children, whose programs are hung up). The cells stay. Detaching a terminal
device closes its root.

## Input

`$surface->input` is the channel the producer reads (see [producers](producers.md)): keys,
mouse reports and pastes given to `dispatch()` arrive there encoded as xterm would encode them
for the modes the program set, and so do the replies to its queries.

```php
$s->write("\e[?1h");                        // the program wants application cursor keys
$s->dispatch(Key::parse('up'));
$s->input->read();                          // "\eOA"
```

## Building escape sequences: `Ansi`

`Ansi` builds the sequences a Surface parses, with 0-based coordinates:

| Method | Sequence |
|---|---|
| `moveTo($x, $y)` | cursor to column `$x`, row `$y` (CUP) |
| `up($n)`, `down($n)`, `right($n)`, `left($n)` | relative moves (CUU, CUD, CUF, CUB); `''` for 0 |
| `column($x)` | to column `$x` of the current row (CHA) |
| `style(Style $style)` | SGR that resets, then sets the style, so the result does not depend on what was set before |
| `fg($color)`, `bg($color)` | foreground or background only |
| `reset()` | SGR 0 |
| `styled($text, $style)` | `$text` in `$style`, then a reset |
| `clearScreen()`, `clearLine()`, `clearToEndOfLine()`, `clearToEndOfScreen()` | ED 2, EL 2, EL 0, ED 0 |
| `clearHome()` | ED 2 then CUP 1;1: erase everything and start at the top-left corner (the start of a full redraw) |
| `hideCursor()`, `showCursor()` | DECTCEM |
| `saveCursor()`, `restoreCursor()` | DECSC, DECRC |
| `scrollRegion($top, $bottom)` | DECSTBM (inclusive rows) |
| `title($title)` | OSC 2 |
| `clipboard($text)` | OSC 52: put text on the clipboard (passed up to the real terminal) |
| `link($uri = '', $id = '')` | OSC 8: start a hyperlink to `$uri` (with an `$id`, pieces of one link are joined); without a URI, end it. Control characters are dropped. |
| `hyperlink($text, $uri, $id = '')` | `$text` as a hyperlink, ended after it |
| `altScreen(bool $on = true)` | mode 1049 |

```php
$s->write(Ansi::moveTo(2, 1) . Ansi::styled('ok', Style::of(Color::GREEN, attrs: Style::BOLD)));
```

## Styles and colours

`Style` is an immutable, interned value (compare with `===`): `Style::of(int $fg = Color::DEFAULT,
int $bg = Color::DEFAULT, int $attrs = 0, string $link = '', string $linkId = '')`,
`Style::plain()`, `has($attrs)`, `withFg($fg)`, `withBg($bg)`, `with($attrs)`, `without($attrs)`,
`withLink($uri, $id = '')`, and `__toString()` (`"bold fg=1 link=https://example.com"`, `''` for
plain). Attributes are bit flags: `Style::BOLD`, `DIM`, `ITALIC`, `UNDERLINE`, `BLINK`,
`INVERSE`, `HIDDEN`, `STRIKE`. `link` is the URI of the hyperlink the cell is in (`''` for
none), `linkId` its id. `blank()` is the style a space in this style looks the same as: its
background and link alone (a space shows no foreground, bold or italic), or the style itself
when it inverts, underlines or strikes through; two blanks look the same exactly when their
`blank()` styles are `===`. A terminal device compares frames by what cells look like, so a
space that only changed an invisible colour costs nothing.

A colour is an int: `Color::DEFAULT` (-1), 0-15 for the named colours (`Color::RED`,
`Color::BRIGHT_BLUE`, ...), 16-255 for the 256-colour palette, or a truecolor from
`Color::rgb($r, $g, $b)` / `Color::hex('#ff8800')`. `Color::isRgb()`, `Color::toRgb()` (RGB of any
colour, null for the default) and `Color::name()` (for display) complete it.

## Measuring and wrapping text: `Text`

The widths the Surface lays text out with, for code that aligns or wraps text. Escape sequences
(colours) and control characters in the text take no cells and are kept where they are, so
styled text can be measured, cut and wrapped too.

| Method | What it does |
|---|---|
| `Text::width(string $text): int` | The cells it takes: wide characters and emoji 2, combining marks 0, escape sequences 0 (`Text::width("\e[31mred\e[0m")` is 3). |
| `Text::wrap(string $text, int $width, bool $words = false): list<string>` | Lay text out in rows of at most `$width` cells, as a terminal that wide shows it: by display width and grapheme cluster, a wide character that does not fit at the end of a row moving to the next, `"\n"` starting a new row. With `$words`, rows break between words (the spaces at a break are dropped; only a word longer than a row is broken). Escape sequences stay in their row, so the rows joined with `"\r\n"` show the same styles. |
| `Text::truncate(string $text, int $width, string $ellipsis = '…'): string` | Cut to `$width` cells, the ellipsis in the last; escape sequences after the cut are kept (a closing reset still resets). |
| `Text::pad(string $text, int $width, string $align = 'left'): string` | Pad with spaces (or cut) to exactly `$width`; `'left'`, `'right'` or `'center'`. |
| `Text::graphemes(string $text): list<string>` | Grapheme clusters by UAX #29 (also where the PCRE2 in use is older than 10.43 and would join consecutive emoji). |
| `Text::clusters(string $text): list<array{string,int}>` | The clusters with their widths. |
| `Text::clusterWidth(string $grapheme): int` | The width of one cluster: 0, 1 or 2. |

**Text that must fit a width that changes** (a chat history, a log, a help text) is kept as
text and wrapped again for the width there is now, never laid out once: a Surface does not
reflow (it is a terminal), so text written for 80 columns stays cut when it shrinks to 40. Keep
the text in your component, and on every render (which runs after each resize) write
`Text::wrap($text, $this->surface->cols(), true)`; `Component\Log` does exactly this for you.

## Performance

About 5 MB/s of plain text and 1.5-2 MB/s of heavily coloured output on PHP 8.5 without JIT:
far more than a terminal program produces.

### What a frame of components costs

Measured on PHP 8.5 without JIT, FakeTerminal 120x60, phasync adapter
(`tests/Benchmark/RenderCostTest.php` holds the budgets, with 10x headroom):

| What | Cost |
|---|---|
| A component rendering a `Column` of 50 `Row([Text, Text])` (about 150 elements) in which one `Text` changed: render, layout, paint, compose, draw | about 1.3 ms of CPU a frame; at 10 Hz about 1.3% of a core |
| The same tree rendered again unchanged (the render returns an equal tree) | the `render()` call and a comparison of the trees: the layout, the windows and the paint stand |
| One `Text` changes in that tree | one surface painted; nothing measured again but the row it is in (a component in an unchanged row is not measured: its answer stands until it renders) |
| A row that is a component of its own changes its state | that row renders, measures and paints; the parent does not render (the row's size did not change) |
| A changed row of a `ProgressBar`, two `Text`s and two `Button`s, 3 levels of surfaces up to the screen | about 0.6 ms alone in a frame, about 0.4 ms each when many rows change in one frame (the row's layout, its writes and their parsing) |
| Such a row rendered again unchanged (a step of its coroutine that changed nothing) | about 35 µs |
| 50 such rows, each with its coroutine ticking at 10 Hz (500 steps a second), one row in twenty changing per tick | about 12-15% of a core (about 8% with the garbage collector off); every row changing on every tick: about 50-57% |
| The same 50 coroutines sleeping with `fiber\sleep()` and drawing nothing | about 6.5% of a core: the runtime's part of the above (about 2.5% with the garbage collector off; the rest is phasync's idle garbage collection, which runs whenever the loop idles, up to 5% of the time) |
| A key to the frame that shows it, with those 50 rows ticking | median 0.2 ms, at most about 2 ms |
| Composing a frame of 160x50, a tree of surfaces ten levels deep with three leaves beside each level (`tests/Benchmark/BlitCostTest.php`) | about 0.25 ms when a cell changed at the bottom, 1 ms when every surface changed, 0.04 ms when a row of the root's own cells changed; a frame that looks as the last one costs no byte on the terminal |
| Composing a frame of 200 windows (50 rows of four) in which one changed | about 0.1 ms (only the row it is on is made again), about 1.2 ms when all of them changed |
| A program without classes of 50 `Row([Text, ProgressBar, Button, Button])` with `onKey:` and `onFocus:`, the bars moved by 50 `Tui::go()` coroutines at 10 Hz: `press('tab')` and `settle()` | median about 2.8 ms with the CPU clocked down between keys (about 1.1 ms back to back, of which composing is 0.1 ms): the closure does not run for a Tab (the rows the focus left and entered are laid out again from its last tree, and only when their look changes with the focus); a Tab that coincides with a tick of the bars pays that frame too (below) |
| The frame of that program in which all fifty bars move (`tools/monitor-bench.php bars`) | about 29 ms with the CPU clocked down between frames, 11.5 ms back to back (0.0.3-beta7: 37 and 15.5 ms): the closure builds its 250 elements again (about 3 ms), the bars render and write (3 ms), the rest of the frame's component work (3 ms), composing (1.3 ms), the device's diff and, headless, the fake terminal's parsing of it (1.5 ms) |
| A Tab in rows of a `Text` and two `Button`s in a `ScrollView`, 160x50, `press('tab')` and `settle()` | about 3 ms with 2 rows, 5.5 ms with 200, with the CPU clocked down between keys (1.2 and 2.1 ms back to back): the Tab walk looks from the focused stop on, a focus request settles only the hosts above it, and only the two rows the focus moved between are composed again |
| Switching to a tab whose content fills the pane (a `Markdown`), 120x40 | about 8.7 ms with the CPU clocked down (0.0.3-beta7: 14.3 ms), about what switching to a `Text` tab costs: the content is laid out once, at its size, and only the cells that look different are written |

A frame renders everything asked for since the last one (see "Frames" in
[components.md](components.md#state-rendering-by-itself)), and `sleep()` ends on ticks of the
frame clock, so fifty rows that tick at 10 Hz are at most ten frames a tick, not fifty.

**Measuring.** Percentages of a core for a light load that runs in short bursts overstate the
work on a machine whose CPU clocks down when idle (the `schedutil` and `powersave`
governors): on the machine these numbers come from, the same frames cost 2.5 times the CPU
time with 40 ms of idle between them as back to back. Compare the cost of a frame (as the
first rows do) rather than a percentage when you tune a tree. In the repository,
`php tools/monitor-bench.php [some|all|bare]` runs the monitor of the table, and
`php tools/monitor-bench.php keys` the program without classes and its keys (`press()` and
`settle()`, as a test or a program measures it); `bars`, `scroll [rows]` and `tabs` the other
rows.

**Budget.** A few hundred elements of which a few change make a frame of one or two
milliseconds. What costs is what changed: rendering and laying out the components that
changed, and composing the rows they are on, whatever the size and depth of the tree.

**When a tree is big:**

- **A component per row, state in the row.** A change renders the row, not the list; a
  coroutine that updates a row belongs in the row (a `go()` of the parent renders the parent and
  all its rows on each step, see "State: rendering by itself" in
  [components.md](components.md#state-rendering-by-itself)).
- **Keep what does not change the same.** A `render()` that returns the same tree as before
  costs almost nothing; one that changes a counter in one `Text` repaints that `Text`.
- **Fewer coroutines that wake.** Each wake of a coroutine is a step that renders its component
  (about 35 µs when nothing changed) and that the runtime pays for apart from the render (the
  loop, the fiber switch, its idle collection). Wake at the rate the screen needs (2-4 Hz for a
  progress display), or let one coroutine update the rows' states.