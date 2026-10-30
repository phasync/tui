<?php

namespace phasync\Tui;

use phasync\Tui\Surface\AnsiParser;
use phasync\Tui\Surface\Emulator;
use phasync\Tui\Surface\Line;
use phasync\Tui\Surface\SurfaceSapi;

use function fiber\go;

/**
 * A terminal emulator in memory: a grid of cells with a cursor, scrollback and an input channel.
 *
 * Everything that shows on screen is a Surface. A program puts content on it the only way a
 * terminal allows, by writing bytes: text, control characters and ANSI/VT escape sequences, as
 * xterm (TERM=xterm-256color) understands them. A shell in a `Pty`, a PHP closure started with
 * `run()` and the library's own widgets are all producers writing into Surfaces.
 *
 *     $surface = new Surface(40, 10);
 *     $surface->write("\e[1mHello\e[0m world\r\n");
 *     echo $surface->line(0);               // "Hello world"
 *     echo $surface->cell(0, 0)->style;     // "bold"
 *
 * Coordinates are 0-based: x is the column, y the row. Row 0 is the top of the screen; negative
 * rows are the scrollback, -1 being the line that most recently scrolled off the top.
 *
 * Use `Ansi` to build the escape sequences, `cell()`, `line()`, `runs()`, `text()` and `dump()`
 * to read the result, `damagedSince()` to find what changed, and `input` for what the producer
 * reads.
 */
final class Surface implements \Stringable
{
    /** The bytes the producer of this surface reads: keys, mouse reports, pastes, replies. */
    public readonly Input $input;

    private Emulator $vt;
    private AnsiParser $parser;
    private string $utf8Tail = '';
    private ?int $damageTaken = null;
    /** @var array<string, array<int, \Closure>> */
    private array $listeners = [];

    /**
     * @param int $cols       width in columns
     * @param int $rows       height in rows
     * @param int $scrollback how many lines that scroll off the top are kept (0 for none)
     */
    public function __construct(int $cols = 80, int $rows = 24, int $scrollback = 5000)
    {
        $this->input = new Input();
        $this->vt = new Emulator($cols, $rows, $scrollback, function (string $type, mixed $data): void {
            if ($type === 'reply') {
                $this->input->write($data);
            } else {
                $this->emit($type, $data);
            }
        });
        $this->parser = new AnsiParser();
    }

    /**
     * Feed bytes to the terminal, exactly as a program's output reaches a terminal.
     *
     * Text is printed at the cursor with autowrap; control characters and escape sequences
     * move the cursor, change colors, erase, scroll and switch modes. An escape sequence or UTF-8
     * character split across two calls is completed by the next call. Malformed UTF-8 shows as
     * U+FFFD, unknown sequences are consumed without effect.
     */
    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $vt = $this->vt;
        $vt->version++;
        $data = $this->utf8Tail . $bytes;
        $this->utf8Tail = '';
        $len = \strlen($data);
        for ($i = 1; $i <= 3 && $i <= $len; $i++) {
            $b = \ord($data[$len - $i]);
            if ($b < 0x80) {
                break;
            }
            if ($b >= 0xC0) {
                if ($b < 0xC2 || $b > 0xF4) {
                    break;   // never a lead byte: scrubbed below
                }
                $need = $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2);
                if ($need > $i) {
                    $this->utf8Tail = substr($data, -$i);
                    $data = substr($data, 0, -$i);
                }
                break;
            }
        }
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = self::scrub($data);
        }
        $source = $vt->pending . $data;
        $vt->pending = '';
        if ($source !== '') {
            $this->parser->parse($source, ['vt' => $vt]);
        }
        $this->emit('change', null);
    }

    /** Replace each malformed byte with U+FFFD. */
    private static function scrub(string $data): string
    {
        return preg_replace_callback(
            '/[\x00-\x7F]+|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
            static fn(array $m): string => isset($m[1]) ? "\u{FFFD}" : $m[0],
            $data,
        );
    }

    /** Width in columns. */
    public function cols(): int
    {
        return $this->vt->cols;
    }

    /** Height in rows. */
    public function rows(): int
    {
        return $this->vt->rows;
    }

    /**
     * Change the size. Text is not reflowed: what fits is kept, rows that no longer fit go to
     * scrollback (blank rows below the cursor are dropped first), the cursor stays inside, and the
     * scroll region is reset. A Pty attached to this surface follows the new size.
     */
    public function resize(int $cols, int $rows): void
    {
        if ($cols === $this->vt->cols && $rows === $this->vt->rows) {
            return;
        }
        $this->vt->version++;
        $this->vt->resize($cols, $rows);
        $this->emit('resize', [$this->vt->cols, $this->vt->rows]);
        $this->emit('change', null);
    }

    /**
     * The cell at column $x of row $y; negative rows read the scrollback.
     *
     * @throws \OutOfRangeException for coordinates outside the surface and its scrollback
     */
    public function cell(int $x, int $y): Cell
    {
        $line = $this->row($y);
        if ($x < 0 || $x >= $this->vt->cols) {
            throw new \OutOfRangeException("Column $x is outside 0.." . ($this->vt->cols - 1));
        }
        $char = $line->chars[$x] ?? ' ';
        $style = $line->styles[$x] ?? Style::plain();
        if ($char === '') {
            return new Cell('', 0, $style);
        }
        $wide = isset($line->chars[$x + 1]) && $line->chars[$x + 1] === '';
        return new Cell($char, $wide ? 2 : 1, $style);
    }

    /** The text of row $y without trailing blanks; negative rows read the scrollback. */
    public function line(int $y): string
    {
        return rtrim(implode('', $this->row($y)->chars), ' ');
    }

    /**
     * Row $y as runs of cells that share a style, covering the full width (trailing blanks
     * included). Use it to render a row elsewhere or to check colors in tests.
     *
     * @return list<Run>
     */
    public function runs(int $y): array
    {
        $line = $this->row($y);
        $runs = [];
        $start = 0;
        $text = '';
        $style = null;
        $cols = $this->vt->cols;
        for ($x = 0; $x < $cols; $x++) {
            $s = $line->styles[$x] ?? Style::plain();
            if ($s !== $style) {
                if ($style !== null) {
                    $runs[] = new Run($start, $text, $x - $start, $style);
                }
                $style = $s;
                $start = $x;
                $text = '';
            }
            $text .= $line->chars[$x] ?? ' ';
        }
        if ($style !== null) {
            $runs[] = new Run($start, $text, $cols - $start, $style);
        }
        return $runs;
    }

    /** True if row $y continues on the next row because the text wrapped there. */
    public function isWrapped(int $y): bool
    {
        return $this->row($y)->wrapped;
    }

    /** The visible screen as text: every row without trailing blanks, joined with "\n". */
    public function text(): string
    {
        $out = [];
        for ($y = 0; $y < $this->vt->rows; $y++) {
            $out[] = $this->line($y);
        }
        return implode("\n", $out);
    }

    public function __toString(): string
    {
        return $this->text();
    }

    /**
     * The visible screen as text with styles marked up, for tests and debugging:
     * `[bold fg=1]error[/] in line 3`. A style is written as in `Style::__toString()`; a literal
     * `[` in the text is doubled. Trailing unstyled blanks are left out.
     */
    public function dump(): string
    {
        $out = [];
        for ($y = 0; $y < $this->vt->rows; $y++) {
            $runs = $this->runs($y);
            $line = '';
            $last = \count($runs) - 1;
            foreach ($runs as $i => $run) {
                $text = str_replace('[', '[[', $run->text);
                $style = (string) $run->style;
                if ($style === '') {
                    $line .= $i === $last ? rtrim($text, ' ') : $text;
                } else {
                    $line .= "[$style]$text" . '[/]';
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /** The cursor: position, visibility and shape. */
    public function cursor(): Cursor
    {
        $vt = $this->vt;
        return new Cursor($vt->x, $vt->y, $vt->modes[25] ?? false, $vt->cursorShape, $vt->cursorBlink);
    }

    /** The window title last set by the program (OSC 0 or 2). */
    public function title(): string
    {
        return $this->vt->title;
    }

    /** The number of lines currently held in scrollback (rows -1 down to -scrollbackSize()). */
    public function scrollbackSize(): int
    {
        return $this->vt->scrollbackEnd - $this->vt->scrollbackStart;
    }

    /**
     * How many lines have ever scrolled into the scrollback. When this grows by N, a line that was
     * row -k is now row -(k+N); a view that stays put in the scrollback adjusts by the difference.
     */
    public function linesScrolled(): int
    {
        return $this->vt->scrollbackEnd;
    }

    /** True while the program uses the alternate screen (full-screen programs like vim, less). */
    public function isAltScreen(): bool
    {
        return $this->vt->mainLines !== null;
    }

    /**
     * Whether DEC private mode $mode is set, such as 1 (application cursor keys), 25 (cursor
     * visible), 1000/1002/1003 (mouse tracking), 1006 (SGR mouse), 2004 (bracketed paste).
     */
    public function mode(int $mode): bool
    {
        return $this->vt->modes[$mode] ?? false;
    }

    /**
     * The kitty keyboard protocol flags the program enabled (`CSI > flags u`), 0 when it uses
     * legacy key encoding: 1 disambiguate escape codes, 2 report event types, 4 report alternate
     * keys, 8 report all keys as escape codes, 16 report associated text. `sendKey()` honours them.
     */
    public function keyboardFlags(): int
    {
        return $this->vt->keyboardFlags();
    }

    /** The xterm modifyOtherKeys level the program set (`CSI > 4 ; n m`): 0, 1 or 2. `sendKey()` honours it. */
    public function modifyOtherKeys(): int
    {
        return $this->vt->modifyOtherKeys;
    }

    /**
     * Set the colors reported to programs that ask for the terminal's default foreground and
     * background (OSC 10 and 11; many programs choose a light or dark theme from them). The
     * default is light gray on black. Pass truecolor values from `Color::rgb()`.
     */
    public function setDefaultColors(int $fg, int $bg): void
    {
        $this->vt->colors = [$fg, $bg];
    }

    /** Whether the program switched the keypad to application mode (DECKPAM, ESC =). */
    public function isKeypadApplication(): bool
    {
        return $this->vt->keypadApplication;
    }

    /**
     * A number that grows with every change. Remember it, and later `damagedSince()` tells which
     * rows changed after it. Any number of readers can track changes this way independently.
     */
    public function version(): int
    {
        return $this->vt->version;
    }

    /**
     * The rows (0-based, ascending) that changed after version $version.
     *
     * @return list<int>
     */
    public function damagedSince(int $version): array
    {
        $rows = [];
        foreach ($this->vt->rowVersion as $y => $v) {
            if ($v > $version) {
                $rows[] = $y;
            }
        }
        sort($rows);
        return $rows;
    }

    /**
     * The rows that changed since the previous call (all rows on the first call). The simple
     * form of `damagedSince()` for a single reader.
     *
     * @return list<int>
     */
    public function takeDamage(): array
    {
        $rows = $this->damagedSince($this->damageTaken ?? -1);
        $this->damageTaken = $this->vt->version;
        return $rows;
    }

    /**
     * Listen to an event of this surface; returns a closure that removes the listener.
     *
     * - `change`: after every `write()` and `resize()`
     * - `resize`: receives `[$cols, $rows]`
     * - `title`: receives the new title
     * - `bell`: the program rang the bell (BEL)
     * - `clipboard`: the program asked to copy text to the clipboard (OSC 52); receives the text
     *
     * @param \Closure(mixed $data): void $listener
     */
    public function on(string $event, \Closure $listener): \Closure
    {
        $id = spl_object_id($listener);
        $this->listeners[$event][$id] = $listener;
        return function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);
        };
    }

    private function emit(string $event, mixed $data): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }
    }

    /**
     * Run $code in a new coroutine as the producer of this surface; returns its awaitable (pass it
     * to `fiber\await()`). Must be called inside `fiber\run()`.
     *
     * $code receives the surface. With ext-phasync loaded, everything the coroutine prints with
     * `echo`, `print`, `printf` or `var_dump()` lands in this surface, including output of
     * coroutines it starts (they are awaited before it ends). Without ext-phasync output is not
     * captured: write with `$surface->write()`, which works in both cases. `STDOUT` is never
     * captured. Either way escape sequences render as in a terminal, so output made for one
     * (colors, progress bars from symfony/console, cursor movement) looks right.
     *
     * @param \Closure(Surface $surface): mixed $code
     */
    public function run(\Closure $code): object
    {
        return go(function () use ($code): mixed {
            if (self::canCaptureOutput()) {
                return SurfaceSapi::capture($this, $code);
            }
            return $code($this);
        });
    }

    /** True when ext-phasync is loaded, so `run()` captures `echo` output. */
    public static function canCaptureOutput(): bool
    {
        return \function_exists('phasync\ext\sapi');
    }

    /**
     * @internal The cells of row $y as parallel arrays of graphemes ('' for a continuation) and
     * styles, for renderers in this library (Viewport). Not part of the public API.
     *
     * @return array{list<string>, list<Style>}
     */
    public function rowData(int $y): array
    {
        $line = $this->row($y);
        return [$line->chars, $line->styles];
    }

    private function row(int $y): Line
    {
        $vt = $this->vt;
        if ($y >= 0) {
            if ($y >= $vt->rows) {
                throw new \OutOfRangeException("Row $y is outside 0.." . ($vt->rows - 1));
            }
            return $vt->lines[$y];
        }
        $line = $vt->scrollback[$vt->scrollbackEnd + $y] ?? null;
        if ($line === null) {
            throw new \OutOfRangeException("Row $y is beyond the scrollback (" . $this->scrollbackSize() . ' lines)');
        }
        return $line;
    }
}
