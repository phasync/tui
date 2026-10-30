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
 * `FakeTerminal` for tests) attached to the root shows it on a real terminal. The host repaints
 * what changed in its hosted surfaces into its own cells by writing escape sequences to itself,
 * and passes input events (`dispatch()`) down to the focused one.
 */
final class Surface implements \Stringable
{
    /** The bytes the producer of this surface reads: keys, mouse reports, pastes, replies. */
    public readonly Input $input;

    private Emulator $vt;
    private AnsiParser $parser;
    private string $utf8Tail = '';
    private ?int $damageTaken = null;
    private ?Host $host = null;
    /** @var list<Viewport> the viewports this surface shows through, the one of its parent last */
    private array $views = [];
    /** A focus() request made while not hosted, applied when it is. */
    private int $focusWanted = 0;
    /** Numbers focus requests, so the latest wins when several wait to be hosted. */
    private static int $focusClock = 0;
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
        $this->host?->wrote();
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
        $this->host?->arrange();
        $this->emit('resize', new Resize($this->vt->cols, $this->vt->rows));
        $this->emit('change', null);
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

    /** True if row $y continues on the next row because the text wrapped there. */
    public function isWrapped(int $y): bool
    {
        return $this->row($y)->wrapped;
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
     */
    public function dump(): string
    {
        $this->compose();
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
     * - `change`: after every `write()` and `resize()`, and when a hosted surface changed (the
     *   cells catch up when they are read, or with `compose()`)
     * - `resize`: receives a `Resize`
     * - `title`: receives the new title
     * - `bell`: the program rang the bell (BEL)
     * - `clipboard`: the program asked to copy text to the clipboard (OSC 52); receives the text
     * - `close`: `close()` was called: the session on this surface is over
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
        return function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);
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
            'mouse' => InputEncoder::mouse($event, $this),
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

    // ------------------------------------------------------------------------------------------
    // Hosting

    /**
     * Show $child on this surface and return its `Viewport`. Without geometry the child shows at
     * the top-left corner at its own size; place it with $x/$y/$width/$height here or later on
     * the viewport, or let a layout (`setLayout()`) place it. A surface is hosted at most once
     * per host: hosting it again returns the same viewport (moved and resized if you pass
     * geometry). The first surface hosted gets the focus, and so does one that asked for it with
     * `focus()` before it was shown.
     *
     * The child's changes show on this surface (and on its host, up to the root): this surface
     * repaints them into its own cells by writing escape sequences to itself, when its cells are
     * read or `compose()` is called. What this surface's own producer writes under a window is
     * painted over by the window. Its title (while focused), bell and clipboard requests are
     * passed up the same way.
     *
     * The surfaces form a tree: the child's `parent()` is now this surface. A surface shown on
     * several hosts at once (a mirror) belongs to the one that hosted it last.
     *
     * @throws \InvalidArgumentException when $child is this surface or hosts it
     */
    public function host(Surface $child, ?int $x = null, ?int $y = null, ?int $width = null, ?int $height = null): Viewport
    {
        $view = $this->hosting()->host($child, $x, $y, $width, $height);
        if ($child->focusWanted > 0 && $child->viewport() === $view) {
            // focus() was called before it was shown
            $stamp = $child->focusWanted;
            $child->focusWanted = 0;
            if ($stamp > $this->host->focusStamp) {
                $child->focusWith($stamp);
            }
        }
        return $view;
    }

    /** Stop showing $child; the area it covered is blanked. Nothing happens if it is not hosted here. */
    public function unhost(Surface $child): void
    {
        $this->host?->unhost($child);
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
        $rect = new Rect(0, 0, $this->cols(), $this->rows());
        $s = $this;
        while (($v = $s->viewport()) !== null) {
            $b = $v->hasBorder() ? 1 : 0;
            $inner = new Rect($v->x() + $b, $v->y() + $b, $v->innerWidth(), $v->innerHeight());
            $rect = (new Rect($inner->x + $rect->x - $v->left(), $inner->y + $rect->y - $v->top(), $rect->width, $rect->height))
                ->intersect($inner);
            $s = $v->host();
            $rect = $rect->intersect(new Rect(0, 0, $s->cols(), $s->rows()));
        }
        return $rect;
    }

    /**
     * Let $layout place the hosted surfaces (see `Split` and `Stack`), now and whenever this
     * surface is resized. The layout owns the windows: surfaces it does not place are unhosted.
     * Pass null to keep the windows where they are and stop arranging them.
     */
    public function setLayout(?Layout $layout): void
    {
        $this->hosting()->layout = $layout;
        $this->host->arrange();
    }

    /** The layout set with `setLayout()`, or null. */
    public function layout(): ?Layout
    {
        return $this->host?->layout;
    }

    /**
     * @internal For layouts: the surface that shows $layout, nested as the item numbered $index
     * of the layout of this surface. The same surface for the same index on every arrange.
     */
    public function nestedSurface(int $index, Layout $layout): Surface
    {
        $surface = $this->hosting()->nested($index);
        if ($surface->layout() !== $layout) {
            $surface->setLayout($layout);
        }
        return $surface;
    }

    // ------------------------------------------------------------------------------------------
    // Focus

    /**
     * Give the focus to this surface: every host from the root down to it focuses the way to it,
     * so keys and pastes come here (or, when this surface hosts windows, go on to the one of them
     * it focuses). The surfaces losing the focus and those gaining it receive a `Focus` event (a
     * program that enabled focus reporting reads `ESC [O` / `ESC [I`).
     *
     * Works at any depth and through any hosts, components or not. Called on a surface that is
     * not shown yet (a window a layout is about to place), it takes effect when it is hosted.
     */
    public function focus(): void
    {
        $this->focusWith(++self::$focusClock);
    }

    /**
     * Focus this surface (as `focus()`) and keep the keys here: its own windows lose the focus,
     * so keys and pastes go to this surface's producer and input listeners. For a program with
     * a window over it (a pop-up on a pane) when the program, not the pop-up, should get the keys.
     */
    public function takeFocus(): void
    {
        $this->focus();
        $this->host?->setFocus(null, self::$focusClock);
    }

    /** @internal Focus this surface for the focus request numbered $stamp (see `focus()`). */
    public function focusWith(int $stamp): void
    {
        // the levels from the top down: [host, the surface on the way here]
        $chain = [];
        $top = $this;
        while (($v = $top->viewport()) !== null) {
            array_unshift($chain, [$v->host(), $top]);
            $top = $v->host();
        }
        // not shown yet (or shown on a host that is not): applied when it is hosted
        $top->focusWanted = $stamp;
        $changed = null;
        foreach ($chain as [$host, $child]) {
            if ($changed === null && $host->focused() !== $child) {
                $changed = [$host->focused(), $child];
            }
            $host->hosting()->setFocus($child, $stamp, false);
        }
        if ($changed !== null) {
            // the branch that left the focus path and the one that joined it are told, once:
            // the event travels down each branch's own focus path
            $changed[0]?->dispatch(new Focus(false));
            $changed[1]->dispatch(new Focus(true));
        }
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
     * around. Bind it to a key (Tab, or a prefix key when the windows are shells that need Tab).
     * Returns false when there is no other window to go to.
     */
    public function focusNext(bool $backwards = false): bool
    {
        return $this->host?->focusNext($backwards) ?? false;
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

    /** Whether pressing a mouse button on a hosted surface focuses it (off by default). */
    public function setFocusOnClick(bool $on): void
    {
        $this->hosting()->clickFocus = $on;
    }

    /**
     * Repaint what changed in the hosted surfaces into this surface now. Happens by itself
     * whenever this surface's cells, damage or version are read, and a terminal device does it
     * every frame; call it only to force the work at a moment of your choosing. While a hosted
     * program is in the middle of a synchronized update (mode 2026) its window keeps the previous
     * frame, for at most half a second.
     */
    public function compose(): void
    {
        if ($this->host !== null && $this->host->dirty) {
            $this->host->compose();
        }
    }

    /** True when a hosted surface changed and this surface's cells have not caught up yet. */
    public function needsCompose(): bool
    {
        return $this->host !== null && $this->host->dirty;
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
        $this->compose();
        $c = $view->source->activeCursor();
        $at = $view->toHost($c->x, $c->y);
        if ($at === null) {
            return new Cursor(0, 0, false, $c->shape, $c->blinking);
        }
        return new Cursor($at[0], $at[1], $c->visible, $c->shape, $c->blinking);
    }

    /** @internal Hosts tell a surface which viewports show it. */
    public function shownThrough(Viewport $view, bool $shown): void
    {
        $this->views = array_values(array_filter($this->views, fn(Viewport $v) => $v !== $view));
        if ($shown) {
            $this->views[] = $view;
        }
    }

    private function hosting(): Host
    {
        return $this->host ??= new Host($this, $this->vt, fn() => $this->emit('change', null));
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
     * @internal Seconds since the program began the synchronized update it is in (mode 2026), or
     * null when it is not in one. Hosts hold its window back meanwhile, for a limited time.
     */
    public function syncAge(): ?float
    {
        return ($this->vt->modes[2026] ?? false) ? microtime(true) - $this->vt->syncStarted : null;
    }

    /**
     * @internal The cells of row $y as parallel arrays of graphemes ('' for a continuation) and
     * styles, for renderers in this library (Viewport). Not part of the public API.
     *
     * @return array{list<string>, list<Style>}
     */
    public function rowData(int $y): array
    {
        $this->compose();
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
