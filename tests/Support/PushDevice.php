<?php

namespace phasync\Tui\Tests\Support;

use phasync\Tui\TerminalDevice;

use function fiber\sleep;

/**
 * A device with no terminal of its own, as a multiplexer's daemon has: its viewers' bytes are
 * handed to it (`push()`), it asks nothing at attach (`$probe` false), and `read()` only idles.
 */
final class PushDevice extends TerminalDevice
{
    public string $output = '';
    public string $restore = '';

    /** @param array{int, int} $size */
    public function __construct(private array $size = [40, 8])
    {
        $this->probe = false;
        $this->hyperlinks = false;
    }

    protected function open(string $restore): void
    {
        $this->restore = $restore;
    }

    protected function close(): void
    {
    }

    protected function send(string $bytes): void
    {
        $this->output .= $bytes;
    }

    protected function read(float $timeout): string
    {
        sleep($timeout);
        return '';
    }

    public function size(): array
    {
        return $this->size;
    }

    /** Bytes a viewer sent, from whatever coroutine has them. */
    public function push(string $bytes): void
    {
        $this->receive($bytes);
    }

    /** The viewers' terminal is now $cols x $rows. */
    public function resizeTo(int $cols, int $rows): void
    {
        $this->size = [$cols, $rows];
        $this->resized();
    }
}
