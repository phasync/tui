<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Signal;

/**
 * The input channel of a Surface: the bytes its producer reads, as a program in a terminal reads
 * its stdin. Key presses, mouse reports and pastes given to the Surface (`Surface::dispatch()`)
 * and the terminal's answers to queries (cursor position, device attributes) arrive here, already
 * encoded the way a terminal program expects them.
 *
 * - A `Pty` attaches itself with `pipe()` and forwards everything to its process.
 * - A coroutine producer (`Surface::run()`) reads with `read()`, which waits for bytes without
 *   blocking other coroutines; decode them with `InputDecoder` if it wants `Key` events.
 *
 * `read()` must be called inside `fiber\run()`. Without a reader or a pipe, at most the newest
 * 1 MiB is kept.
 */
final class Input
{
    private string $buffer = '';
    private bool $closed = false;
    private ?\Closure $sink = null;
    private Signal $signal;

    /** An empty, open channel. Every Surface has one (`Surface::$input`). */
    public function __construct()
    {
        $this->signal = new Signal();
    }

    /** Queue bytes for the producer. Ignored after close(). */
    public function write(string $bytes): void
    {
        if ($this->closed || $bytes === '') {
            return;
        }
        if ($this->sink !== null) {
            ($this->sink)($bytes);
            return;
        }
        $this->buffer .= $bytes;
        if (\strlen($this->buffer) > 1 << 20) {
            $this->buffer = substr($this->buffer, -(1 << 20));
        }
        $this->signal->notify();
    }

    /**
     * Wait until bytes are available and return up to $max of them. Returns '' when the channel
     * is closed and empty, or when $timeout seconds pass without input.
     */
    public function read(int $max = 8192, float $timeout = \PHP_FLOAT_MAX): string
    {
        $deadline = $timeout === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : microtime(true) + $timeout;
        while ($this->buffer === '' && !$this->closed) {
            $left = $deadline === \PHP_FLOAT_MAX ? \PHP_FLOAT_MAX : $deadline - microtime(true);
            if ($left <= 0 || !$this->signal->wait($left)) {
                return '';
            }
        }
        $out = substr($this->buffer, 0, $max);
        $this->buffer = (string) substr($this->buffer, \strlen($out));
        return $out;
    }

    /** The bytes waiting to be read, without removing them. */
    public function peek(): string
    {
        return $this->buffer;
    }

    /**
     * Deliver input straight to $sink instead of buffering it (what a Pty does). Bytes already
     * buffered are delivered at once. Pass null to go back to buffering.
     *
     * @param (\Closure(string $bytes): void)|null $sink
     */
    public function pipe(?\Closure $sink): void
    {
        $this->sink = $sink;
        if ($sink !== null && $this->buffer !== '') {
            $bytes = $this->buffer;
            $this->buffer = '';
            $sink($bytes);
        }
    }

    /** End the channel: readers get the remaining bytes, then ''. Further writes are ignored. */
    public function close(): void
    {
        $this->closed = true;
        $this->signal->notify();
    }

    /** True after `close()`. */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
