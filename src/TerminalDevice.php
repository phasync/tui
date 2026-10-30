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
 *   SGR encoding), bracketed paste and focus reporting on, and the kitty keyboard protocol
 *   (disambiguated keys) pushed when the terminal answers its query; `detach()` undoes all of it.
 *
 * `attach()`, `detach()` and `wait()` must be called inside `fiber\run()`.
 */
abstract class TerminalDevice
{
    /** What `attach()` sends: alternate screen, cursor hidden, mouse, paste and focus reporting. */
    private const TAKEOVER = "\e[?1049h\e[?25l\e[?1000h\e[?1002h\e[?1006h\e[?2004h\e[?1004h";
    /** What `detach()` sends to undo it (`Tty` also sends it when the program dies). */
    private const RESTORE = "\e[0m\e[<u\e[?1004l\e[?2004l\e[?1006l\e[?1002l\e[?1000l\e[0 q\e[?25h\e[?1049l";

    private ?Surface $root = null;
    private InputDecoder $decoder;
    private ?Signal $wake = null;
    private ?Signal $detached = null;
    private bool $damaged = false;
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
     * Show $root on this terminal and send it the terminal's input, until `detach()`. The root is
     * resized to the terminal's size at once and whenever the terminal is resized.
     *
     * @throws \LogicException when already attached
     */
    public function attach(Surface $root): void
    {
        if ($this->root !== null) {
            throw new \LogicException('This terminal already shows a surface; detach() it first');
        }
        $this->decoder = new InputDecoder();
        $this->wake = new Signal();
        $this->detached ??= new Signal();
        $this->frame = [];
        $this->redraw = true;
        $this->damaged = true;
        $this->pen = null;
        $this->shown = null;
        $this->kitty = false;
        $this->open(self::RESTORE);
        $this->root = $root;
        [$cols, $rows] = $this->size();
        $root->resize($cols, $rows);
        $this->offs = [
            $root->on('change', function (): void {
                if (!$this->drawing) {
                    $this->damage();
                }
            }),
            $root->on('title', fn(string $title) => $this->send(Ansi::title($title))),
            $root->on('bell', fn() => $this->send("\x07")),
            $root->on('clipboard', fn(string $text) => $this->clipboard($text)),
        ];
        // ask for the kitty keyboard protocol; the answer to primary device attributes ends the probe
        $this->probing = microtime(true);
        $this->probe = '';
        $this->send(self::TAKEOVER . ($root->title() !== '' ? Ansi::title($root->title()) : '') . "\e[?u\e[c");
        go($this->frames(...));
        go($this->inputs(...));
    }

    /**
     * Stop showing the root and give the terminal back: modes off, main screen, cursor shown,
     * terminal settings restored. The device's coroutines end. Safe to call when not attached.
     */
    public function detach(): void
    {
        if ($this->root === null) {
            return;
        }
        foreach ($this->offs as $off) {
            $off();
        }
        $this->offs = [];
        $this->root = null;
        $this->wake?->notify();
        $this->close();
        $this->detached?->notify();
    }

    /** True between `attach()` and `detach()`. */
    public function isAttached(): bool
    {
        return $this->root !== null;
    }

    /** The surface shown, or null when not attached. */
    public function root(): ?Surface
    {
        return $this->root;
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
            $root->compose();
            $out = '';
            if ($this->redraw) {
                $this->redraw = false;
                $this->frame = [];
                $this->pen = Style::plain();
                $out .= "\e[0m\e[H\e[2J";
                $damaged = range(0, $root->rows() - 1);
            } else {
                $damaged = $root->damagedSince($this->drawnVersion);
            }
            $this->drawnVersion = $root->version();
            $at = [-1, -1];
            foreach ($damaged as $y) {
                $out .= $this->drawRow($y, $root, $at);
            }
            $cursor = $root->activeCursor();
            $shown = $this->shown;
            if ($out === '' && $shown == $cursor) {
                return;
            }
            // the cursor is hidden while drawing, so it does not flicker over the screen
            $visible = $shown !== null && $shown->visible;
            $frame = "\e[?2026h";
            if ($visible && ($out !== '' || !$cursor->visible)) {
                $frame .= "\e[?25l";
                $visible = false;
            }
            $frame .= $out . Ansi::moveTo($cursor->x, $cursor->y);
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
        }
    }

    /**
     * The escape sequences that turn row $y of the previous frame into the root's row $y.
     *
     * @param array{int,int} $at where the terminal's cursor is, [-1,-1] when unknown
     */
    private function drawRow(int $y, Surface $root, array &$at): string
    {
        [$chars, $styles] = $root->rowData($y);
        $cols = $root->cols();
        $plain = Style::plain();
        [$oldChars, $oldStyles] = $this->frame[$y] ?? [[], []];
        $this->frame[$y] = [$chars, $styles];
        $same = static fn(int $i): bool => ($chars[$i] ?? ' ') === ($oldChars[$i] ?? ' ')
            && ($styles[$i] ?? $plain) === ($oldStyles[$i] ?? $plain);
        $out = '';
        $x = 0;
        while ($x < $cols) {
            if ($same($x)) {
                $x++;
                continue;
            }
            // a changed span; unchanged gaps of up to 3 cells are rewritten rather than jumped over
            $start = $x > 0 && ($chars[$x] ?? ' ') === '' ? $x - 1 : $x;
            $end = $x;
            for ($i = $x + 1, $gap = 0; $i < $cols && $gap <= 3; $i++) {
                if ($same($i)) {
                    $gap++;
                } else {
                    $gap = 0;
                    $end = $i;
                }
            }
            if (($chars[$end + 1] ?? null) === '') {
                $end++;     // include the second half of a wide character
            }
            if ($at !== [$start, $y]) {
                $out .= Ansi::moveTo($start, $y);
            }
            for ($i = $start; $i <= $end; $i++) {
                $ch = $chars[$i] ?? ' ';
                if ($ch === '') {
                    continue;
                }
                $style = $styles[$i] ?? $plain;
                if ($style !== $this->pen) {
                    $out .= Ansi::style($style);
                    $this->pen = $style;
                }
                $out .= $ch;
            }
            $x = $end + 1;
            // after the last column the cursor waits to wrap: its position is not known
            $at = $x >= $cols ? [-1, -1] : [$x, $y];
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
        $bytes = (string) preg_replace('/\e\[\?[\d;]*[uc]/', '', $this->probe);
        $this->probing = null;
        $this->probe = '';
        if ($kitty && $this->root !== null) {
            $this->kitty = true;
            $this->send("\e[>1u");
        }
        if ($bytes !== '') {
            $this->receive($bytes, true);
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
        if ($cols !== $root->cols() || $rows !== $root->rows()) {
            $root->resize($cols, $rows);
            $this->redraw = true;
            $this->damage();
        }
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
