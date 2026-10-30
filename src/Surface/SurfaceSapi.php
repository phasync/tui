<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Surface;

use function fiber\run;

/**
 * @internal Routes a coroutine's output into a Surface with ext-phasync's `phasync\ext\sapi()`,
 * the one place in the library that names phasync (guarded by Surface::canCaptureOutput()).
 *
 * sapi() takes an object with name(), write() and send_headers() (ext-phasync 2.0.7 and later);
 * PHP's output layer hands every piece of `echo`/`print`/`printf` output to write().
 */
final class SurfaceSapi
{
    /** The first ext-phasync whose sapi() takes a write() method (older ones took output()). */
    public const MIN_EXT_VERSION = '2.0.7';

    private function __construct(private readonly Surface $surface)
    {
    }

    /** Run $code with its output going to $surface; coroutines it starts finish first. */
    public static function capture(Surface $surface, \Closure $code): mixed
    {
        return \phasync\ext\sapi(static fn() => run($code, $surface), new self($surface));
    }

    public function name(): string
    {
        return \PHP_SAPI;
    }

    public function write(string $data): bool
    {
        $this->surface->write($data);
        return true;
    }

    public function send_headers(): bool
    {
        return true;
    }
}
