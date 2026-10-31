<?php

namespace phasync\Tui;

use phasync\Tui\Surface\AnsiParser;
use phasync\Tui\Surface\Emulator;
use phasync\Tui\Surface\Host;
use phasync\Tui\Surface\InputEncoder;
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
 *
 * A Surface can host other Surfaces (`host()`), each shown through a `Viewport`, to any depth:
 * the screen of a program is a root Surface hosting windows, and a terminal device (`Tty`, or
 * `FakeTerminal` for tests) attached to the root shows it on a real terminal. What a host shows
 * (what its `cell()`, `line()`, `text()` and the rest read) is its own cells with its windows
 * copied over them, in z-order and clipped, to any depth (`copyArea()`/`drawArea()` are the
 * primitives); it passes input events (`dispatch()`) down to the focused one.
 */
final class Surface implements \Stringable
{
    /** How long a hosted surface may hold back what it shows with synchronized output (mode 2026). */
    private const SYNC_TIMEOUT = 0.5;

    /** The bytes the producer of this surface reads: keys, mouse reports, pastes, replies. */
    public readonly Input $input;

    private Emulator $vt;
    private AnsiParser $parser;
    private string $utf8Tail = '';
    private ?int $damageTaken = null;
    private ?Host $host = null;
    /** @var list<Viewport> the viewports this surface shows through, the one of its parent last */
    private array $views = [];
    /** The number of the latest `focus()` of this surface (0: never focused). */
    private int $focusStamp = 0;
    /** The number of the latest `takeFocus()` of this surface. */
    private int $keepStamp = 0;
    private bool $focusable = true;
    /** @var list<Surface> for a root: the focus path its surfaces were last told about */
    private array $announced = [];
    /** It was shown in a window and is in none now (a hidden tab's pane): see detached(). */
    private bool $detached = false;
    /** @var list<\Closure> what flush() runs first (see onFlush()) */
    private static array $flushers = [];
    /** @var \WeakMap<Surface, true>|null the surfaces whose producer asked for mouse reports */
    private static ?\WeakMap $mouseWanted = null;
    /** Numbers focus requests: the latest one wins. */
    private static int $focusClock = 0;
    /** Changes to the tree in progress; the focus settles when the outermost one ends. */
    private static int $treeChanges = 0;
    /** @var array<int, Surface> surfaces where something the focus depends on changed: their trees settle */
    private static array $unsettled = [];
    /** Counts the changes of focus requests and focus paths, for those who keep an answer about the focus. */
    private static int $focusVersion = 0;
    private static int $hostingVersion = 0;
    /** @var array<int, Surface> the surfaces focus() or takeFocus() was called on since the focus last settled */
    private static array $requested = [];
    /** Something other than a focus request changed the focus of a tree since it last settled. */
    private static bool $changed = false;
    /** @var \WeakMap<Surface, true>|null surfaces with `area` listeners */
    private static ?\WeakMap $areaWatchers = null;
    /** A window moved, was resized, scrolled, hosted or unhosted since the areas were checked. */
    private static bool $geometryChanged = false;
    private static bool $checkingAreas = false;
    /** The area last announced to `area` listeners. */
    private ?Rect $areaSeen = null;
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
            } elseif ($type === 'mouse') {
                // (a terminal device asks for the mouse reports some surface of its tree wants)
                self::$mouseWanted ??= new \WeakMap();
                if ($this->wantsMouse()) {
                    self::$mouseWanted[$this] = true;
                } else {
                    unset(self::$mouseWanted[$this]);
                }
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
        if ($this->host !== null) {
            $this->host->dirty = true;
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
        if ($this->host !== null) {
            $this->host->dirty = true;
            $this->changeTree(fn() => $this->host->arrange());
        }
        $this->emit('resize', new Resize($this->vt->cols, $this->vt->rows));
        $this->emit('change', null);
        self::geometryChanged();
    }

    /**
     * The cell at column $x of row $y; negative rows read the scrollback.
     *
     * @throws \OutOfRangeException for coordinates outside the surface and its scrollback
     */
    public function cell(int $x, int $y): Cell
    {
        $this->compose();
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
        $this->compose();
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
        $this->compose();
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

    /**
     * Row $y as runs of cells that look the same: as `runs()`, a blank in the style of the text
     * before it when it looks the same in that, else in that of the text after it, else in the
     * style it looks like (`Style::blank()`); blanks to the end of the row that look like empty
     * cells are plain. Two rows that look the same give the same runs.
     *
     * @return list<Run>
     */
    private function looks(int $y): array
    {
        $line = $this->row($y);
        $chars = $line->chars;
        $n = \count($chars);
        // the next cell that is not a blank, from each cell on
        $next = [];
        for ($x = $n - 1, $at = null; $x >= 0; $x--) {
            $next[$x] = $at;
            if ($chars[$x] !== ' ') {
                $at = $x;
            }
        }
        $runs = [];
        $start = 0;
        $text = '';
        $style = null;
        $plain = Style::plain();
        foreach ($chars as $x => $ch) {
            $s = $line->styles[$x];
            if ($ch === ' ') {
                $look = $s->blank();
                if ($next[$x] === null && $look === $plain) {
                    $s = $plain;        // (blank to the end of the row: nothing there)
                } elseif ($style !== null && $style->blank() === $look) {
                    $s = $style;
                } elseif (($j = $next[$x]) !== null && $line->styles[$j]->blank() === $look) {
                    $s = $line->styles[$j];
                } else {
                    $s = $look;
                }
            }
            if ($s !== $style) {
                if ($style !== null) {
                    $runs[] = new Run($start, $text, $x - $start, $style);
                }
                $style = $s;
                $start = $x;
                $text = '';
            }
            $text .= $ch;
        }
        if ($style !== null) {
            $runs[] = new Run($start, $text, $n - $start, $style);
        }
        return $runs;
    }

    /** True if row $y continues on the next row because the text wrapped there. */
    public function isWrapped(int $y): bool
    {
        return $this->row($y, false)->wrapped;
    }

    /** The visible screen as text: every row without trailing blanks, joined with "\n". */
    public function text(): string
    {
        $this->compose();
        $out = [];
        for ($y = 0; $y < $this->vt->rows; $y++) {
            $out[] = $this->line($y);
        }
        return implode("\n", $out);
    }

    /** The same as `text()`. */
    public function __toString(): string
    {
        return $this->text();
    }

    /**
     * The visible screen as text with styles marked up, for tests and debugging:
     * `[bold fg=1]error[/] in line 3`. A style is written as in `Style::__toString()`; a literal
     * `[` in the text is doubled. Trailing unstyled blanks are left out.
     *
     * With $looks, it shows what a person sees: a blank (a space has a background, but no
     * foreground, bold or italic: `Style::blank()`) is shown in the style of the text next to
     * it when it looks the same in that, else as it looks. Two screens that look the same give
     * the same dump, however they were drawn. `FakeTerminal::dump()` shows this.
     */
    public function dump(bool $looks = false): string
    {
        $this->compose();
        $out = [];
        for ($y = 0; $y < $this->vt->rows; $y++) {
            $runs = $looks ? $this->looks($y) : $this->runs($y);
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

    /**
     * The pen: the style the next printed character gets (colours and attributes from the last
     * SGR sequences, and the hyperlink it is in). For a producer that wants to know whether it
     * has to set its style again.
     */
    public function pen(): Style
    {
        return $this->vt->pen;
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
     * keys, 8 report all keys as escape codes, 16 report associated text. `dispatch()` honours them.
     */
    public function keyboardFlags(): int
    {
        return $this->vt->keyboardFlags();
    }

    /** The xterm modifyOtherKeys level the program set (`CSI > 4 ; n m`): 0, 1 or 2. `dispatch()` honours it. */
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
        $this->compose();
        return $this->vt->version;
    }

    /**
     * The rows (0-based, ascending) that changed after version $version.
     *
     * @return list<int>
     */
    public function damagedSince(int $version): array
    {
        $this->compose();
        $rows = [];
        foreach ($this->host?->frameVersions() ?? $this->vt->rowVersion as $y => $v) {
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
     * - `change`: after every `write()`, `drawArea()` and `resize()`, and when what it shows
     *   changed otherwise (a hosted surface or a window; the cells catch up when they are read)
     * - `resize`: receives a `Resize`
     * - `title`: receives the new title
     * - `bell`: the program rang the bell (BEL)
     * - `clipboard`: the program asked to copy text to the clipboard (OSC 52); receives the text
     * - `close`: `close()` was called: the session on this surface is over
     * - `area`: where this surface shows on its root (`area()`) changed: its window, or a window
     *   it is in, was moved, resized or scrolled, or it was hosted or unhosted; receives the new
     *   `Rect`. Told once a change to the tree is complete (a layout's whole arrange is one).
     *
     * Input listeners see the events given to `dispatch()` before they go further, and stop them
     * by returning true; this is how a program reacts to keys on any surface (a shortcut on the
     * root, a key a widget handles itself):
     *
     * - `key`: receives a `Key`
     * - `mouse`: receives a `Mouse`, in this surface's coordinates
     * - `paste`: receives a `Paste`
     * - `focus`: receives a `Focus` when this surface gains or loses the focus
     *
     * @param \Closure(mixed $data): mixed $listener
     */
    public function on(string $event, \Closure $listener): \Closure
    {
        $id = spl_object_id($listener);
        $this->listeners[$event][$id] = $listener;
        if ($event === 'area') {
            self::$areaWatchers ??= new \WeakMap();
            self::$areaWatchers[$this] = true;
            $this->areaSeen ??= $this->area();
        }
        return function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);
            if ($event === 'area' && ($this->listeners['area'] ?? []) === []) {
                unset(self::$areaWatchers[$this]);
                $this->areaSeen = null;
            }
        };
    }

    /** Call the listeners of $event; true as soon as one returns true. */
    private function emit(string $event, mixed $data): bool
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            if ($listener($data) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deliver an input event: a `Key`, `Mouse`, `Paste` or `Focus`, as a terminal device does
     * with what the user types. The event goes to this surface's input listeners (`on('key')`
     * and friends) first; one returning true takes it. Then, if this surface hosts others, it
     * goes to the focused one (a mouse event to the one under the pointer, in its coordinates),
     * and so on down. Where it ends, the surface encodes it to the bytes its producer expects,
     * as xterm would for the modes the program set (application cursor keys, kitty keyboard
     * flags, modifyOtherKeys, mouse mode and encoding, bracketed paste, focus reporting), and puts
     * them on `input`.
     *
     * A host takes the mouse events a hosted program did not ask for: the wheel scrolls the
     * window, a drag selects text and copies it to the clipboard (OSC 52, passed up to the
     * terminal). Returns false only for a mouse event that nothing wanted.
     */
    public function dispatch(Event $event): bool
    {
        $type = match (true) {
            $event instanceof Key => 'key',
            $event instanceof Mouse => 'mouse',
            $event instanceof Paste => 'paste',
            $event instanceof Focus => 'focus',
            default => throw new \InvalidArgumentException('Unknown event ' . $event::class),
        };
        if ($this->emit($type, $event)) {
            return true;
        }
        if ($this->host !== null && $this->host->route($event)) {
            return true;
        }
        $bytes = match ($type) {
            'key' => InputEncoder::key($event, $this),
            'paste' => InputEncoder::paste($event->text, $this),
            'focus' => $this->mode(1004) ? ($event->focused ? "\e[I" : "\e[O") : '',
            // (a drag held outside the window is reported at its edge, as a terminal does)
            'mouse' => InputEncoder::mouse($event->at(max(0, min($this->cols() - 1, $event->x)), max(0, min($this->rows() - 1, $event->y))), $this),
        };
        $this->input->write($bytes);
        return $type !== 'mouse' || $bytes !== '';
    }

    /**
     * Hang up: tell everything attached to this surface that the session on it is over, as a
     * terminal window closing does. A terminal device attached to it detaches, a component on it
     * is disposed (which ends the programs of its `Terminal`s); listeners of `close` are called.
     * The cells stay as they are. Detaching a terminal device closes its root.
     */
    public function close(): void
    {
        $this->emit('close', null);
    }

    /** True if the program asked for mouse reports (any of the modes 9, 1000, 1002, 1003). */
    public function wantsMouse(): bool
    {
        return $this->mode(1000) || $this->mode(1002) || $this->mode(1003) || $this->mode(9);
    }

    /**
     * @internal The surfaces below $root (not $root itself) that asked for mouse reports
     * (`wantsMouse()`), found from the few that did rather than by walking the tree.
     *
     * @return list<Surface>
     */
    public static function mouseWantedBelow(Surface $root): array
    {
        $out = [];
        foreach (self::$mouseWanted ?? [] as $surface => $_) {
            if ($surface !== $root && $surface->root() === $root) {
                $out[] = $surface;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Hosting

    /**
     * Show $child on this surface and return its `Viewport`. Without geometry the child shows at
     * the top-left corner at its own size; place it with $x/$y/$width/$height here or later on
     * the viewport, or let a layout (`setLayout()`) place it. A surface is hosted at most once
     * per host: hosting it again returns the same viewport (moved and resized if you pass
     * geometry). Of its windows, the host focuses the one focused most recently (`focus()`, also
     * when called before it was shown), or the first one when none was ever focused.
     *
     * The child's changes show on this surface (and on its host, up to the root): what this
     * surface shows is its own cells with the windows' cells copied over them, made again when
     * it is read after a change (see `compose()`). What this surface's own producer writes stays
     * in its own cells, under the windows; it shows where no window covers it. The child's title
     * (while focused), bell and clipboard requests are passed up to this surface's listeners.
     *
     * The surfaces form a tree: the child's `parent()` is now this surface. A surface shown on
     * several hosts at once (a mirror) belongs to the one that hosted it last.
     *
     * @throws \InvalidArgumentException when $child is this surface or hosts it
     */
    public function host(Surface $child, ?int $x = null, ?int $y = null, ?int $width = null, ?int $height = null): Viewport
    {
        return $this->changeTree(fn() => $this->hosting()->host($child, $x, $y, $width, $height));
    }

    /** Stop showing $child; what this surface has under it shows again. Nothing happens if it is not hosted here. */
    public function unhost(Surface $child): void
    {
        if ($this->host !== null) {
            $this->changeTree(fn() => $this->host->unhost($child));
        }
    }

    /** Bring the window of the hosted surface $child to the top, over all others. */
    public function raise(Surface $child): void
    {
        $this->host?->raise($child);
    }

    /**
     * The viewport $child shows through on this surface, or null if it is not hosted here.
     * Without an argument: the viewport this surface shows through on its host (`parent()`), or
     * null when it is not hosted.
     */
    public function viewport(?Surface $child = null): ?Viewport
    {
        if ($child === null) {
            return $this->views === [] ? null : $this->views[\count($this->views) - 1];
        }
        return $this->host?->find($child);
    }

    /**
     * The viewports of the hosted surfaces, bottom first (later ones cover earlier ones).
     *
     * @return list<Viewport>
     */
    public function viewports(): array
    {
        return $this->host?->viewports ?? [];
    }

    /** The surface that hosts this one, or null for a root (or a surface not shown anywhere). */
    public function parent(): ?Surface
    {
        return $this->viewport()?->host();
    }

    /** The root of the tree this surface is in: the last `parent()` up, or this surface itself. */
    public function root(): Surface
    {
        $s = $this;
        while (($p = $s->parent()) !== null) {
            $s = $p;
        }
        return $s;
    }

    /**
     * Where this surface shows on its root: the rectangle of root cells its visible part covers
     * (its window, clipped by every window it is in on the way up; empty when it is scrolled or
     * moved out of view). For a root, the whole surface. Directional focus (`focusToward()`)
     * uses it; use it to place a pop-up next to a window or to map root coordinates.
     */
    public function area(): Rect
    {
        // (as Rect::intersect() at each level, in plain numbers: it runs for every watched surface when the geometry changes)
        [$x, $y, $w, $h] = [0, 0, $this->cols(), $this->rows()];
        $s = $this;
        while (($v = $s->viewport()) !== null) {
            $b = $v->hasBorder() ? 1 : 0;
            [$ix, $iy, $iw, $ih] = [$v->x() + $b, $v->y() + $b, $v->innerWidth(), $v->innerHeight()];
            $s = $v->host();
            $x0 = $ix + $x - $v->left();
            $y0 = $iy + $y - $v->top();
            $x = max($x0, $ix);
            $y = max($y0, $iy);
            $w = max(0, min($x0 + $w, $ix + $iw) - $x);
            $h = max(0, min($y0 + $h, $iy + $ih) - $y);
            $r = min($x + $w, $s->cols());
            $bottom = min($y + $h, $s->rows());
            $x = max($x, 0);
            $y = max($y, 0);
            $w = max(0, $r - $x);
            $h = max(0, $bottom - $y);
        }
        return new Rect($x, $y, $w, $h);
    }

    /**
     * Let $layout place the hosted surfaces (see `Split` and `Stack`), now and whenever this
     * surface is resized. The layout owns the windows: surfaces it does not place are unhosted.
     * Pass null to keep the windows where they are and stop arranging them.
     */
    public function setLayout(?Layout $layout): void
    {
        $this->hosting()->layout = $layout;
        $this->changeTree(fn() => $this->host->arrange());
    }

    /**
     * @internal Set $layout without arranging it now: it is arranged at the next resize or
     * `setLayout()`. For a component whose parent is about to give it another size (its tree's
     * windows are placed once, at that size, not first at the old one).
     */
    public function deferLayout(Layout $layout): void
    {
        $this->hosting()->layout = $layout;
    }

    /** The layout set with `setLayout()`, or null. */
    public function layout(): ?Layout
    {
        return $this->host?->layout;
    }

    /**
     * For layouts that nest layouts (as `Split` and `Stack` do): the surface that shows $layout,
     * the nested layout numbered $index (0, 1, ... in the order of the arranging layout's items)
     * on this surface. It is the same surface for the same number on every arrange, so a layout
     * built anew keeps its windows and focus; `$layout` is set on it. Host it like any surface.
     * Surfaces of nested layouts the arrange did not host are given up afterwards.
     */
    public function nestedSurface(int $index, Layout $layout): Surface
    {
        $surface = $this->hosting()->nested($index);
        if ($surface->layout() !== $layout) {
            $surface->setLayout($layout);
        }
        return $surface;
    }

    /**
     * @internal The nested layout this surface was made for (`nestedSurface()`) is gone: its
     * windows are unhosted, down through its own nested layouts.
     */
    public function giveUp(): void
    {
        if ($this->host !== null) {
            $this->changeTree(fn() => $this->host->giveUp());
        }
    }

    // ------------------------------------------------------------------------------------------
    // Focus

    /**
     * Give the focus to this surface: every host from the root down to it focuses the way to it,
     * so keys and pastes come here (or, when this surface hosts windows, go on to the one of them
     * it focuses). The surfaces losing the focus and those gaining it receive a `Focus` event (a
     * program that enabled focus reporting reads `ESC [O` / `ESC [I`). It takes effect at once,
     * for the very next key.
     *
     * The focus belongs to the surface, not to where it is: the surface focused last has it,
     * wherever it is shown and however the windows are re-arranged. Called on a surface that is
     * not shown yet (a window a layout is about to place), it takes effect when it is hosted; a
     * surface moved to another host or into a new nested layout keeps it. When the focused
     * surface is no longer shown, the focus goes back to the one focused before it that still is,
     * else to the first window.
     */
    public function focus(): void
    {
        $this->focusStamp = ++self::$focusClock;
        self::$requested[spl_object_id($this)] = $this;
        self::focusMayMove($this);
    }

    /**
     * Focus this surface (as `focus()`) and keep the keys here: its own windows lose the focus,
     * so keys and pastes go to this surface's producer and input listeners, until one of them is
     * focused again. For a program with a window over it (a pop-up on a pane) when the program,
     * not the pop-up, should get the keys.
     */
    public function takeFocus(): void
    {
        $this->focusStamp = $this->keepStamp = ++self::$focusClock;
        self::$requested[spl_object_id($this)] = $this;
        self::focusMayMove($this);
    }

    /**
     * @internal The latest focus request in this surface's subtree, its own included (0: none):
     * what decides whether its host's keys go its way. Changes nothing.
     */
    public function latestFocus(): int
    {
        return max($this->focusStamp, $this->latestFocusBelow());
    }

    /**
     * @internal The latest focus request that decides where this surface passes keys on: that of
     * the window subtree it focuses, or its own `takeFocus()` (0: none). Changes nothing.
     */
    public function latestFocusBelow(): int
    {
        $rank = $this->keepStamp;
        foreach ($this->host?->viewports ?? [] as $v) {
            $rank = max($rank, $v->source->latestFocus());
        }
        return $rank;
    }

    /**
     * @internal Run $change to the tree (hosting, unhosting, arranging); when the outermost
     * change ends, the focus of the tree settles: every host focuses the window whose subtree was
     * focused last, and the surfaces that left or joined the focus path are told.
     */
    public function changeTree(\Closure $change): mixed
    {
        self::$treeChanges++;
        try {
            return $change();
        } finally {
            if (--self::$treeChanges === 0) {
                self::settle();
                self::checkAreas();
            }
        }
    }

    /**
     * @internal Something the focus of $surface's tree depends on changed (a focus request, a
     * window hosted, unhosted or raised, a surface's focusability): the tree settles when the
     * change to it is complete. Moving and resizing windows, which happens on every layout,
     * changes nothing for the focus, so a re-render that keeps its windows settles nothing.
     */
    public static function focusMayMove(Surface $surface): void
    {
        self::$focusVersion++;
        if (!isset(self::$requested[spl_object_id($surface)])) {
            self::$changed = true;      // (not a focus request: the whole tree settles, see settle())
        }
        self::$unsettled[spl_object_id($surface)] = $surface;
        if (self::$treeChanges === 0) {
            self::settle();
        }
    }

    /**
     * @internal A number that changes whenever a focus request is made or a focus path may have
     * changed: an answer about the focus computed at the same number is still right.
     */
    public static function focusVersion(): int
    {
        return self::$focusVersion;
    }

    /**
     * Settle the focus of every tree something changed in (each root once). When the only
     * changes are focus requests, only the hosts above the surfaces asked for change their focus
     * (the latest request is above every other, so the way to it is the focus path, and nothing
     * else changed): a focus request costs the depth of the tree, not its size. Anything else
     * (a window hosted, unhosted or raised, a surface's focusability) settles the whole tree.
     */
    private static function settle(): void
    {
        while (self::$unsettled !== []) {
            $roots = [];
            foreach (self::$unsettled as $surface) {
                $root = $surface->root();
                $roots[spl_object_id($root)] = $root;
            }
            $requested = self::$changed ? [] : self::$requested;
            self::$unsettled = self::$requested = [];
            self::$changed = false;
            usort($requested, fn(Surface $a, Surface $b) => $a->focusStamp <=> $b->focusStamp);
            foreach ($requested as $surface) {
                // (in the order asked: a later request takes the hosts it shares with an earlier one)
                if ($surface->keepStamp === $surface->focusStamp) {
                    $surface->host?->setFocus(null);
                }
                for ($s = $surface; ($v = $s->viewport()) !== null; $s = $v->host()) {
                    $v->host()->host->setFocus($v);
                }
            }
            foreach ($roots as $root) {
                $root->settleFocus($requested === []);
            }
        }
    }

    /**
     * @internal The geometry of a window changed (moved, resized, scrolled, hosted, unhosted, or
     * a surface resized): the `area` listeners are told once the change to the tree is complete.
     */
    public static function geometryChanged(): void
    {
        self::$geometryChanged = true;
        if (self::$treeChanges === 0) {
            self::checkAreas();
        }
    }

    /** Tell the `area` listeners whose surface's area differs from the one they were told last. */
    private static function checkAreas(): void
    {
        if (self::$checkingAreas || !self::$geometryChanged || self::$areaWatchers === null) {
            return;
        }
        self::$checkingAreas = true;
        try {
            // a listener may change the tree again: check until it is quiet
            while (self::$geometryChanged) {
                self::$geometryChanged = false;
                $watched = [];
                foreach (self::$areaWatchers as $surface => $_) {
                    $watched[] = $surface;
                }
                foreach ($watched as $surface) {
                    $area = $surface->area();
                    if ($surface->areaSeen != $area) {
                        $surface->areaSeen = $area;
                        $surface->emit('area', $area);
                    }
                }
            }
        } finally {
            self::$checkingAreas = false;
        }
    }

    /** Settle the focus of the tree below this root (with $rank, else it is settled), and announce how the focus path changed. */
    private function settleFocus(bool $rank = true): void
    {
        self::$focusVersion++;
        if ($rank) {
            $this->rankFocus();
        }
        $path = [$this];
        for ($s = $this; ($next = $s->focused()) !== null; $s = $next) {
            $path[] = $next;
        }
        if ($this->detached) {
            return;     // (taken out of its tree: nobody is told of a focus it has in no tree; joinTree() tells when it is shown)
        }
        $old = $this->announced === [] ? [$this] : $this->announced;
        $this->announced = $path;
        // a surface on both paths (one moved to a new host) is not told anything
        self::announce(array_values(array_filter($old, fn(Surface $s) => !\in_array($s, $path, true))), false);
        self::announce(array_values(array_filter($path, fn(Surface $s) => !\in_array($s, $old, true))), true);
    }

    /**
     * Point every host below this surface at the window whose subtree holds the latest focus
     * request (staying where it is on a tie, the first window when none was ever focused; none
     * when the host took the focus itself later). Returns the latest focus request in the subtree.
     */
    private function rankFocus(): int
    {
        $rank = $this->focusStamp;
        $host = $this->host;
        if ($host === null) {
            return $rank;
        }
        $best = null;
        $bestRank = -1;
        $currentRank = -1;
        foreach ($host->viewports as $v) {
            $r = $v->source->rankFocus();
            if ($r === 0 && !$v->source->focusable) {
                $r = -1;    // never focused, and does not take the focus by itself
            }
            if ($r > $bestRank) {
                $best = $v;
                $bestRank = $r;
            }
            if ($v === $host->focus) {
                $currentRank = $r;
            }
        }
        if ($host->focus !== null && $currentRank === $bestRank) {
            $best = $host->focus;
        }
        $host->setFocus($this->keepStamp > $bestRank ? null : $best);
        return max($rank, $bestRank);
    }

    /**
     * Tell $path (surfaces that left or joined a focus path, top first) that they lost or gained
     * the focus: each one's listeners, top down (one returning true stops it), and the program of
     * one that keeps the focus itself (the end of a path) if it asked for focus reports.
     *
     * @param list<Surface> $path
     */
    private static function announce(array $path, bool $focused): void
    {
        $event = new Focus($focused);
        foreach ($path as $s) {
            if ($s->emit('focus', $event)) {
                return;
            }
            if ($s->focused() === null && $s->mode(1004)) {
                $s->input->write($focused ? "\e[I" : "\e[O");
            }
        }
    }

    /**
     * @internal A root is being hosted: it is no longer the top of a focus path of its own, so
     * the surfaces it told they have the focus are told they lost it (they are told again if
     * they are on the path of the tree it joins).
     */
    public function joinTree(): void
    {
        if (\count($this->announced) > 1) {
            self::announce(\array_slice($this->announced, 1), false);
        }
        $this->announced = [];
    }

    /**
     * Whether this surface takes the focus by itself: a host gives its keys to the first window
     * that does when none was focused, and `focusNext()`/`focusToward()` go only to those (and
     * not below one that does not). Turn it off for what only shows something (a label, a
     * status line), so keys are not lost on it. `focus()` still focuses it when asked to.
     * Surfaces take the focus by default; a host whose windows all refuse it keeps the keys.
     */
    public function setFocusable(bool $focusable): void
    {
        if ($focusable !== $this->focusable) {
            $this->focusable = $focusable;
            self::focusMayMove($this);
        }
    }

    /** True unless `setFocusable(false)` was called. */
    public function isFocusable(): bool
    {
        return $this->focusable;
    }

    /** True if keys go to this surface or through it: it is on the focus path from its root. */
    public function hasFocus(): bool
    {
        $s = $this;
        while (($v = $s->viewport()) !== null) {
            if (!$v->isFocused()) {
                return false;
            }
            $s = $v->host();
        }
        return true;
    }

    /** The hosted surface that has this surface's focus, or null when this surface keeps it. */
    public function focused(): ?Surface
    {
        return $this->host?->focus?->source;
    }

    /**
     * Move the focus to the next window in order (the previous one with $backwards): through
     * every surface below this one that hosts nothing, depth first, in hosting order, wrapping
     * around (with `$wrap` false, stopping at the last window: for a group of fields inside a
     * larger one, which goes on from there). Bind it to a key (Tab, or a prefix key when the
     * windows are shells that need Tab). Returns false when there is no other window to go to.
     */
    public function focusNext(bool $backwards = false, bool $wrap = true): bool
    {
        return $this->host?->focusNext($backwards, $wrap) ?? false;
    }

    /**
     * Move the focus to the window next to the focused one in $direction (`'left'`, `'right'`,
     * `'up'` or `'down'`), by where the windows below this surface show on the screen (`area()`):
     * the nearest one on that side, preferring one that lies alongside the focused window. Bind
     * it to keys (Alt-arrows, or a prefix key and the arrows, as in tmux). Returns false when
     * there is no window in that direction.
     *
     * @throws \InvalidArgumentException for another direction
     */
    public function focusToward(string $direction): bool
    {
        if (!\in_array($direction, ['left', 'right', 'up', 'down'], true)) {
            throw new \InvalidArgumentException("Unknown direction '$direction'");
        }
        return $this->host?->focusToward($direction) ?? false;
    }

    /**
     * Whether pressing a mouse button on a window focuses it: on the windows of this surface and
     * of every surface below it that has not been set otherwise (off by default). Set it on the
     * root to have click-to-focus everywhere, nested layouts included.
     */
    public function setFocusOnClick(bool $on): void
    {
        $this->hosting()->clickFocus = $on;
    }

    /** @internal True if a press on a window of this surface focuses it (set here or inherited). */
    public function focusesOnClick(): bool
    {
        return $this->host?->clickFocus ?? $this->parent()?->focusesOnClick() ?? false;
    }

    /**
     * Make what this surface shows from its own cells and its windows now (they are copied in
     * z-order, clipped, to any depth: about a millisecond for a screen of 160x50). Happens by
     * itself whenever this surface's cells, damage or version are read after a change, and a
     * terminal device does it every frame; call it only to force the work at a moment of your
     * choosing. While a hosted program is in the middle of a synchronized update (mode 2026)
     * its window shows the cells it had when the update began, for at most half a second.
     */
    public function compose(): void
    {
        $this->host?->compose();
    }

    /**
     * Bring this surface up to date now: producers that draw in frames rather than at once (the
     * components, whose renders wait for the next frame) draw what they owe, then the hosted
     * surfaces are composed into it (`compose()`). A terminal device does this before each frame
     * it draws and before it freezes rows; call it to read what the program shows after its
     * last change without waiting for the frame.
     */
    public function flush(): void
    {
        foreach (self::$flushers as $flusher) {
            $flusher();
        }
        $this->compose();
    }

    /**
     * @internal $flusher draws what a kind of producer owes (see `flush()`); the component
     * layer registers its frame here.
     */
    public static function onFlush(\Closure $flusher): void
    {
        self::$flushers[] = $flusher;
    }

    /**
     * True when something it shows changed since it was composed (a hosted surface, a window),
     * or a window holds back a synchronized update (so it is to be composed again soon).
     */
    public function needsCompose(): bool
    {
        return $this->host !== null && ($this->host->dirty || $this->host->holding());
    }

    /**
     * The cursor a terminal showing this surface displays: that of the focused hosted surface
     * (down the focus path), at its position on this surface, hidden when it is outside its
     * window; this surface's own `cursor()` when it keeps the focus.
     */
    public function activeCursor(): Cursor
    {
        $view = $this->host?->focus;
        if ($view === null) {
            return $this->cursor();
        }
        $c = $view->source->activeCursor();
        $at = $view->toHost($c->x, $c->y);
        if ($at === null) {
            return new Cursor(0, 0, false, $c->shape, $c->blinking);
        }
        return new Cursor($at[0], $at[1], $c->visible, $c->shape, $c->blinking);
    }

    /**
     * @internal A number that changes whenever a surface is hosted somewhere or unhosted: an
     * answer about which surfaces are shown, computed at the same number, is still right.
     */
    public static function hostingVersion(): int
    {
        return self::$hostingVersion;
    }

    /** @internal Hosts tell a surface which viewports show it. */
    public function shownThrough(Viewport $view, bool $shown): void
    {
        self::$hostingVersion++;
        $this->views = array_values(array_filter($this->views, fn(Viewport $v) => $v !== $view));
        if ($shown) {
            $this->views[] = $view;
        }
        $this->detached = $this->views === [];
    }

    /**
     * @internal True if this surface was shown in a window and is in none now: what it hosts is
     * not on any screen (a hidden tab's pane, a window closed but kept). A surface never hosted
     * (a root of its own) is not detached; ask the root (`$surface->root()->detached()`) whether
     * a surface is in a tree that is shown.
     */
    public function detached(): bool
    {
        return $this->detached;
    }

    private function hosting(): Host
    {
        return $this->host ??= new Host($this, $this->vt, fn() => $this->emit('change', null));
    }

    /**
     * Run $code in a new coroutine as the producer of this surface; returns its awaitable (pass it
     * to `fiber\await()`). Must be called inside `fiber\run()`.
     *
     * $code receives the surface. With ext-phasync 2.0.7 or later loaded, everything the coroutine prints with
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

    /**
     * True when `run()` captures `echo` output: ext-phasync 2.0.7 or later is loaded (older
     * versions have a different `sapi()` contract and are treated as absent).
     */
    public static function canCaptureOutput(): bool
    {
        return \function_exists('phasync\ext\sapi')
            && version_compare((string) phpversion('phasync'), SurfaceSapi::MIN_EXT_VERSION, '>=');
    }

    /**
     * @internal The rows of this surface's own cells as they were when its program began the
     * synchronized update it is in (mode 2026), for at most half a second; null when it is in
     * none (or took longer). A host shows them meanwhile.
     *
     * @return list<Line>|null
     */
    public function heldLines(): ?array
    {
        $vt = $this->vt;
        return $vt->synced !== null && microtime(true) - $vt->syncStarted < self::SYNC_TIMEOUT ? $vt->synced : null;
    }

    /**
     * @internal A number that grows with every change of this surface or of what it hosts,
     * without composing it (see `version()`): a window showing it whose number is the same
     * shows the same cells.
     */
    public function changeCount(): int
    {
        return $this->vt->version;
    }

    /**
     * @internal The rows of this surface's own cells (what its producer wrote, without the
     * windows it hosts), for the compositor.
     *
     * @return list<Line>
     */
    public function ownLines(): array
    {
        return $this->vt->lines;
    }

    /** @internal @return array<int, int> the version at which each row of its own cells last changed */
    public function ownRowVersions(): array
    {
        return $this->vt->rowVersion;
    }

    /** @internal Scrollback row $y (negative), or null beyond the oldest one kept. */
    public function scrollbackLine(int $y): ?Line
    {
        return $this->vt->scrollback[$this->vt->scrollbackEnd + $y] ?? null;
    }

    /**
     * @internal Tell this surface's listeners of $event (`title`, `bell`, `clipboard`) as if its
     * own producer had caused it: how a host passes a hosted surface's up. A title becomes this
     * surface's title.
     */
    public function relay(string $event, mixed $data = null): void
    {
        if ($event === 'title') {
            $this->vt->title = $data;
        }
        $this->emit($event, $data);
    }

    /**
     * @internal The cells of row $y as parallel arrays of graphemes ('' for a continuation) and
     * styles, for renderers in this library. Not part of the public API.
     *
     * @return array{list<string>, list<Style>}
     */
    public function rowData(int $y): array
    {
        $line = $this->row($y);
        return [$line->chars, $line->styles];
    }

    /**
     * The cells of the rectangle at column $x, row $y, $width wide and $height high, as this
     * surface shows them (its windows included): to show them somewhere else with
     * `drawArea()` (a snapshot of a pane, a frame to keep). Negative rows are the scrollback;
     * what lies outside the surface and its scrollback is blank. A wide character cut by the
     * rectangle's edge is a blank in the copy.
     */
    public function copyArea(int $x, int $y, int $width, int $height): Area
    {
        $width = max(0, $width);
        $height = max(0, $height);
        $chars = $styles = [];
        $rows = $this->vt->rows;
        for ($r = $y; $r < $y + $height; $r++) {
            $line = $r >= 0 ? ($r < $rows ? $this->row($r) : null) : $this->scrollbackLine($r);
            if ($line === null) {
                $chars[] = array_fill(0, $width, ' ');
                $styles[] = array_fill(0, $width, Style::plain());
            } else {
                [$chars[], $styles[]] = Area::cut($line->chars, $line->styles, $x, $width);
            }
        }
        return new Area($width, $height, $chars, $styles);
    }

    /**
     * Put the cells of $area (from `copyArea()` of this or another surface) on this surface
     * with their top-left corner at column $x, row $y; what falls outside is left out, and a wide
     * character cut by an edge becomes a blank, as does one half of a wide character this
     * surface had where the area ends. The cursor and the pen stay as they are, as for a
     * terminal's rectangular copy. The cells are this surface's own, as if its producer had
     * written them.
     */
    public function drawArea(Area $area, int $x, int $y): void
    {
        $vt = $this->vt;
        $left = max(0, $x);
        $right = min($vt->cols, $x + $area->width);
        if ($left >= $right) {
            return;
        }
        $vt->version++;
        for ($r = max(0, $y), $end = min($vt->rows, $y + $area->height); $r < $end; $r++) {
            [$c, $s] = Area::cut($area->chars[$r - $y], $area->styles[$r - $y], $left - $x, $right - $left);
            $line = $vt->lines[$r];
            Area::put($line->chars, $line->styles, $left, $c, $s);
            $vt->rowVersion[$r] = $vt->version;
        }
        if ($this->host !== null) {
            $this->host->dirty = true;
        }
        $this->emit('change', null);
    }

    /** Row $y as it shows ($shown: windows included), or of its own cells; negative rows read the scrollback. */
    private function row(int $y, bool $shown = true): Line
    {
        $vt = $this->vt;
        if ($y >= 0) {
            if ($y >= $vt->rows) {
                throw new \OutOfRangeException("Row $y is outside 0.." . ($vt->rows - 1));
            }
            if ($shown && $this->host !== null) {
                $this->host->compose();
                return $this->host->frameLine($y);
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
