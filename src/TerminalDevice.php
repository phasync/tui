<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Signal;

use function fiber\go;
use function fiber\sleep;

/**
 * Shows a root Surface on a terminal and feeds the terminal's input to it: the bridge between
 * the surface tree and the outside world. `Tty` is the real terminal; `FakeTerminal` an in-memory
 * one for tests and headless programs. Both work the same way:
 *
 *     fiber\run(function () {
 *         $root = new Surface();
 *         $tty = new Tty();
 *         $tty->attach($root);              // takes over the terminal, sizes $root to it
 *         $root->on('key', function (Key $key) use ($tty) {
 *             if ($key->is('ctrl+q')) {
 *                 $tty->detach();           // gives the terminal back
 *                 return true;
 *             }
 *         });
 *         ...                               // host surfaces on $root, start ptys
 *         $tty->wait();                     // until detached
 *     });
 *
 * While attached, the device:
 *
 * - draws the root's damage as the minimum cursor moves and SGR runs against the previous frame,
 *   at most 60 frames a second and only when something changed, in a coroutine of its own;
 *   frames are wrapped in synchronized output (mode 2026), and the cursor shown is the root's
 *   `activeCursor()` (that of the focused window);
 * - decodes the terminal's input into `Key`, `Mouse`, `Paste` and `Focus` events and gives them
 *   to the root with `Surface::dispatch()`;
 * - follows the terminal's size, resizing the root (which re-lays its windows);
 * - passes the root's title, bell and clipboard requests (OSC 52) to the terminal;
 * - keeps the terminal in raw mode on the alternate screen with mouse reporting (button events,
 *   SGR encoding; motion without a button too while a program in the tree asks for it with
 *   mode 1003), bracketed paste and focus reporting on, and the kitty keyboard protocol
 *   (disambiguated keys) pushed when the terminal answers its query; `detach()` undoes all of it;
 * - writes the hyperlinks of the cells (OSC 8) when the terminal supports them
 *   (`supportsHyperlinks()`), else the text alone;
 * - writes colours the terminal has: on a terminal of 256 or 16 colours (`colors()`), a
 *   truecolour cell (a Tailwind colour, `#ff5500`) is drawn in the nearest colour it has
 *   (`Color::nearest()`), in this one place, so the cells keep the colours the program chose.
 *
 * **Inline mode** (`Tty::inline()`, `FakeTerminal::inline()`): instead of taking the screen
 * over, the root shows as a live region at the bottom of the terminal's normal screen, under
 * what the terminal shows already. The region is as tall as the root's content (its rows down
 * to the last one with something in it, or with the cursor shown), at most the root's height,
 * which is the terminal's less one row: the line under the region, where the cursor waits
 * (and the shell's prompt appears after the program). The root's scrollback is the terminal's: a row
 * that scrolls off the root's top is written once more above the region, as it was then, and
 * never touched again; `println()` puts lines there too. Only relative cursor motion is used
 * (the region moves whenever the terminal scrolls), the damage is drawn against the previous
 * frame as in full-screen mode, and the cursor waits on the line under the region (or where the
 * root shows it, inside the region). No alternate screen, and no mouse reporting unless a
 * surface in the tree asks for it (mode 1000, 1002 or 1003, as a program in a terminal does),
 * so the terminal's own selection and scrolling keep working. Detaching leaves the last frame
 * standing with the cursor under it (or erases the region, with `keep: false`). A width change
 * of the terminal repaints the region; terminals that rewrap their lines on a width change may
 * leave a copy of its rows above it. While the region is live, keys come through the library,
 * not `fgets(STDIN)`. The region starts on the line the cursor is on: print a newline first if
 * that line has text.
 *
 * **Ending.** `detach()` gives the terminal back and closes the root (`Surface::close()`), which
 * disposes a component on it, and so the whole component tree and the programs of its
 * `Terminal`s; closing the root (or disposing the root component) detaches the device. The
 * device's coroutines run while it is attached, so an attached device keeps `fiber\run()` from
 * returning; once it is detached and the programs have ended, `fiber\run()` returns. When the
 * code that called `attach()` fails (the closure given to `fiber\run()`, or the coroutine that
 * attached, throws), the device detaches by itself, so the terminal is restored and the
 * exception comes out of `fiber\run()` (with the runtime versions `Component::start()` names).
 *
 * `attach()`, `detach()` and `wait()` must be called inside `fiber\run()`.
 */
abstract class TerminalDevice
{
    /** What `attach()` sends: alternate screen, cursor hidden, mouse, paste and focus reporting. */
    private const TAKEOVER = "\e[?1049h\e[?25l\e[?1000h\e[?1002h\e[?1006h\e[?2004h\e[?1004h";
    /** What `detach()` sends to undo it (`Tty` also sends it when the program dies). */
    private const RESTORE = "\e[0m\e[<u\e[?1004l\e[?2004l\e[?1006l\e[?1003l\e[?1002l\e[?1000l\e[0 q\e[?25h\e[?1049l";
    /** Inline mode: the normal screen, no mouse reporting until a surface asks for it. */
    private const INLINE_TAKEOVER = "\e[?25l\e[?2004h\e[?1004h";
    /** Inline mode's restore: as RESTORE, without leaving an alternate screen it never entered. */
    private const INLINE_RESTORE = "\e[0m\e[<u\e[?1004l\e[?2004l\e[?1006l\e[?1003l\e[?1002l\e[?1000l\e[0 q\e[?25h";
    /** Terminals that answer XTVERSION and write OSC 8 hyperlinks. */
    private const HYPERLINK_TERMINALS = '/^(kitty|WezTerm|foot|ghostty|iTerm2|contour|phasync-tui)\b/i';

    private ?Surface $root = null;
    /** @var \WeakMap<Surface, TerminalDevice>|null the device each attached root is shown on */
    private static ?\WeakMap $devices = null;
    private InputDecoder $decoder;
    private ?Signal $wake = null;
    private ?Signal $detached = null;
    private bool $damaged = false;
    /** A render was asked for while a frame was being drawn (see drawsFrames()). */
    private bool $frameAgain = false;
    private bool $drawing = false;
    private bool $redraw = true;
    private float $lastFrame = 0.0;
    private int $drawnVersion = -1;
    /** @var array<int, array{list<string>, list<Style>}> the frame on the terminal, per row */
    private array $frame = [];
    private ?Style $pen = null;
    private ?Cursor $shown = null;
    /** @var list<\Closure> */
    private array $offs = [];
    private ?float $probing = null;
    private string $probe = '';
    private bool $kitty = false;
    /** The terminal's background as it answered OSC 11: 'light' or 'dark'; null when it did not. */
    private ?string $background = null;
    /** @var list<\Closure(): void> called when the probe has ended */
    private array $probed = [];
    /** Mouse motion without a button is reported (mode 1003 on the terminal). */
    private bool $motion = false;
    /** Inline mode: mouse reporting is on, because a surface in the tree asked for it. */
    private bool $mouse = false;
    /** The terminal's name from its XTVERSION answer, '' when it did not answer. */
    private string $terminalName = '';
    /** The hyperlink the terminal writes in: [URI, id]. */
    private array $link = ['', ''];
    /**
     * Whether the terminal writes OSC 8 hyperlinks: true or false as the device (or its
     * environment) knows it, null to decide from the terminal's XTVERSION answer.
     */
    protected ?bool $hyperlinks = null;
    /** How many colours the terminal shows (`Color::DEPTH_16`, `DEPTH_256`, `DEPTH_TRUECOLOR`); set by subclasses. */
    protected int $colors = Color::DEPTH_TRUECOLOR;
    /** @var array<int, string> SGR for each style as this terminal shows it, by style (styles are interned) */
    private array $sgr = [];
    /** Inline mode (see the class description), and whether detaching leaves the last frame. */
    private bool $inline = false;
    private bool $keep = true;
    /** Inline: the rows the region has on the terminal now. */
    private int $region = 0;
    /** Inline: where the terminal's cursor is, relative to the region's top-left (x -1: column not known). */
    private array $at = [-1, 0];
    /** Inline: the lowest row (relative to the region's top) known to exist on the terminal. */
    private int $reach = 0;
    /** Inline: the root's linesScrolled() at the previous frame. */
    private int $scrolledOff = 0;
    /** @var list<array{list<string>, list<Style>}> inline: rows to write above the region at the next frame */
    private array $printed = [];
    /** Inline: detaching; the last frame leaves the cursor out of the region. */
    private bool $ending = false;
    /** @var list<string> lines given to println() while attached full screen, printed after detach */
    private array $held = [];

    /** Prepare the terminal (raw mode); $restore is to be sent when it is restored, even on a crash. */
    abstract protected function open(string $restore): void;

    /** Send the restore sequence and give the terminal back as it was. */
    abstract protected function close(): void;

    /** Send bytes to the terminal (may wait cooperatively until it accepts them). */
    abstract protected function send(string $bytes): void;

    /** Wait up to $timeout seconds for input; '' when none came. Never blocks other coroutines. */
    abstract protected function read(float $timeout): string;

    /**
     * The terminal's size as [cols, rows].
     *
     * @return array{int,int}
     */
    abstract public function size(): array;

    /**
     * Make this device show its root in inline mode (see the class description), for the
     * subclasses' `inline()` constructors.
     */
    protected function useInline(bool $keep): void
    {
        $this->inline = true;
        $this->keep = $keep;
    }

    /**
     * Inline mode, for subclasses that show the terminal: the rows from the region's top down
     * to the terminal's cursor, and the region's height; null when not attached inline.
     *
     * @return array{int,int}|null
     */
    protected function regionPlace(): ?array
    {
        return $this->inline && $this->root !== null ? [$this->at[1], $this->region] : null;
    }

    /** True if this device shows its root inline, as a live region in the normal screen (see the class description). */
    public function isInline(): bool
    {
        return $this->inline;
    }

    /**
     * Show $root on this terminal and send it the terminal's input, until `detach()`. The root is
     * resized to the terminal's size at once and whenever the terminal is resized (in inline
     * mode too: the region is the part of it with content). Detaches by itself when the task
     * that called it ends with an exception (see the class description).
     *
     * @throws \LogicException when already attached
     */
    public function attach(Surface $root): void
    {
        if ($this->root !== null) {
            throw new \LogicException('This terminal already shows a surface; detach() it first');
        }
        Internal\Runtime::check();
        $this->decoder = new InputDecoder();
        $this->wake = new Signal();
        $this->detached ??= new Signal();
        $this->frame = [];
        $this->redraw = true;
        $this->damaged = true;
        $this->pen = null;
        $this->shown = null;
        $this->kitty = false;
        $this->background = null;
        $this->motion = false;
        $this->mouse = false;
        $this->terminalName = '';
        $this->link = ['', ''];
        $this->region = 0;
        $this->at = $this->inline ? [-1, 0] : [-1, -1];   // (inline: on the region's top row, the column not known)
        $this->reach = 0;
        $this->printed = [];
        $this->open($this->inline ? self::INLINE_RESTORE : self::RESTORE);
        $this->root = $root;
        self::$devices ??= new \WeakMap();
        self::$devices[$root] = $this;
        [$cols, $rows] = $this->size();
        $root->resize($cols, $this->inline ? max(1, $rows - 1) : $rows);
        $this->scrolledOff = $root->linesScrolled();
        $this->offs = [
            $root->on('change', function (): void {
                if (!$this->drawing) {
                    $this->damage();
                }
            }),
            $root->on('title', fn(string $title) => $this->send(Ansi::title($title))),
            $root->on('bell', fn() => $this->send("\x07")),
            $root->on('clipboard', fn(string $text) => $this->clipboard($text)),
            $root->on('close', fn() => $this->release(false)),
        ];
        // ask for the kitty keyboard protocol, the terminal's name and its background colour;
        // the answer to primary device attributes (which every terminal answers) ends the probe
        $this->probing = microtime(true);
        $this->probe = '';
        $this->send(($this->inline ? self::INLINE_TAKEOVER : self::TAKEOVER) . ($root->title() !== '' ? Ansi::title($root->title()) : '') . "\e[?u\e[>0q\e]11;?\e\\\e[c");
        go($this->frames(...));
        go($this->inputs(...));
        // the code that attached fails: give the terminal back, so the exception ends fiber\run()
        Internal\Runtime::watch(function () use ($root): void {
            if ($this->root === $root) {
                $this->detach();
            }
        });
    }

    /**
     * Stop showing the root and give the terminal back: modes off, main screen, cursor shown,
     * terminal settings restored. The device's coroutines end, and the root is closed
     * (`Surface::close()`): a component on it is disposed with its children, which hangs up the
     * programs of its `Terminal`s. This is how a program quits. Safe to call when not attached.
     */
    public function detach(): void
    {
        $this->release(true);
    }

    private function release(bool $closeRoot): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }
        foreach ($this->offs as $off) {
            $off();
        }
        $this->offs = [];
        if ($this->inline) {
            // the last frame, then the cursor under it (or the region erased)
            $this->ending = true;
            $this->refresh();
            $this->ending = false;
            $this->send($this->link() . "\e[0m" . ($this->keep ? $this->move(0, $this->region) : $this->move(0, 0) . "\e[J"));
        }
        $this->root = null;
        unset(self::$devices[$root]);
        $this->wake?->notify();
        $this->close();
        foreach ($this->held as $line) {
            $this->send($line);
        }
        $this->held = [];
        $this->detached?->notify();
        if ($closeRoot) {
            $root->close();
        }
    }

    /**
     * Print $text (lines, with escape sequences if you like) as plain output of the program. In
     * inline mode it goes above the live region, into the terminal's scrollback, at the next
     * frame, and the region is drawn again under it. Not attached, it is written to the terminal
     * at once; attached full screen (where the alternate screen would hide it), after `detach()`.
     * A line break ends each call.
     */
    public function println(string $text = ''): void
    {
        $text = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $text) . "\r\n";
        if ($this->root === null) {
            $this->send($text);
            return;
        }
        if (!$this->inline) {
            $this->held[] = $text;
            return;
        }
        // laid out as the terminal will show it: through a surface as wide as the root
        $page = new Surface($this->root->cols(), 1, 1 << 20);
        $page->write("\e[?25l" . $text);
        for ($y = -$page->scrollbackSize(); $y < 0; $y++) {
            $this->printed[] = $page->rowData($y);
        }
        $this->damage();
    }

    /**
     * Inline mode: keep rows of the live region as they show now, above it, in the terminal's
     * scrollback, never to change again: a finished job's progress line, a result, a step that
     * is done. $what is a row of the root, or a surface shown on it (a component's: its rows,
     * `$tty->freeze($row->surface)`). They are written above the region at the next frame, as
     * `println()` lines are; the program then stops showing them (removes the row from its
     * render), and the region shrinks under them. It takes the rows as the root shows them at
     * the call, after the renders the program asked for until then (`Surface::flush()`), so a
     * component's coroutine sets the final state and freezes at once. Full screen, the rows
     * are kept and written after `detach()`, as `println()` lines are. Not attached: nothing.
     */
    public function freeze(int|Surface $what): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }
        $root->flush();     // (what the program set last is what freezes)
        if ($what instanceof Surface) {
            $area = $what->area();
            $top = $area->y - $root->area()->y;
            $rows = $area->isEmpty() ? [] : range(max(0, $top), min($root->rows(), $top + $area->height) - 1);
        } else {
            $rows = $what >= 0 && $what < $root->rows() ? [$what] : [];
        }
        foreach ($rows as $y) {
            [$chars, $styles] = $root->rowData($y);
            if ($this->inline) {
                $this->printed[] = [$chars, $styles];
            } else {
                $this->held[] = $this->rowText($chars, $styles) . "\e[0m\r\n";
            }
        }
        $this->damage();
    }

    /**
     * A row's cells as text with SGR sequences, trailing blanks cut.
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     */
    private function rowText(array $chars, array $styles): string
    {
        $plain = Style::plain();
        $end = \count($chars);
        while ($end > 0 && ($chars[$end - 1] === ' ' || $chars[$end - 1] === '') && $styles[$end - 1] === $plain) {
            $end--;
        }
        $out = '';
        $pen = $plain;
        for ($i = 0; $i < $end; $i++) {
            if ($styles[$i] !== $pen) {
                $out .= $this->sgr($styles[$i]);
                $pen = $styles[$i];
            }
            $out .= $chars[$i];
        }
        return $out;
    }

    /**
     * True if the terminal shows OSC 8 hyperlinks, so the device writes the cells' links to it;
     * else it writes their text alone. `Tty` knows it from the environment (`TERM_PROGRAM`,
     * `VTE_VERSION`, `TERM` and the like) or from the name the terminal answers when attached,
     * conservatively: false for a terminal it does not know to support them, and inside tmux or
     * screen. `FakeTerminal` is told (default true). A widget that would show a link's address
     * as text otherwise asks this (`Markdown`'s `hyperlinks:`).
     */
    public function supportsHyperlinks(): bool
    {
        return $this->hyperlinks ?? preg_match(self::HYPERLINK_TERMINALS, $this->terminalName) === 1;
    }

    /**
     * How many colours the terminal shows: `Color::DEPTH_TRUECOLOR`, `Color::DEPTH_256` or
     * `Color::DEPTH_16`. Colours a cell has that the terminal has not are drawn as the nearest
     * one it has. `Tty` knows it from `COLORTERM` and `TERM`; `FakeTerminal` is told (default
     * truecolour).
     */
    public function colors(): int
    {
        return $this->colors;
    }

    /** Draw for a terminal of $colors colours from now on (see `colors()`): for a terminal the environment says wrong. */
    public function setColors(int $colors): void
    {
        $colors = $colors >= Color::DEPTH_TRUECOLOR ? Color::DEPTH_TRUECOLOR : ($colors >= 256 ? 256 : 16);
        if ($colors !== $this->colors) {
            $this->colors = $colors;
            $this->sgr = [];
            $this->redraw = true;
            if ($this->root !== null) {
                $this->damage();
            }
        }
    }

    /** SGR for $style in the colours this terminal has. */
    private function sgr(Style $style): string
    {
        if ($this->colors >= Color::DEPTH_TRUECOLOR) {
            return Ansi::style($style);
        }
        $id = spl_object_id($style);
        if (!isset($this->sgr[$id])) {
            $fg = Color::nearest($style->fg, $this->colors);
            $bg = Color::nearest($style->bg, $this->colors);
            $this->sgr[$id] = Ansi::style($fg === $style->fg && $bg === $style->bg ? $style : Style::of($fg, $bg, $style->attrs));
        }
        return $this->sgr[$id];
    }

    /** The name the terminal gave when asked (XTVERSION) at `attach()`, such as `kitty(0.36.4)`; '' when it did not answer. */
    public function terminalName(): string
    {
        return $this->terminalName;
    }

    /** True between `attach()` and `detach()`. */
    public function isAttached(): bool
    {
        return $this->root !== null;
    }

    /**
     * The device $root is attached to, or null: how code that has a surface finds its terminal
     * (a component: `Component::device()`), to `println()` or `freeze()`.
     */
    public static function of(Surface $root): ?TerminalDevice
    {
        return self::$devices[$root] ?? null;
    }

    /** The surface shown, or null when not attached. */
    public function root(): ?Surface
    {
        return $this->root;
    }

    /**
     * The component shown on this device: the root component started on it (`Tui::run()`'s,
     * `Tui::headless()`'s, or one given to `start()`), null when none is. How a test or a
     * program without a class reaches the components of its tree: `$terminal->component()
     * ->children()['progress']` finds the one placed with `key: 'progress'`. Keeping the
     * instances you place (`$bars[$i] ??= new ProgressBar(...)`) is the simpler way.
     */
    public function component(): ?Component
    {
        return $this->root === null ? null : Component::of($this->root);
    }

    /** Wait (cooperatively) until `detach()` is called; returns at once when not attached. */
    public function wait(): void
    {
        while ($this->root !== null) {
            $this->detached->wait();
        }
    }

    /** True if the terminal answered the kitty keyboard query, so the protocol is in use. */
    public function hasKittyKeyboard(): bool
    {
        return $this->kitty;
    }

    /**
     * Draw what changed now instead of at the next frame. Frames are drawn by themselves; call
     * this when the screen must be up to date at a given moment (before a slow operation, in tests).
     */
    public function refresh(): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }
        $this->drawing = true;
        try {
            $root->flush();
            // the version first: changes made while the rows are read are drawn next frame
            $version = $root->version();
            $out = $this->inline ? $this->drawInline($root) : $this->drawFull($root);
            $this->drawnVersion = $version;
            $out .= $this->link() . $this->mouseModes($root);
            $cursor = $root->activeCursor();
            if ($this->inline) {
                // shown where the root shows it inside the region, else waiting on the line under it
                $cursor = $cursor->visible && $cursor->y < $this->region
                    ? $cursor
                    : new Cursor(0, $this->region, false, $cursor->shape, $cursor->blinking);
            }
            $shown = $this->shown;
            // (inline, the cursor may have to move although nothing was drawn: rows that left
            // the top moved the region down, and line feeds make its rows)
            $move = $this->move($cursor->x, $cursor->y);
            if ($out === '' && $move === '' && $shown == $cursor) {
                return;
            }
            // the cursor is hidden while drawing, so it does not flicker over the screen
            $visible = $shown !== null && $shown->visible;
            $frame = "\e[?2026h";
            if ($visible && ($out !== '' || !$cursor->visible)) {
                $frame .= "\e[?25l";
                $visible = false;
            }
            $frame .= $out . $move;
            if ($shown === null || $shown->shape !== $cursor->shape || $shown->blinking !== $cursor->blinking) {
                $frame .= "\e[" . (['block' => 1, 'underline' => 3, 'bar' => 5][$cursor->shape] + ($cursor->blinking ? 0 : 1)) . ' q';
            }
            if ($cursor->visible && !$visible) {
                $frame .= "\e[?25h";
            }
            $this->shown = $cursor;
            $this->send($frame . "\e[?2026l");
        } finally {
            $this->drawing = false;
            $this->damaged = false;
            $this->lastFrame = microtime(true);
            if ($this->frameAgain) {
                $this->frameAgain = false;
                $this->damage();
            }
        }
    }

    /** Full screen: the damaged rows of the root, against the previous frame (all of them after a resize). */
    private function drawFull(Surface $root): string
    {
        $out = '';
        if ($this->redraw) {
            $this->redraw = false;
            $this->frame = [];
            $this->pen = Style::plain();
            $this->at = [0, 0];
            $out .= "\e[0m\e[H\e[2J";
            $damaged = range(0, $root->rows() - 1);
        } else {
            $damaged = $root->damagedSince($this->drawnVersion);
        }
        foreach ($damaged as $y) {
            [$chars, $styles] = $root->rowData($y);
            $out .= $this->drawRow($y, $chars, $styles);
        }
        return $out;
    }

    /**
     * Inline: the rows that left the root's top and the printed lines, once, above the region;
     * then the region, as tall as the root's content, against the previous frame.
     */
    private function drawInline(Surface $root): string
    {
        $out = '';
        if ($this->redraw) {
            // (the first frame, or a width change: what the region showed is erased, drawn anew)
            $this->redraw = false;
            if ($this->frame !== []) {
                $out .= $this->move(0, 0) . $this->link() . "\e[0m\e[J";
                $this->pen = Style::plain();
            }
            $this->frame = [];
            $this->region = 0;
        }
        $scrolled = $root->linesScrolled() - $this->scrolledOff;
        $this->scrolledOff = $root->linesScrolled();
        $above = [];
        for ($y = -min($scrolled, $root->scrollbackSize()); $y < 0; $y++) {
            $above[] = $root->rowData($y);
        }
        array_push($above, ...$this->printed);
        $this->printed = [];
        if ($above !== []) {
            // written over the top of the region, which moves down under them
            foreach ($above as $y => [$chars, $styles]) {
                $out .= $this->drawRow($y, $chars, $styles);
            }
            $n = \count($above);
            $this->frame = \array_slice($this->frame, $n);
            $this->at[1] -= $n;
            $this->reach -= $n;
            $this->region = max(0, $this->region - $n);
        }
        $height = $this->contentRows($root);
        if ($height < $this->region) {
            $out .= $this->move(0, $height) . $this->link() . "\e[0m\e[J";
            $this->pen = Style::plain();
            $this->frame = \array_slice($this->frame, 0, $height);
        }
        $this->region = $height;
        for ($y = 0; $y < $height; $y++) {
            [$chars, $styles] = $root->rowData($y);
            $out .= $this->drawRow($y, $chars, $styles);
        }
        return $out;
    }

    /**
     * Inline: how many rows of the root the region shows: down to the last row with anything in
     * it (a character, or a colour), or to the cursor when it is shown.
     */
    private function contentRows(Surface $root): int
    {
        $cursor = $root->activeCursor();
        $min = $cursor->visible && !$this->ending ? min($root->rows(), $cursor->y + 1) : 0;
        $plain = Style::plain();
        for ($y = $root->rows() - 1; $y >= $min; $y--) {
            [$chars, $styles] = $root->rowData($y);
            foreach ($chars as $i => $ch) {
                if ($ch !== ' ' || $styles[$i] !== $plain) {
                    return $y + 1;
                }
            }
        }
        return $min;
    }

    /**
     * The cursor to column $x, row $y: absolute (CUP) full screen; relative to where it is in
     * inline mode, where row $y counts from the region's top, and rows below the lowest one
     * known to exist are made with line feeds (scrolling the terminal when at its bottom).
     */
    private function move(int $x, int $y): string
    {
        [$ax, $ay] = $this->at;
        if ($ax === $x && $ay === $y) {
            return '';
        }
        $this->at = [$x, $y];
        if (!$this->inline) {
            return Ansi::moveTo($x, $y);
        }
        $out = '';
        if ($y > $this->reach) {
            $out .= self::vertical($ay, $this->reach) . str_repeat("\n", $y - $this->reach);
            $this->reach = $y;
            $ax = -1;       // (a line feed after the last column leaves the column there)
        } else {
            $out .= self::vertical($ay, $y);
        }
        if ($ax !== $x) {
            $out .= $x === 0 ? "\r" : "\e[" . ($x + 1) . 'G';
        }
        return $out;
    }

    private static function vertical(int $from, int $to): string
    {
        return $to > $from ? "\e[" . ($to - $from) . 'B' : ($to < $from ? "\e[" . ($from - $to) . 'A' : '');
    }

    /** OSC 8 ending the hyperlink the terminal writes in, if one is open. */
    private function link(): string
    {
        if ($this->link[0] === '') {
            return '';
        }
        $this->link = ['', ''];
        return Ansi::link();
    }

    /** The mouse modes that changed: motion reports for 1003 below; in inline mode, any reporting only when asked for. */
    private function mouseModes(Surface $root): string
    {
        $out = '';
        $below = Surface::mouseWantedBelow($root);
        if ($this->inline) {
            $mouse = $root->wantsMouse() || $below !== [];
            if ($mouse !== $this->mouse) {
                $this->mouse = $mouse;
                $out .= $mouse ? "\e[?1000h\e[?1002h\e[?1006h" : "\e[?1003l\e[?1002l\e[?1000l\e[?1006l";
                $this->motion = false;
            }
            if (!$mouse) {
                return $out;
            }
        }
        $motion = $root->mode(1003);
        foreach ($below as $s) {
            $motion = $motion || $s->mode(1003);
        }
        if ($motion !== $this->motion) {
            // a program below asked for (or gave up) motion reports without a button held
            $this->motion = $motion;
            // (the mouse modes are one setting: leaving 1003 goes back to button events, 1002)
            $out .= $motion ? "\e[?1003h" : "\e[?1003l\e[?1002h";
        }
        return $out;
    }

    /**
     * The escape sequences that turn row $y of the previous frame into these cells (row $y of
     * the root, or in inline mode a row written above the region).
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     */
    private function drawRow(int $y, array $chars, array $styles): string
    {
        $cols = \count($chars);
        $plain = Style::plain();
        $links = $this->supportsHyperlinks();
        [$oldChars, $oldStyles] = $this->frame[$y] ?? [[], []];
        $this->frame[$y] = [$chars, $styles];
        if ($chars === $oldChars && $styles === $oldStyles) {
            return '';      // (damaged, and the same again)
        }
        $out = '';
        $x = 0;
        while ($x < $cols) {
            if (($chars[$x] ?? ' ') === ($oldChars[$x] ?? ' ') && ($styles[$x] ?? $plain) === ($oldStyles[$x] ?? $plain)) {
                $x++;
                continue;
            }
            // a changed span; unchanged gaps of up to 3 cells are rewritten rather than jumped over
            $start = $x > 0 && ($chars[$x] ?? ' ') === '' ? $x - 1 : $x;
            $end = $x;
            for ($i = $x + 1, $gap = 0; $i < $cols && $gap <= 3; $i++) {
                if (($chars[$i] ?? ' ') === ($oldChars[$i] ?? ' ') && ($styles[$i] ?? $plain) === ($oldStyles[$i] ?? $plain)) {
                    $gap++;
                } else {
                    $gap = 0;
                    $end = $i;
                }
            }
            if (($chars[$end + 1] ?? null) === '') {
                $end++;     // include the second half of a wide character
            }
            $out .= $this->move($start, $y);
            for ($i = $start; $i <= $end; $i++) {
                $ch = $chars[$i] ?? ' ';
                if ($ch === '') {
                    continue;
                }
                $style = $styles[$i] ?? $plain;
                if ($style !== $this->pen) {
                    $out .= $this->sgr($style);
                    $this->pen = $style;
                    if ($links && [$style->link, $style->linkId] !== $this->link) {
                        $this->link = [$style->link, $style->linkId];
                        $out .= Ansi::link($style->link, $style->linkId);
                    }
                }
                $out .= $ch;
            }
            $x = $end + 1;
            // after the last column the cursor waits to wrap: its column is not known (full
            // screen: nor its row; inline, the row is, and moving up or down cancels the wrap)
            $this->at = $x >= $cols ? [-1, $this->inline ? $y : -1] : [$x, $y];
        }
        return $out;
    }

    /** Pass a clipboard request of the root to the terminal (OSC 52). */
    protected function clipboard(string $text): void
    {
        $this->send(Ansi::clipboard($text));
    }

    /**
     * Handle bytes the terminal sent: answers to the device's own query, then keys, mouse,
     * paste and focus events for the root. With $final, a lone ESC at the end is the Escape key.
     */
    protected function receive(string $bytes, bool $final = false): void
    {
        if ($this->root === null) {
            return;
        }
        if ($this->probing !== null) {
            $this->probe .= $bytes;
            if (preg_match('/\e\[\?[\d;]*c/', $this->probe) === 1) {
                $this->endProbe(preg_match('/\e\[\?\d*u/', $this->probe) === 1);
            }
            return;
        }
        $this->deliver($this->decoder->feed($bytes));
        if ($final && $this->decoder->hasPending()) {
            $this->deliver($this->decoder->flush());
        }
    }

    private function endProbe(bool $kitty): void
    {
        if (preg_match('/\eP>\|([^\e\x07]*)(?:\e\\\\|\x07)/', $this->probe, $m)) {
            $this->terminalName = $m[1];
        }
        if (preg_match('/\e\]11;rgb:([0-9a-f]+)\/([0-9a-f]+)\/([0-9a-f]+)(?:\e\\\\|\x07)/i', $this->probe, $m)) {
            // (each channel 1 to 4 hex digits) by its luminance
            [$r, $g, $b] = array_map(fn(string $h) => hexdec($h) / (16 ** \strlen($h) - 1), [$m[1], $m[2], $m[3]]);
            $this->background = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.5 ? 'light' : 'dark';
        }
        $bytes = (string) preg_replace(['/\e\[\?[\d;]*[uc]/', '/\eP>\|[^\e\x07]*(?:\e\\\\|\x07)/', '/\e\]11;[^\e\x07]*(?:\e\\\\|\x07)/'], '', $this->probe);
        $this->probing = null;
        $this->probe = '';
        if ($kitty && $this->root !== null) {
            $this->kitty = true;
            $this->send("\e[>1u");
        }
        [$probed, $this->probed] = [$this->probed, []];
        foreach ($probed as $listener) {
            $listener();
        }
        if ($bytes !== '') {
            $this->receive($bytes, true);
        }
    }

    /**
     * The terminal's background as it told when attached (it answers the OSC 11 query):
     * `'light'` or `'dark'`, null when it did not answer (yet). The themes follow it: a program
     * that sets no theme is drawn in `light` on a light terminal, else in `dark`. The answer
     * comes a moment after `attach()` (it never holds start-up up), and the screen is drawn
     * again in the other theme when it says light. `FakeTerminal` answers as it was made
     * (`background:`).
     */
    public function background(): ?string
    {
        return $this->background;
    }

    /** @internal Call $listener when the terminal has answered what attach() asked (at once when it has). */
    public function whenProbed(\Closure $listener): void
    {
        if ($this->probing === null) {
            $listener();
        } else {
            $this->probed[] = $listener;
        }
    }

    /** @param list<Event> $events */
    private function deliver(array $events): void
    {
        foreach ($events as $event) {
            $this->root?->dispatch($event);
        }
    }

    /** Re-read the terminal's size; resize the root and redraw everything when it changed. */
    protected function resized(): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }
        [$cols, $rows] = $this->size();
        if ($cols === $root->cols() && ($this->inline ? max(1, $rows - 1) : $rows) === $root->rows()) {
            return;
        }
        if (!$this->inline) {
            $root->resize($cols, $rows);
            $this->redraw = true;
            $this->damage();
            return;
        }
        // inline: a terminal made lower keeps the cursor's line (under the region) and pushes
        // the rows above it into its scrollback; the root, made lower too, pushes as many rows
        // off its top. Those rows stay as they were drawn (they are not written again): the
        // region starts below them.
        $lost = max(0, $this->at[1] - ($rows - 1));
        $before = $root->linesScrolled();
        $oldCols = $root->cols();
        $root->resize($cols, max(1, $rows - 1));
        $shift = max($lost, $root->linesScrolled() - $before);
        $this->scrolledOff = $root->linesScrolled();
        $this->frame = \array_slice($this->frame, $shift);
        $this->at[1] -= $shift;
        $this->reach = max(0, $this->at[1]);      // (rows below the cursor's may be gone; line feeds make them again)
        $this->region = max(0, $this->region - $shift);
        if ($cols !== $oldCols) {
            $this->redraw = true;      // a width change: the region is drawn anew
        }
        $this->damage();
    }

    /**
     * @internal Whether the frame loop runs, and if so, ask it for a frame: its refresh renders
     * the components marked for a render first (Component::markDirty() asks this).
     */
    final public function drawsFrames(): bool
    {
        if ($this->root === null || $this->wake === null) {
            return false;
        }
        if ($this->drawing) {
            $this->frameAgain = true;     // (asked during a frame: the next one)
        } else {
            $this->damage();
        }
        return true;
    }

    private function damage(): void
    {
        $this->damaged = true;
        $this->wake?->notify();
    }

    /** The frame loop: draw when damaged, at most 60 times a second. */
    private function frames(): void
    {
        while ($this->root !== null) {
            if (!$this->damaged) {
                $this->wake->wait();
                continue;
            }
            $wait = $this->lastFrame + 1 / 60 - microtime(true);
            if ($wait > 0) {
                sleep($wait);
            }
            if ($this->root === null) {
                break;
            }
            $this->refresh();
            if ($this->root?->needsCompose()) {
                $this->damaged = true;   // a window holds back a synchronized update: look again
            }
        }
    }

    /** The input loop: read, decode, dispatch; a lone ESC after 50 ms is the Escape key. */
    private function inputs(): void
    {
        while ($this->root !== null) {
            $bytes = $this->read($this->decoder->hasPending() ? 0.05 : 0.1);
            if ($this->root === null) {
                break;
            }
            if ($bytes !== '') {
                $this->receive($bytes);
            } elseif ($this->decoder->hasPending()) {
                $this->deliver($this->decoder->flush());
            }
            if ($this->probing !== null && microtime(true) - $this->probing > 1.0) {
                $this->endProbe(false);  // no answer: a terminal without device attributes
            }
            $this->resized();
        }
    }
}
