<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * Page N of M, to step through pages of something: `‹ 1 … 4 5 6 … 20 ›`, `‹ ● ○ ○ ○ ›` or `‹ 3 / 20 ›`.
 *
 *     new Paginator(pages: 20, page: 1, onChange: fn(int $page) => $this->load($page))
 *     new Paginator(5, display: 'dots')
 *
 * Pages count from 1. Left/Right (or h/l) and Page Up/Down step, Home/End go to the first and
 * last page; a key that cannot step (on the first or last page) goes on to the next field. A click
 * on a number or a dot goes there, a click on `‹` or `›` steps. `$display` is `'numbers'`
 * (first, last and the pages around the current one; all of them when there are seven or
 * fewer), `'dots'` or `'text'`. `onChange($page, $paginator)` is called after every change
 * and the event `change` emitted. In a `Form`, its value is `value()` (the page). One row; as
 * wide as what it shows. `$pages` and `$display` are props; the page is kept when `$pages` shrinks below it.
 *
 * **Style**: `Paginator`, parts `Paginator::page` (state `current`), `Paginator::arrow` (state
 * `disabled` at the ends); states `:focused`, `:disabled`.
 */
class Paginator extends Component implements Field
{
    private int $page;
    /** @var list<array{int, int, int|null}> from column, to column (exclusive), the page a click there goes to */
    private array $hits = [];

    /**
     * @param int $pages   how many pages there are (at least 1)
     * @param int $page    the first page, from 1
     * @param 'numbers'|'dots'|'text' $display
     * @param (\Closure(int $page, Paginator $paginator): void)|null $onChange
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public int $pages = 1,
        int $page = 1,
        public string $display = 'numbers',
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if ($pages < 1 || !\in_array($display, ['numbers', 'dots', 'text'], true)) {
            throw new \InvalidArgumentException("A Paginator needs at least one page and display 'numbers', 'dots' or 'text'");
        }
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->page = max(1, min($pages, $page));
    }

    protected function bindings(): array
    {
        return [
            'left' => 'previous', 'h' => 'previous', 'pageup' => 'previous',
            'right' => 'next', 'l' => 'next', 'pagedown' => 'next',
            'home' => 'first', 'end' => 'last',
        ];
    }

    /** The current page, from 1. */
    public function page(): int
    {
        return $this->page;
    }

    /** The current page, the value in a `Form`. */
    public function value(): int
    {
        return $this->page;
    }

    /** Go to a page (limited to 1..pages; calls `$onChange` when that changed it). */
    public function setPage(int $page): void
    {
        $page = max(1, min($this->pages, $page));
        if ($page === $this->page) {
            return;
        }
        $this->page = $page;
        $this->call($this->onChange, $page, $this);
        $this->emit('change', $this);
        $this->requestRender();
    }

    private function goTo(int $to): bool
    {
        $to = max(1, min($this->pages, $to));
        if ($this->attribute('disabled') || $to === $this->page) {
            return false;
        }
        $this->setPage($to);
        return true;
    }

    public function previous(): bool { return $this->goTo($this->page - 1); }
    public function next(): bool { return $this->goTo($this->page + 1); }
    public function first(): bool { return $this->goTo(1); }
    public function last(): bool { return $this->goTo($this->pages); }

    /**
     * What is shown, left to right: [text, page a click goes to (null: nothing), part, current].
     *
     * @return list<array{string, int|null, string, bool}>
     */
    private function segments(): array
    {
        $pages = $this->pages;
        $page = min($this->page, $pages);
        $segments = [['‹', $page - 1 >= 1 ? $page - 1 : null, 'arrow', false]];
        if ($this->display === 'text') {
            $segments[] = ["$page / $pages", null, 'page', true];
        } elseif ($this->display === 'dots') {
            for ($p = 1; $p <= $pages; $p++) {
                $segments[] = [$p === $page ? '●' : '○', $p, 'page', $p === $page];
            }
        } else {
            $shown = $pages <= 7 ? range(1, $pages) : array_values(array_unique(array_filter(
                [1, $page - 1, $page, $page + 1, $pages],
                static fn(int $p) => $p >= 1 && $p <= $pages,
            )));
            $before = 0;
            foreach ($shown as $p) {
                if ($before && $p - $before === 2) {
                    $segments[] = [(string) ($before + 1), $before + 1, 'page', false];
                } elseif ($before && $p - $before > 2) {
                    $segments[] = ['…', null, 'page', false];
                }
                $segments[] = [$p === $page ? " $p " : (string) $p, $p, 'page', $p === $page];
                $before = $p;
            }
        }
        $segments[] = ['›', $page + 1 <= $pages ? $page + 1 : null, 'arrow', false];
        return $segments;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            foreach ($this->hits as [$from, $to, $page]) {
                if ($mouse->x >= $from && $mouse->x < $to && $page !== null) {
                    $this->setPage($page);
                    break;
                }
            }
        }
        return true;
    }

    public function measure(int $cols, int $rows): ?array
    {
        $width = 0;
        foreach ($this->segments() as $s) {
            $width += Text::width($s[0]) + 1;
        }
        return [min($cols, $width - 1), 1];
    }

    protected function render(): null
    {
        $this->page = min($this->page, $this->pages);
        $line = '';
        $this->hits = [];
        $x = 0;
        foreach ($this->segments() as [$text, $target, $part, $current]) {
            $states = $current ? ['current'] : ($part === 'arrow' && $target === null ? ['disabled'] : []);
            $line .= ($line === '' ? '' : Ansi::style($this->style()->text) . ' ') . Ansi::style($this->style($part, ...$states)->text) . $text;
            $x += ($x ? 1 : 0);
            $this->hits[] = [$x, $x + Text::width($text), $target];
            $x += Text::width($text);
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . Text::truncate($line, $this->surface->cols()));
        return null;
    }
}
