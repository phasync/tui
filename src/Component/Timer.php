<?php

namespace phasync\Tui\Component;

/**
 * A countdown: `05:00` down to `00:00`, then it calls `onFinish`.
 *
 *     new Timer(300, running: true, onFinish: fn() => Toast::show('Time is up', 'warning'))
 *
 * As a `Stopwatch` (it ticks by itself; Space or a click starts and stops it, `r` resets it to the
 * full time; `play()`, `pause()`, `reset()`), but showing the time left, rounded up so that
 * `00:00` means it is over. When the time runs out it stops, `onFinish($timer)` is called once
 * and the event `finish` emitted; the state `finished` is set until it is reset (starting a
 * finished timer starts it over). `remaining()` is the seconds left; `$duration` (seconds) is a
 * prop. `onTick` is called as for a `Stopwatch`, with the time left.
 *
 * **Style**: `Timer`, with the states `running` and `finished`; states `:focused`, `:disabled`.
 */
class Timer extends Stopwatch
{
    private bool $finished = false;

    /**
     * @param (\Closure(Timer $timer): void)|null $onFinish
     * @param (\Closure(float $remaining, Timer $timer): void)|null $onTick
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public float $duration = 60.0,
        bool $running = false,
        int $precision = 0,
        private readonly ?\Closure $onFinish = null,
        ?\Closure $onTick = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if ($duration <= 0) {
            throw new \InvalidArgumentException('A Timer needs a duration above zero');
        }
        parent::__construct($running, $precision, $onTick === null ? null : fn(float $e, Stopwatch $w) => $onTick(max(0.0, $this->duration - $e), $this), $key, $id, $class, $style, $disabled);
    }

    /** Seconds left (0 when finished). */
    public function remaining(): float
    {
        return max(0.0, $this->duration - $this->elapsed());
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function play(): void
    {
        if ($this->finished) {
            $this->reset();
        }
        parent::play();
    }

    public function reset(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->finished = false;
        $this->setStyleState('finished', false);
        return parent::reset();
    }

    protected function shown(): float
    {
        $unit = 10 ** $this->precision;
        return ceil($this->remaining() * $unit - 1e-9) / $unit;
    }

    protected function ticked(): void
    {
        if (!$this->finished && $this->remaining() <= 0) {
            $this->finished = true;
            $this->setStyleState('finished', true);
            $this->pause();
            $this->accumulated = $this->duration;
            $this->call($this->onFinish, $this);
            $this->emit('finish', $this);
        }
    }
}
