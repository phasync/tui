<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A month, to look at or to pick a date from: 
 *
 *     new Calendar(date: '2026-11-02', onSelect: fn(string $date) => ...)
 *
 * Arrows move the highlighted day (across month ends), Page Up/Down the month, Home/End the first and last
 * day of the month, `t` today; Enter or Space picks the highlighted day (`onSelect('Y-m-d')`,
 * event `select`), as does a click on a day; the arrows in the title (`‹` `›`) click to the
 * neighbouring months. Weeks start on Monday unless `sundayFirst`. `$marked` is a list of
 * 'Y-m-d' dates drawn emphasised (events). In a `Form`, its value is `value()`, the picked date.
 * 20 columns by 8 rows.
 *
 * **Style**: `Calendar`, parts `Calendar::title`, `Calendar::weekday`, `Calendar::day`
 * (states `today`, `marked`, `current`, `chosen`, `outside`).
 */
class Calendar extends Component implements Field
{
    private \DateTimeImmutable $shown;
    private \DateTimeImmutable $current;
    private ?string $chosen = null;

    /**
     * @param list<string> $marked
     * @param (\Closure(string $date, Calendar $calendar): void)|null $onSelect
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        ?string $date = null,
        public array $marked = [],
        public bool $sundayFirst = false,
        private readonly ?\Closure $onSelect = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->current = new \DateTimeImmutable($date ?? 'today');
        $this->current = $this->current->setTime(0, 0);
        $this->shown = $this->current->modify('first day of this month');
    }

    protected function bindings(): array
    {
        return [
            'left' => 'dayBefore', 'right' => 'dayAfter', 'up' => 'weekBefore', 'down' => 'weekAfter',
            'pageup' => 'monthBefore', 'pagedown' => 'monthAfter', 'home' => 'monthStart', 'end' => 'monthEnd',
            't' => 'today', 'enter' => 'pick', 'space' => 'pick',
        ];
    }

    /** The highlighted day as 'Y-m-d'. */
    public function current(): string
    {
        return $this->current->format('Y-m-d');
    }

    /** The picked date as 'Y-m-d', null before one was picked. */
    public function value(): ?string
    {
        return $this->chosen;
    }

    /** Highlight a date and show its month. */
    public function setCurrent(string $date): void
    {
        $this->current = (new \DateTimeImmutable($date))->setTime(0, 0);
        $this->shown = $this->current->modify('first day of this month');
        $this->requestRender();
    }

    public function days(int $n): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->setCurrent($this->current->modify(($n >= 0 ? '+' : '') . "$n days")->format('Y-m-d'));
        return true;
    }

    public function months(int $n): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $first = $this->current->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . "$n month");
        $day = min((int) $this->current->format('j'), (int) $first->format('t'));
        $this->setCurrent($first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d'));
        return true;
    }

    public function dayBefore(): bool { return $this->days(-1); }
    public function dayAfter(): bool { return $this->days(1); }
    public function weekBefore(): bool { return $this->days(-7); }
    public function weekAfter(): bool { return $this->days(7); }
    public function monthBefore(): bool { return $this->months(-1); }
    public function monthAfter(): bool { return $this->months(1); }
    public function monthStart(): bool { $this->setCurrent($this->current->modify('first day of this month')->format('Y-m-d')); return true; }
    public function monthEnd(): bool { $this->setCurrent($this->current->modify('last day of this month')->format('Y-m-d')); return true; }
    public function today(): bool { $this->setCurrent('today'); return true; }

    public function pick(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->chosen = $this->current();
        $this->call($this->onSelect, $this->chosen, $this);
        $this->emit('select', $this);
        $this->requestRender();
        return true;
    }

    /** The first day shown in the grid (a Monday, or a Sunday with sundayFirst). */
    private function gridStart(): \DateTimeImmutable
    {
        $dow = (int) $this->shown->format('N');          // 1 Monday .. 7 Sunday
        $back = $this->sundayFirst ? $dow % 7 : $dow - 1;
        return $this->shown->modify("-$back days");
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            if ($mouse->y === 0) {
                $this->months($mouse->x < 10 ? -1 : 1);
            } elseif ($mouse->y >= 2 && $mouse->x < 20) {
                $day = $this->gridStart()->modify('+' . (($mouse->y - 2) * 7 + intdiv($mouse->x, 3)) . ' days');
                $this->setCurrent($day->format('Y-m-d'));
                $this->pick();
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, 20), min($rows, 8)];
    }

    protected function render(): null
    {
        $focused = $this->hasFocus();
        $base = Ansi::style($this->style()->text);
        $title = '‹' . Text::pad($this->shown->format('F Y'), 18, 'center') . '›';
        $out = [Ansi::style($this->style('title')->text) . $title];
        $names = $this->sundayFirst ? ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] : ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        $out[] = Ansi::style($this->style('weekday')->text) . implode(' ', $names);
        $day = $this->gridStart();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        for ($week = 0; $week < 6; $week++) {
            $line = '';
            for ($d = 0; $d < 7; $d++) {
                $ymd = $day->format('Y-m-d');
                $states = array_keys(array_filter([
                    'outside' => $day->format('n') !== $this->shown->format('n'),
                    'today' => $ymd === $today,
                    'marked' => \in_array($ymd, $this->marked, true),
                    'chosen' => $ymd === $this->chosen,
                    'current' => $focused && $ymd === $this->current(),
                ]));
                $line .= Ansi::style($this->style('day', ...$states)->text) . str_pad($day->format('j'), 2, ' ', STR_PAD_LEFT) . $base . ($d < 6 ? ' ' : '');
                $day = $day->modify('+1 day');
            }
            $out[] = $line;
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . $base . implode("\r\n", $out));
        return null;
    }
}
