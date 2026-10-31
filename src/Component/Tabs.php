<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Layers;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text as TextElement;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A tab bar and one content per tab: panes of a workspace, open files, shells.
 *
 *     $tabs = $this->child('tabs', fn() => new Tabs([
 *         'shell' => ['label' => 'Shell', 'content' => new Terminal('sh')],
 *         'log'   => ['label' => 'Log',   'content' => new Log()],
 *         'help'  => ['label' => 'Help',  'content' => new Markdown($help), 'closable' => true],
 *     ], hints: true, onChange: fn(string $key) => ...));
 *     $tabs->setTabs([...$more]);          // add, remove and rename at runtime; the current tab stays by key
 *
 * Each tab is `key => ['label' => ..., 'content' => Component|Element, 'closable' => ?bool]`. A
 * content that is a component, and every component in a content's element tree
 * (`new Row([$list, new Box($preview)])`), is a child kept alive while its tab is hidden (see
 * `Component::keep()`): it keeps its state, and a `Terminal` goes on running and receiving
 * output (it starts when its tab is first shown, as it is sized then); it is stopped when its
 * tab goes. A component made elsewhere (with the owner's `child()`) stays its owner's, which
 * keeps finding it with `child()` while the tab holds it; `content()` gives a tab's content.
 * An element is built into the tree while its tab is shown. Hidden tabs are not laid out.
 *
 * **Focus.** When the tab changes while the focus is in the content (Ctrl-Tab, Alt-number,
 * `select()`), it follows into the new tab's content: to its first component that takes keys,
 * else its first component, whatever elements wrap it; a content with no component gives it
 * to the bar.
 *
 * **Keys.** On the bar: Left/Right (Home/End) change tab, Tab or Enter move the focus into the
 * shown content, Delete closes the current tab when it is closable. In the content: Escape
 * moves the focus back to the bar (when the content did not take it; a `Terminal` keeps its
 * Escape): a plain binding, which comes after the content. Everywhere in the tabs, before the
 * content sees them: Ctrl-Tab and Ctrl-Shift-Tab (around; bindings marked `first`), Alt-1 to
 * Alt-9 (in `onKey()`, which sees keys on their way to the content). **Mouse**: a click on a tab
 * selects it and focuses the bar, on its
 * `×` closes it, a middle click closes it, the wheel on the bar changes tab, `‹` and `›` step
 * to the previous or next one. When the labels are wider than the bar it scrolls, with `‹` and
 * `›` at its ends, and the current tab is always in view.
 *
 * Closing (the `×`, a middle click, Delete): `$onClose($key, $tabs)` when given, which decides
 * (call `close($key)` or `setTabs()`); else the tab is removed and its component disposed.
 * Events: `change` (with the key and the tabs) when the current tab changed, `close` when a tab
 * was asked to close.
 *
 * **Style**: `Tabs`; parts `Tabs::bar` (the row), `Tabs::tab` (states `current`, `hover`,
 * `focused` for the current tab while the bar has the focus), `Tabs::hint` (the number),
 * `Tabs::close` (the `×`) and `Tabs::more` (`‹` `›`). Parts compose in that order, in the tab's
 * states, so a tab inherits the bar's background and a hint the tab's. A `:hover` rule for a
 * tab (the default theme has one) makes the bar follow the mouse. Labels are plain text.
 */
class Tabs extends Component
{
    /** @var array<string, array{label: string, content: Component|Element, closable?: bool}> */
    private array $tabs = [];
    private ?string $current;
    /** First tab shown when the bar scrolls. */
    private int $first = 0;
    /** The tab under the mouse, by key. */
    private ?string $hover = null;
    /** @var list<array{int, int, string, ?string}> x from, x to (excluded), what, tab key */
    private array $hits = [];

    /**
     * @param array<array-key, array{label: string, content: Component|Element, closable?: bool}> $tabs the tabs by key (the first instance's only: use `setTabs()` later)
     * @param string|null $current the key of the first current tab (default the first)
     * @param string $position 'top' or 'bottom' (a prop)
     * @param bool   $hints   show the number of each of the first nine tabs, which Alt-number selects (a prop)
     * @param bool   $closable every tab has a `×` (a tab's own `closable` overrides) (a prop)
     * @param (\Closure(string $key, Tabs $tabs): void)|null $onChange the current tab changed
     * @param (\Closure(string $key, Tabs $tabs): void)|null $onClose a tab is asked to close; it decides
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        array $tabs = [],
        ?string $current = null,
        public string $position = 'top',
        public bool $hints = false,
        public bool $closable = false,
        private readonly ?\Closure $onChange = null,
        private readonly ?\Closure $onClose = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
        $this->tabs = self::check($tabs);
        $this->current = $current !== null && isset($this->tabs[$current]) ? $current : array_key_first($this->tabs);
    }

    /** @return array<string, array{label: string, content: Component|Element, closable?: bool}> */
    private static function check(array $tabs): array
    {
        $out = [];
        foreach ($tabs as $key => $tab) {
            if (!\is_array($tab) || !isset($tab['label']) || !($tab['content'] ?? null) instanceof Element) {
                throw new \InvalidArgumentException("Tab '$key' must be ['label' => string, 'content' => Component|Element]");
            }
            $out[(string) $key] = $tab;
        }
        return $out;
    }

    protected function bindings(): array
    {
        return [
            'left' => ['previous', 'previous tab'], 'right' => ['next', 'next tab'],
            'home' => 'first', 'end' => 'last',
            'tab' => ['focusContent', 'into the tab'], 'enter' => 'focusContent',
            'escape' => ['focusBar', 'tab bar'],
            'delete' => 'closeCurrent',
            'ctrl+tab' => ['nextTab', 'next tab', 'first' => true],
            'ctrl+shift+tab' => ['previousTab', 'first' => true],
        ];
    }

    // ------------------------------------------------------------------------------------------
    // The tabs

    /** The keys of the tabs, in order. @return list<string> */
    public function tabs(): array
    {
        return array_keys($this->tabs);
    }

    /** The key of the current tab, null without tabs. */
    public function current(): ?string
    {
        return $this->current;
    }

    /**
     * Replace the tabs (add, remove, reorder, rename). The current tab stays when its key is
     * still there (or `$current` names another); else the tab at its old place becomes current.
     * Components of tabs that went are disposed; those of tabs that stay keep their state.
     * Emits `change` when the current tab is another one afterwards.
     *
     * @param array<array-key, array{label: string, content: Component|Element, closable?: bool}> $tabs
     */
    public function setTabs(array $tabs, ?string $current = null): void
    {
        $old = $this->current;
        $oldIndex = $old === null ? 0 : (int) array_search($old, array_keys($this->tabs), true);
        $went = $this->childKeys();
        $this->tabs = self::check($tabs);
        foreach (array_diff_key($went, $this->childKeys()) as $childKey => $_) {
            $this->child($childKey)?->stop();      // the tab went (or has another component now)
        }
        $keys = array_keys($this->tabs);
        if ($current !== null && isset($this->tabs[$current])) {
            $this->current = $current;
        } elseif ($old === null || !isset($this->tabs[$old])) {
            $this->current = $keys[min($oldIndex, \count($keys) - 1)] ?? null;
        }
        $this->requestRender();
        if ($this->current !== $old && $this->current !== null) {
            $this->changed();
        }
    }

    /** Make a tab current; false when there is no such tab. */
    public function select(string $key): bool
    {
        if (!isset($this->tabs[$key])) {
            return false;
        }
        if ($key !== $this->current) {
            $inContent = $this->inContent();
            $this->current = $key;
            $this->requestRender();
            if ($inContent && !$this->focusContent()) {
                $this->focus();     // the focus follows into the new tab, or to the bar when it has nothing to focus
            }
            $this->changed();
        }
        return true;
    }

    /**
     * What tab $key shows: the component or the element it was given, null when there is no
     * such tab. A component given as a tab's content stays its owner's (the one that made it,
     * with `child()` or `new`): `Tabs` keeps it alive while its tab exists, also hidden, and
     * stops it when the tab goes; the owner's `child($key)` keeps finding it until then.
     */
    public function content(string $key): Component|Element|null
    {
        return $this->tabs[$key]['content'] ?? null;
    }

    /** @return array<string, true> the keys of the children that are the components of the tabs */
    private function childKeys(): array
    {
        $out = [];
        foreach ($this->tabs as $key => $_) {
            foreach ($this->tabComponents((string) $key) as $c) {
                $out[$this->childKey((string) $key, $c)] = true;
            }
        }
        return $out;
    }

    /**
     * The components of tab $key: its content when it is one, else those its element tree
     * holds, in the order they show (not those inside them).
     *
     * @return list<Component>
     */
    private function tabComponents(string $key): array
    {
        $out = [];
        $walk = static function (Element $e) use (&$walk, &$out): void {
            if ($e instanceof Component) {
                $out[] = $e;
            } elseif ($e instanceof Column || $e instanceof Row || $e instanceof Layers) {
                foreach ($e->children as $child) {
                    $walk($child);
                }
            } elseif ($e instanceof Box) {
                $walk($e->child);
            }
        };
        if (isset($this->tabs[$key])) {
            $walk($this->tabs[$key]['content']);
        }
        return $out;
    }

    /** The next tab; false at the last one (the key goes on). */
    public function next(): bool
    {
        return $this->step(1, false);
    }

    /** The previous tab; false at the first one. */
    public function previous(): bool
    {
        return $this->step(-1, false);
    }

    /** The first tab (Home on the bar); false in the content, so the key goes on there. */
    public function first(): bool
    {
        return $this->inContent() ? false : $this->select((string) array_key_first($this->tabs));
    }

    /** The last tab (End on the bar); false in the content. */
    public function last(): bool
    {
        return $this->inContent() ? false : $this->select((string) array_key_last($this->tabs));
    }

    /** The next tab, after the last the first (Ctrl-Tab, from the bar or the content); false with one tab. */
    public function nextTab(): bool
    {
        return $this->step(1, true);
    }

    /** The previous tab, around (Ctrl-Shift-Tab). */
    public function previousTab(): bool
    {
        return $this->step(-1, true);
    }

    private function step(int $by, bool $around): bool
    {
        $keys = array_keys($this->tabs);
        $i = array_search($this->current, $keys, true);
        if ($i === false || $keys === [] || ($this->inContent() && !$around)) {
            return false;
        }
        $j = $i + $by;
        if ($around) {
            $j = ($j + \count($keys)) % \count($keys);
        } elseif ($j < 0 || $j >= \count($keys)) {
            return false;
        }
        return $j !== $i && $this->select((string) $keys[$j]);
    }

    /** Close a tab as the `×` does: `$onClose` decides when given, else the tab is removed. False when there is no such tab. */
    public function close(string $key): bool
    {
        if (!isset($this->tabs[$key])) {
            return false;
        }
        $this->emit('close', $key, $this);
        if ($this->onClose !== null) {
            $this->call($this->onClose, $key, $this);
        } else {
            $tabs = $this->tabs;
            unset($tabs[$key]);
            $this->setTabs($tabs);
        }
        return true;
    }

    /** Close the current tab (Delete on the bar) when it is closable. */
    public function closeCurrent(): bool
    {
        return $this->current !== null && !$this->inContent() && $this->isClosable($this->current) && $this->close($this->current);
    }

    private function isClosable(string $key): bool
    {
        return $this->tabs[$key]['closable'] ?? $this->closable;
    }

    private function changed(): void
    {
        $this->emit('change', $this->current, $this);
        $this->call($this->onChange, $this->current, $this);
    }

    // ------------------------------------------------------------------------------------------
    // Focus

    /** The component of the current tab, when its content is one. */
    private function currentComponent(): ?Component
    {
        if ($this->current === null) {
            return null;
        }
        $content = $this->tabs[$this->current]['content'];
        return $content instanceof Component ? $this->child($this->childKey($this->current, $content)) : null;
    }

    /** @return list<Component> the components of the current tab (see tabComponents()), as they run */
    private function contentComponents(): array
    {
        if ($this->current === null) {
            return [];
        }
        $out = [];
        foreach ($this->tabComponents($this->current) as $c) {
            $out[] = $this->child($this->childKey($this->current, $c)) ?? $c;
        }
        return $out;
    }

    private function childKey(string $key, Component $content): string
    {
        return 'tab:' . $key . ':' . spl_object_id($content);
    }

    /** True while the focus is in the shown content (in a component of it), false on the bar. */
    public function inContent(): bool
    {
        foreach ($this->contentComponents() as $c) {
            if ($c->hasFocus()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Move the focus into the shown content (Tab, Enter on the bar): to its first component
     * that takes keys, else its first component, whatever elements wrap it (a component not
     * shown yet gets it when it starts). False when the content has no component or the focus
     * is there already.
     */
    public function focusContent(): bool
    {
        $components = $this->contentComponents();
        if ($components === [] || $this->inContent()) {
            return false;
        }
        $target = $components[0];
        foreach ($components as $c) {
            if ($c->canFocus()) {
                $target = $c;
                break;
            }
        }
        $target->focus();
        return true;
    }

    /** Move the focus back to the bar (Escape in the content); false on the bar already, or in a `Terminal`, which keeps Escape. */
    public function focusBar(): bool
    {
        if (!$this->inContent()) {
            return false;
        }
        foreach ($this->contentComponents() as $c) {
            if ($c instanceof Terminal && $c->hasFocus()) {
                return false;
            }
        }
        $this->focus();
        return true;
    }

    /** Alt-1 to Alt-9, on their way to the content: a map of nine bindings would say less. */
    protected function onKey(Key $key): bool
    {
        if ($key->alt && !$key->ctrl && !$key->shift && preg_match('/^[1-9]$/', $key->name)) {
            $keys = array_keys($this->tabs);
            return isset($keys[(int) $key->name - 1]) && $this->select((string) $keys[(int) $key->name - 1]);
        }
        return false;
    }

    // ------------------------------------------------------------------------------------------
    // Mouse

    protected function onMouse(Mouse $mouse): bool
    {
        $row = $this->position === 'bottom' ? $this->surface->rows() - 1 : 0;
        $hover = null;
        $hit = null;
        if ($mouse->y === $row) {
            foreach ($this->hits as $h) {
                if ($mouse->x >= $h[0] && $mouse->x < $h[1]) {
                    $hit = $h;
                    break;
                }
            }
            $hover = $hit !== null && $hit[3] !== null ? $hit[3] : null;
        }
        if ($hover !== $this->hover) {
            $this->hover = $hover;
            $this->markDirty();
        }
        if ($mouse->y !== $row) {
            return false;
        }
        if ($mouse->action === Mouse::WHEEL_UP || $mouse->action === Mouse::WHEEL_DOWN) {
            $this->step($mouse->action === Mouse::WHEEL_UP ? -1 : 1, false);
            return true;
        }
        if ($mouse->action !== Mouse::PRESS) {
            return false;
        }
        if ($hit === null) {
            if ($mouse->button === Mouse::LEFT) {
                $this->focus();
                return true;
            }
            return false;
        }
        if ($mouse->button === Mouse::MIDDLE) {
            if ($hit[3] !== null && $this->isClosable($hit[3])) {
                $this->close($hit[3]);
            }
            return true;
        }
        if ($mouse->button !== Mouse::LEFT) {
            return false;
        }
        $this->focus();
        match ($hit[2]) {
            'close' => $this->close($hit[3]),
            'prev' => $this->step(-1, false),
            'next' => $this->step(1, false),
            default => $this->select($hit[3]),
        };
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Drawing

    /** @return list<string> the plain parts of tab $i: hint, label, close, with padding */
    private function cell(int $i, string $key, string $label, ?int $max = null): array
    {
        $hint = $this->hints && $i < 9 ? ($i + 1) . ' ' : '';
        $close = $this->isClosable($key) ? ' ×' : '';
        if ($max !== null) {
            $label = Text::truncate($label, max(1, $max - 2 - Text::width($hint) - Text::width($close)));
        }
        return [$hint, $label, $close];
    }

    private function bar(): Element
    {
        $cols = $this->surface->cols();
        $keys = array_map('strval', array_keys($this->tabs));
        $barFocused = $this->hasFocus() && !$this->inContent();
        $widths = [];
        foreach ($keys as $i => $key) {
            [$h, $l, $c] = $this->cell($i, $key, $this->tabs[$key]['label']);
            $widths[$i] = 2 + Text::width($h . $l . $c);
        }
        $overflow = array_sum($widths) > $cols;
        $avail = $overflow ? max(1, $cols - 2) : $cols;
        $cur = $this->current === null ? 0 : (int) array_search($this->current, $keys, true);

        // the window of tabs that is shown: the current one always in it
        $first = $overflow ? max(0, min($this->first, \count($keys) - 1)) : 0;
        $last = $first - 1;
        for (;;) {
            $used = 0;
            for ($last = $first; $last < \count($keys) && $used + $widths[$last] <= $avail; $last++) {
                $used += $widths[$last];
            }
            $last = max($first, $last - 1);     // (a tab wider than the bar is shown cut)
            if ($cur < $first) {
                $first = $cur;
            } elseif ($cur > $last && $first < $cur) {
                $first++;
            } else {
                break;
            }
        }
        $this->first = $first;

        $bar = $this->style('bar');
        $more = $this->style(['bar', 'more']);
        $spans = [];
        $hits = [];
        $x = 0;
        if ($overflow) {
            $spans[] = new Span($first > 0 ? '‹' : ' ', style: $more->properties());
            $hits[] = [0, 1, 'prev', null];
            $x = 1;
        }
        for ($i = $first; $i <= $last && $i < \count($keys); $i++) {
            $key = $keys[$i];
            $states = [];
            if ($key === $this->current) {
                $states[] = 'current';
                if ($barFocused) {
                    $states[] = 'focused';
                }
            }
            if ($key === $this->hover && $this->hasStyleState('hover')) {
                $states[] = 'hover';
            }
            [$h, $l, $c] = $this->cell($i, $key, $this->tabs[$key]['label'], $widths[$i] > $avail ? $avail : null);
            $tab = $this->style(['bar', 'tab'], ...$states)->properties();
            $spans[] = new Span(' ', style: $tab);
            if ($h !== '') {
                $spans[] = new Span($h, style: $this->style(['bar', 'tab', 'hint'], ...$states)->properties());
            }
            $spans[] = new Span($l, style: $tab);
            $labelEnd = $x + 1 + Text::width($h . $l);
            if ($c !== '') {
                $spans[] = new Span($c, style: $this->style(['bar', 'tab', 'close'], ...$states)->properties());
            }
            $spans[] = new Span(' ', style: $tab);
            $end = $x + 2 + Text::width($h . $l . $c);
            $hits[] = [$x, $c !== '' ? $labelEnd + 1 : $end, 'tab', $key];
            if ($c !== '') {
                $hits[] = [$labelEnd + 1, $end - 1, 'close', $key];
                $hits[] = [$end - 1, $end, 'tab', $key];
            }
            $x = $end;
        }
        if ($overflow) {
            $spans[] = new Span(str_repeat(' ', max(0, $cols - 1 - $x)) . ($last < \count($keys) - 1 ? '›' : ' '), style: $more->properties());
            $hits[] = [max($x, $cols - 1), $cols, 'next', null];
        }
        $this->hits = $hits;
        return new TextElement($spans, wrap: false, style: $bar->properties(), height: 1);
    }

    protected function render(): Element
    {
        $content = null;
        foreach ($this->tabs as $key => $tab) {
            $key = (string) $key;
            // the components of every tab stay alive while it is hidden; only the current tab is in the tree
            foreach ($this->tabComponents($key) as $c) {
                $this->keep($this->childKey($key, $c), fn() => $c);
            }
            if ($key === $this->current) {
                $c = $tab['content'];
                $content = $c instanceof Component
                    ? $this->child($this->childKey($key, $c))->with(height: '1fr')
                    : $c->with(key: 'tab:' . $key, height: '1fr');
            }
        }
        $bar = $this->bar();
        return new Column($this->position === 'bottom' ? [$content, $bar] : [$bar, $content]);
    }
}
