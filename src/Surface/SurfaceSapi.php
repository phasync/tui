<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Surface;

use function fiber\run;

/**
 * @internal Routes a coroutine's output into a Surface with ext-phasync's `phasync\ext\sapi()`,
 * the one place in the library that names phasync (guarded by Surface::canCaptureOutput()).
 *
 * sapi() takes an object with name(), output() and send_headers(); output() returns the stream
 * that echo writes to. That stream is opened on the small stream wrapper below, which hands
 * every write to Surface::write().
 */
final class SurfaceSapi
{
    private const PROTOCOL = 'phasync-tui-surface';

    /** @var array<int, Surface> */
    public static array $surfaces = [];

    /** @var resource|null */
    private $stream = null;

    /** Stream wrapper context, set by PHP. */
    public $context;
    private ?Surface $target = null;

    public function __construct(private readonly ?Surface $surface = null)
    {
    }

    /** Run $code with its output going to $surface; coroutines it starts finish first. */
    public static function capture(Surface $surface, \Closure $code): mixed
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $id = spl_object_id($surface);
        self::$surfaces[$id] = $surface;
        $sapi = new self($surface);
        try {
            return \phasync\ext\sapi(static fn() => run($code, $surface), $sapi);
        } finally {
            if ($sapi->stream !== null) {
                fclose($sapi->stream);
            }
            unset(self::$surfaces[$id]);
        }
    }

    public function name(): string
    {
        return \PHP_SAPI;
    }

    /** @return resource */
    public function output()
    {
        return $this->stream ??= fopen(self::PROTOCOL . '://' . spl_object_id($this->surface), 'w');
    }

    public function send_headers(): bool
    {
        return true;
    }

    // --- stream wrapper ---------------------------------------------------------------------

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $id = (int) substr($path, \strlen(self::PROTOCOL) + 3);
        $this->target = self::$surfaces[$id] ?? null;
        return $this->target !== null;
    }

    public function stream_write(string $data): int
    {
        $this->target?->write($data);
        return \strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->target = null;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
