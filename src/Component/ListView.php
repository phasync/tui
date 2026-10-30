<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * A list of rows with one selected, scrolling to keep it in view: a member list, a file tree, a
 * process list, a pick list.
 *
 *     $files = $this->child('files', fn() => new ListView(
 *         items: ['a.txt' => 'a.txt', 'src' => "\e[34msrc/\e[0m"],
 *         onSelect: fn(string $key) => $this->preview($key),
 *         onActivate: fn(string $key) => $this->open($key),
 *     ));
 *     $files->setItems($newItems);             // the selection stays on the same key
 *
 * Items are labels by key (a list, or keys of your own such as file names or pids); a label is
 * one row of text and may carry escape sequences for colours. A label wider than the list is
 * cut with "…". The selected row is drawn in `$selectedStyle` (inverse video by default) over
 * the label's own colours.
 *
 * Keys: Up/Down (and Ctrl-P/Ctrl-N), Page Up/Page Down, Home/End move the selection; Enter
 * activates the selected item. The mouse: a click selects a row (a click on the selected row
 * activates it), the wheel scrolls. `$onSelect` is called whenever the selection changes,
 * `$onActivate` on Enter or the second click; both receive the key, the label and the list.
 */
final class ListView extends Component
{
    /** @var list<int|string> the keys in order */
    private array $keys = [];
    /** @var list<string> the labels in order */
    private array $labels = [];
    private ?int $index = null;
    private int $top = 0;
    /** The wheel moved the view away from the selection; the next move brings it back. */
    private bool $free = false;
    private Style $selectedStyle;

    /**
     * @param array<int|string, string> $items label by key, in order
     * @param (\Closure(int|string $key, string $label, ListView $list): void)|null $onSelect   the selection changed
     * @param (\Closure(int|string $key, string $label, ListView $list): void)|null $onActivate Enter on the selection
     * @param Style|null                $selectedStyle how the selected row is drawn (default: inverse video)
     */
    public function __construct(
        array $items = [],
        private readonly ?\Closure $onSelect = null,
        private readonly ?\Closure $onActivate = null,
        ?Style $selectedStyle = null,
    ) {
        $this->selectedStyle = $selectedStyle ?? Style::of(attrs: Style::INVERSE);
        $this->replace($items);
        $this->index = $this->keys === [] ? null : 0;
    }

    /**
     * Replace the items. The selection stays on the item with the same key when there still is
     * one, else on the same position (clamped); `$onSelect` is called if the selected key changed.
     *
     * @param array<int|string, string> $items label by key, in order
     */
    public function setItems(array $items): void
    {
        $selected = $this->selected();
        $this->replace($items);
        $at = $selected === null ? false : array_search($selected, $this->keys, true);
        if ($at !== false) {
            $this->index = $at;
        } elseif ($this->keys === []) {
            $this->index = null;
        } else {
            $this->index = min($this->index ?? 0, \count($this->keys) - 1);
        }
        if ($this->selected() !== $selected) {
            $this->announce($this->onSelect);
        }
        $this->requestRender();
    }

    /** @param array<int|string, string> $items */
    private function replace(array $items): void
    {
        $this->keys = array_keys($items);
        $this->labels = array_map(fn($label) => str_replace(["\r", "\n"], ' ', (string) $label), array_values($items));
    }

    /**
     * The items, label by key, in order.
     *
     * @return array<int|string, string>
     */
    public function items(): array
    {
        return $this->keys === [] ? [] : array_combine($this->keys, $this->labels);
    }

    /** The key of the selected item, or null when the list is empty. */
    public function selected(): int|string|null
    {
        return $this->index === null ? null : $this->keys[$this->index];
    }

    /** The label of the selected item, or null when the list is empty. */
    public function selectedLabel(): ?string
    {
        return $this->index === null ? null : $this->labels[$this->index];
    }

    /**
     * Select the item with key $key and scroll it into view; `$onSelect` is called when the
     * selection changes. Returns false (and changes nothing) when there is no such item.
     */
    public function select(int|string $key): bool
    {
        $at = array_search($key, $this->keys, true);
        if ($at === false) {
            return false;
        }
        $this->moveTo($at);
        return true;
    }

    private function moveTo(int $index): void
    {
        if ($this->keys === []) {
            return;
        }
        $index = max(0, min(\count($this->keys) - 1, $index));
        $this->free = false;
        if ($index !== $this->index) {
            $this->index = $index;
            $this->announce($this->onSelect);
        }
        $this->requestRender();
    }

    private function announce(?\Closure $callback): void
    {
        if ($callback !== null && $this->index !== null) {
            $callback($this->keys[$this->index], $this->labels[$this->index], $this);
        }
    }

    protected function onKey(Key $key): bool
    {
        $page = max(1, $this->surface->rows() - 1);
        $at = $this->index ?? 0;
        match (true) {
            $key->is('up') || $key->is('ctrl+p') => $this->moveTo($at - 1),
            $key->is('down') || $key->is('ctrl+n') => $this->moveTo($at + 1),
            $key->is('pageup') => $this->moveTo($at - $page),
            $key->is('pagedown') => $this->moveTo($at + $page),
            $key->is('home') => $this->moveTo(0),
            $key->is('end') => $this->moveTo(\count($this->keys) - 1),
            $key->is('enter') => $this->announce($this->onActivate),
            default => null,
        };
        return $key->is('up') || $key->is('down') || $key->is('ctrl+p') || $key->is('ctrl+n') || $key->is('pageup')
            || $key->is('pagedown') || $key->is('home') || $key->is('end') || $key->is('enter');
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->isWheel()) {
            $max = max(0, \count($this->keys) - $this->surface->rows());
            $this->top = max(0, min($max, $this->top + ($mouse->action === Mouse::WHEEL_UP ? -3 : 3)));
            $this->free = true;
            $this->requestRender();
            return true;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $at = $this->top + $mouse->y;
            if ($at < \count($this->keys)) {
                $at === $this->index ? $this->announce($this->onActivate) : $this->moveTo($at);
            }
            return true;
        }
        return $mouse->action === Mouse::RELEASE || $mouse->action === Mouse::DRAG;
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        $n = \count($this->keys);
        // keep the selection in view (unless the wheel moved the view away; a move brings it back)
        if ($this->index !== null && !$this->free) {
            if ($this->index < $this->top) {
                $this->top = $this->index;
            } elseif ($this->index >= $this->top + $rows) {
                $this->top = $this->index - $rows + 1;
            }
        }
        $this->top = max(0, min($this->top, max(0, $n - $rows)));
        // the selected style as additions to whatever the label sets (no reset)
        $selected = preg_replace('/^\e\[0;?/', "\e[", Ansi::style($this->selectedStyle));
        $selected = $selected === "\e[m" ? '' : $selected;
        $out = "\e[?25l\e[0m\e[2J";
        for ($y = 0; $y < $rows && $this->top + $y < $n; $y++) {
            $i = $this->top + $y;
            $label = Text::truncate($this->labels[$i], $cols);
            $out .= Ansi::moveTo(0, $y);
            if ($i === $this->index) {
                // the selection's style over the label's own: again after each of its SGRs
                $label = preg_replace('/\e\[[0-9;:]*m/', '$0' . $selected, $label);
                $out .= $selected . $label . str_repeat(' ', max(0, $cols - Text::width($label)));
            } else {
                $out .= $label;
            }
            $out .= "\e[0m";
        }
        $this->surface->write($out);
        return null;
    }
}
