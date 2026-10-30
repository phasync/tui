# Surface: a terminal in memory

A `Surface` is a terminal emulator without a screen: a grid of cells (`cols` x `rows`) with a
cursor, scrollback, modes and an input channel. Everything that shows on screen in this library
is a Surface, and the only way to put anything on one is the way a terminal allows: writing
bytes (text, control characters, escape sequences) with `write()`. There is no cell-drawing
API; widgets, borders and the compositor write escape sequences too, so the emulator is tested
by everything the library draws.

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
  passed up; other OSC, DCS, APC, PM and SOS strings are consumed and ignored.
- Queries answered on the input channel: DSR 5 and 6 (cursor position), DA1, DA2, XTVERSION,
  DECRQM, kitty keyboard flags, OSC 10/11 colours.

Unknown sequences are consumed without corrupting the screen. A sequence or a UTF-8 character
split across two `write()` calls is completed by the next call. Not implemented, on purpose for
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
| `dump(): string` | The visible screen with styles marked up, for tests: `[bold fg=1]error[/] in line 3`; a literal `[` is doubled. |
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
| `hideCursor()`, `showCursor()` | DECTCEM |
| `saveCursor()`, `restoreCursor()` | DECSC, DECRC |
| `scrollRegion($top, $bottom)` | DECSTBM (inclusive rows) |
| `title($title)` | OSC 2 |
| `clipboard($text)` | OSC 52: put text on the clipboard (passed up to the real terminal) |
| `altScreen(bool $on = true)` | mode 1049 |

```php
$s->write(Ansi::moveTo(2, 1) . Ansi::styled('ok', Style::of(Color::GREEN, attrs: Style::BOLD)));
```

## Styles and colours

`Style` is an immutable, interned value (compare with `===`): `Style::of(int $fg = Color::DEFAULT,
int $bg = Color::DEFAULT, int $attrs = 0)`, `Style::plain()`, `has($attrs)`, `withFg($fg)`,
`withBg($bg)`, `with($attrs)`, `without($attrs)`, and `__toString()` (`"bold fg=1"`, `''` for
plain). Attributes are bit flags: `Style::BOLD`, `DIM`, `ITALIC`, `UNDERLINE`, `BLINK`,
`INVERSE`, `HIDDEN`, `STRIKE`.

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
