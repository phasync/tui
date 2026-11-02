<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Overlay;
use phasync\Tui\Rect;
use phasync\Tui\Text;

/**
 * Short notifications that appear in a corner of the screen and go away by themselves.
 *
 *     Toast::show('Saved');
 *     Toast::show('Disk almost full', 'warning', timeout: 6);
 *     Toast::show('Upload failed', 'error', position: 'top-right');
 *
 * `show()` can be called from anywhere in a running program (a key handler, a coroutine, a
 * callback): the toasts are one overlay (`Component::overlay()`) on the running root, over
 * everything, which never takes the focus or the keys, and ends when the last toast is gone.
 * Toasts stack, oldest first, in the corner `$position` names (`'bottom-right'` (default),
 * `'bottom-left'`, `'top-right'`, `'top-left'`; the position of the first toast of a stack
 * decides). `$severity` is `'info'`, `'success'`, `'warning'` or `'error'`; `$timeout` is the
 * seconds a toast stays (0: until clicked). A click on a toast dismisses it. A message takes
 * markup, is one row, and is cut with "…" when wider than the screen. `Toast::clear()` removes
 * all. The root is the one `Dialog::open()` uses; pass `$root` for another.
 *
 * **Style**: parts `Toast::message` and `Toast::mark` (the bar at its left) with the state of
 * the severity: `info`, `success`, `warning`, `error`.
 */
class Toast extends Component
{
    private const SIGNS = ['info' => 'ℹ', 'success' => '✔', 'warning' => '⚠', 'error' => '✖'];

    /** @var \WeakMap<Component, Toast>|null the stack on each root */
    private static ?\WeakMap $stacks = null;

    /** @var list<array{string, string, float}> message, severity, expiry (microtime; INF: stays) */
    private array $toasts = [];
    private ?Overlay $overlay = null;

    /** @param string $position one of `bottom-right`, `bottom-left`, `top-right`, `top-left` */
    private function __construct(private readonly string $position)
    {
    }

    /**
     * Show a notification.
     *
     * @param 'info'|'success'|'warning'|'error' $severity
     * @param 'bottom-right'|'bottom-left'|'top-right'|'top-left' $position
     * @throws \InvalidArgumentException for an unknown severity or position
     * @throws \LogicException when no program is running to show it
     */
    public static function show(string $message, string $severity = 'info', float $timeout = 3.0, string $position = 'bottom-right', ?Component $root = null): void
    {
        if (!isset(self::SIGNS[$severity])) {
            throw new \InvalidArgumentException("Unknown severity '$severity': use info, success, warning or error");
        }
        if (!\in_array($position, ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)) {
            throw new \InvalidArgumentException("Unknown position '$position'");
        }
        $root ??= Component::runningRoot() ?? throw new \LogicException('Toast::show() needs a running program');
        self::$stacks ??= new \WeakMap();
        $stack = self::$stacks[$root] ?? null;
        if ($stack === null || $stack->overlay === null || !$stack->overlay->isOpen()) {
            $stack = self::$stacks[$root] = new self($position);
            $stack->add($message, $severity, $timeout);
            $far = 1 << 20;
            $anchor = new Rect(str_ends_with($position, 'right') ? $far : -$far, str_starts_with($position, 'top') ? -$far : $far, 1, 1);
            // (anchored at the corner, the overlay is placed against it; it must not keep the focus it is given)
            $stack->overlay = $root->overlay($stack, $anchor, str_starts_with($position, 'top') ? 'below' : 'above');
            $stack->overlay->focusBefore?->focus();
            return;
        }
        $stack->add($message, $severity, $timeout);
    }

    /** Remove every toast on the running root (or on `$root`). */
    public static function clear(?Component $root = null): void
    {
        $root ??= Component::runningRoot();
        if ($root !== null) {
            (self::$stacks[$root] ?? null)?->overlay?->close();
        }
    }

    private function add(string $message, string $severity, float $timeout): void
    {
        $this->toasts[] = [str_replace(["\r", "\n"], ' ', $message), $severity, $timeout > 0 ? microtime(true) + $timeout : INF];
        $this->requestRender();
    }

    protected function run(): void
    {
        while ($this->sleep(0.1)) {
            $now = microtime(true);
            $this->toasts = array_values(array_filter($this->toasts, static fn(array $t) => $t[2] > $now));
            if ($this->toasts === []) {
                $this->overlay?->close();
                return;
            }
        }
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT && isset($this->toasts[$mouse->y])) {
            array_splice($this->toasts, $mouse->y, 1);
            if ($this->toasts === []) {
                $this->overlay?->close();
            }
            $this->requestRender();
        }
        return true;
    }

    /** @return list<string> the text of each toast: sign and message */
    private function lines(): array
    {
        return array_map(static fn(array $t) => ' ' . self::SIGNS[$t[1]] . ' ' . Markup::plain($t[0]) . ' ', $this->toasts);
    }

    public function measure(int $cols, int $rows): ?array
    {
        $widest = max([1, ...array_map(Text::width(...), $this->lines())]);
        return [min($cols, $widest + 1), min($rows, \count($this->toasts))];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $out = [];
        foreach ($this->toasts as [$message, $severity]) {
            $line = ' ' . self::SIGNS[$severity] . ' ' . $this->markup($message, 'message', $severity) . ' ';
            $body = Ansi::style($this->style('message', $severity)->text) . $line;
            $out[] = Ansi::style($this->style('mark', $severity)->text) . '▌' . Text::truncate($body, $cols - 1) . "\e[K";
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . implode("\r\n", $out));
        return null;
    }
}
