<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A running clock that counts up: `00:12.3`, `1:02:03`.
 *
 *     new Stopwatch(running: true, precision: 1)
 *     $watch->play(); $watch->pause(); $watch->reset(); $watch->elapsed();    // seconds
 *
 * It ticks by itself while it is running (ten times a second with a `precision` of 1 or 2,
 * else twice), no coroutine of yours needed. Space or a click starts and stops it, `r` resets it
 * (and stops it). `precision` is the decimals of the seconds shown (0-2); hours show once it
 * has run an hour. `onTick($elapsed, $watch)` is called at every tick while it runs, and the
 * event `tick` emitted. One row, as wide as the time. A disabled one takes no keys or clicks.
 *
 * **Style**: `Stopwatch`, with the state `running` while it counts; states `:focused`, `:disabled`.
 */
class Stopwatch extends Component
{
    protected float $accumulated = 0.0;
    protected ?float $startedAt = null;
    private bool $ticker = false;

    /**
     * @param (\Closure(float $elapsed, Stopwatch $watch): void)|null $onTick
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        bool $running = false,
        public int $precision = 0,
        protected readonly ?\Closure $onTick = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if ($precision < 0 || $precision > 2) {
            throw new \InvalidArgumentException('The precision of a clock is 0 to 2 decimals');
        }
        $this->with($key, $id, $class, $style, disabled: $disabled);
        if ($running) {
            $this->startedAt = self::now();
            $this->setStyleState('running', true);
        }
    }

    protected function bindings(): array
    {
        return ['space' => 'toggle', 'r' => 'reset'];
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /** Seconds counted so far. */
    public function elapsed(): float
    {
        return $this->accumulated + ($this->startedAt === null ? 0.0 : self::now() - $this->startedAt);
    }

    public function isRunning(): bool
    {
        return $this->startedAt !== null;
    }

    /** Start (or go on) counting (not `start()`: that is the component's). */
    public function play(): void
    {
        if ($this->startedAt === null) {
            $this->startedAt = self::now();
            $this->setStyleState('running', true);
            $this->ticking();
            $this->requestRender();
        }
    }

    /** Stop counting; the time so far stays. */
    public function pause(): void
    {
        if ($this->startedAt !== null) {
            $this->accumulated = $this->elapsed();
            $this->startedAt = null;
            $this->setStyleState('running', false);
            $this->requestRender();
        }
    }

    /** Stop and set the time back to zero. */
    public function reset(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->pause();
        $this->accumulated = 0.0;
        $this->requestRender();
        return true;
    }

    /** Start it when stopped, stop it when running (Space). */
    public function toggle(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->isRunning() ? $this->pause() : $this->play();
        return true;
    }

    /** $seconds as `MM:SS`, `MM:SS.d`/`.dd`, or `H:MM:SS` from an hour on. */
    public static function format(float $seconds, int $precision = 0): string
    {
        $unit = 10 ** $precision;
        $total = (int) floor(max(0.0, $seconds) * $unit + 1e-9);
        $fraction = $total % $unit;
        $s = intdiv($total, $unit);
        $time = $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
        return $precision > 0 ? $time . '.' . str_pad((string) $fraction, $precision, '0', \STR_PAD_LEFT) : $time;
    }

    /** The time as shown: the elapsed time here, the time left in a `Timer`. */
    protected function shown(): float
    {
        return $this->elapsed();
    }

    /** Called at every tick while running, after the callbacks: a `Timer` ends here. */
    protected function ticked(): void
    {
    }

    protected function mount(): void
    {
        $this->ticking();
    }

    /** Start the coroutine that ticks while the clock runs (one at a time). */
    private function ticking(): void
    {
        if ($this->ticker || !$this->isRunning() || !$this->isMounted()) {
            return;
        }
        $this->ticker = true;
        $this->go(function (): void {
            try {
                while ($this->isRunning() && $this->sleep($this->precision > 0 ? 0.1 : 0.25)) {
                    $this->call($this->onTick, $this->elapsed(), $this);
                    $this->emit('tick', $this);
                    $this->ticked();
                }
            } finally {
                $this->ticker = false;
            }
        });
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $this->toggle();
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, Text::width(self::format($this->shown(), $this->precision))), 1];
    }

    protected function render(): null
    {
        $this->surface->write("\e[?25l" . \phasync\Tui\Ansi::style($this->style()->text) . "\e[2J\e[H"
            . Text::truncate(self::format($this->shown(), $this->precision), $this->surface->cols()));
        return null;
    }
}
