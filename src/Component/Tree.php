<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * Hierarchical items that open and close: a file browser, an outline, a process tree.
 *
 *     $tree = $this->child('files', fn() => new Tree(
 *         items: [
 *             ['label' => 'src', 'expanded' => true, 'children' => [
 *                 ['label' => 'Tree.php', 'value' => '/src/Tree.php'],
 *             ]],
 *             ['label' => 'vendor', 'children' => null],           // opened by the loader below
 *         ],
 *         load: fn(array $node) => scandir_items($node['value']),  // or an awaitable of the items
 *         onActivate: fn($key, Tree $t) => $this->open($t->value($key)),
 *     ));
 *
 * **Items** are lists of arrays: `label` (required), `key` (your id; default: the parent's key and
 * the label joined by "/", so give one when labels repeat; keys are unique in the tree), `icon`
 * (a string shown before the label), `value` (anything: `value($key)` gives it back),
 * `children` (a list of items), `expanded` (open when first shown) and `leaf` (never has
 * children). With a `load` callable an item without `children` is a branch whose children are
 * fetched when it is opened first.
 *
 * **Lazy children**: `load(array $node)` is called with the node (`key`, `label`, `icon`, `value`,
 * `depth`) and returns the list of its items (an array, or any `Traversable`: a generator), or
 * an awaitable of it: any other object is taken for one and passed to `fiber\await()` in a
 * coroutine of the tree (a `fiber\go()` handle, a `fiber\Deferred`, a promise with `then()`),
 * which keeps the tree usable meanwhile and shows a spinning sign in place of the marker. The
 * children are kept: closing and opening again does not load again. When loading fails the node
 * closes again (state `failed`), and opening it tries once more. `reload($key)` forgets the
 * children of a node.
 *
 * **Keys**: Up/Down, Page Up/Page Down, Home/End move the current node (add more with
 * `bind()`: `$tree->bind(['ctrl+p' => 'previous', 'ctrl+n' => 'next', 'k' => 'previous'])`). Right
 * opens a closed node, on an open one goes to its first child; Left closes an open node, else
 * goes to the parent. Enter opens or closes a branch and activates the node; Space does the
 * same for a branch, but with `multiple` it marks or unmarks the node instead. Typing letters
 * jumps to the next node whose label starts with them (letters typed within a second are one
 * word; typing the same letter again goes on to the next).
 *
 * **Mouse**: a click on a row makes it current, on the ▸/▾ marker opens or closes it (and
 * Ctrl-click marks with `multiple`); the wheel scrolls three rows.
 *
 * **Callbacks and events**: `onSelect($key, $tree)` when the current node changed, `onExpand`,
 * `onCollapse`, `onActivate($key, $tree)` on Enter, and `onChange($keys, $tree)` when the marks
 * changed; the same as events `select`, `expand`, `collapse`, `activate` and `change` (with the
 * same arguments) for `on()`.
 *
 * **Size**: it takes the room it is given. Only the rows in view are laid out and drawn, so a
 * hundred thousand nodes cost what a screenful does (opening or closing a node walks the open
 * nodes once). The view scrolls as `Scrollable` says (`scrollTop()`, `scrollTo()`, the `scroll`
 * event); the wheel moves the view without moving the current node, and the next move brings
 * the current node back into view.
 *
 * **Style** parts: `Tree::node` (states `current`, `selected` for a marked node, `loading`,
 * `failed`), `Tree::marker` and `Tree::guide` (the same states). The marker and the guides are
 * drawn on the node's row (`style(['node', 'marker'], ...)`): without a colour of their own
 * they keep a highlighted row one bar. The default theme shows the current node inverted.
 */
class Tree extends Component
{
    use Scrollable;

    /** @var array<int|string, array{key: int|string, label: string, icon: string, value: mixed, parent: int|string|null, depth: int, children: list<int|string>|null, lazy: bool, loaded: bool, loading: bool, failed: bool}> */
    private array $nodes = [];
    /** @var list<int|string> */
    private array $roots = [];
    /** @var array<int|string, true> */
    private array $expanded = [];
    /** @var array<int|string, true> */
    private array $marked = [];
    /** @var array<int|string, array> the items a lazy node was loaded with */
    private array $loadedItems = [];
    /** @var list<int|string> the open nodes in order of appearance */
    private array $rows = [];
    /** @var array<int|string, int> key => position in $rows */
    private array $pos = [];
    /** @var list<int|string> open lazy nodes that still need their children */
    private array $needLoad = [];
    private int|string|null $currentKey = null;
    /** The wheel moved the view away from the current node; the next move brings it back. */
    private bool $free = false;
    private ?array $builtFrom = null;
    private int $frame = 0;
    /** @var array<int|string, true> the nodes whose children are on their way */
    private array $loading = [];
    private bool $animating = false;
    private string $typed = '';
    private float $typedAt = 0.0;
    /** @var list<int|string> the node keys drawn by the last render, for clicks (row => key) */
    private array $drawn = [];

    /**
     * @param list<array<string, mixed>> $items the items (see the class description)
     * @param (\Closure(array): mixed)|null $load children of a lazy node: a list of items or an awaitable of it
     * @param bool $multiple Space marks nodes
     * @param bool $guides   draw ├─ └─ │ lines (off: two spaces of indent per level)
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public array $items = [],
        private readonly ?\Closure $load = null,
        public bool $multiple = false,
        public bool $guides = true,
        private readonly ?\Closure $onSelect = null,
        private readonly ?\Closure $onExpand = null,
        private readonly ?\Closure $onCollapse = null,
        private readonly ?\Closure $onActivate = null,
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->with($key, $id, $class, $style);
        $this->rebuild();
    }

    protected function contentRows(): int
    {
        return \count($this->rows);
    }

    // ------------------------------------------------------------------------------------------
    // The data

    /**
     * Replace the items. Which nodes are open, marked and current is kept by key, and so are the
     * children of lazy nodes already loaded.
     *
     * @param list<array<string, mixed>> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
        $this->rebuild();
        $this->requestRender();
    }

    protected function propsChanged(): void
    {
        if ($this->items !== $this->builtFrom) {
            $this->rebuild();
        }
    }

    private function rebuild(): void
    {
        $old = $this->nodes;
        $this->builtFrom = $this->items;
        $this->nodes = [];
        $this->roots = $this->build($this->items, null, 0, $old);
        $this->expanded = array_intersect_key($this->expanded, $this->nodes);
        $this->loadedItems = array_intersect_key($this->loadedItems, $this->nodes);
        $this->loading = array_intersect_key($this->loading, $this->nodes);
        $marked = $this->marked;
        $this->marked = array_intersect_key($this->marked, $this->nodes);
        if ($this->currentKey !== null && !isset($this->nodes[$this->currentKey])) {
            $this->currentKey = null;
        }
        $this->flatten();
        if ($this->marked !== $marked) {
            $this->changed();
        }
    }

    /**
     * Make the nodes of $items, children of $parent; returns their keys.
     *
     * @param list<array<string, mixed>> $items
     * @param array<int|string, mixed> $old the nodes before a rebuild ('expanded' counts only for new ones)
     * @return list<int|string>
     */
    private function build(array $items, int|string|null $parent, int $depth, array $old = []): array
    {
        $keys = [];
        foreach ($items as $item) {
            $label = str_replace(["\r", "\n", "\t", "\e"], ' ', (string) ($item['label'] ?? ''));
            $key = $item['key'] ?? ($parent === null ? $label : $parent . '/' . $label);
            if (isset($this->nodes[$key])) {
                throw new \InvalidArgumentException("Tree: the key '$key' is used twice; give the items a `key`");
            }
            $children = isset($item['children']) ? (array) $item['children'] : null;
            $lazy = $children === null && $this->load !== null && !($item['leaf'] ?? false);
            $this->nodes[$key] = $node = [
                'key' => $key, 'label' => $label, 'icon' => (string) ($item['icon'] ?? ''), 'value' => $item['value'] ?? null,
                'parent' => $parent, 'depth' => $depth, 'children' => null, 'lazy' => $lazy, 'loaded' => false,
                'loading' => false, 'failed' => false,
            ];
            if (!isset($old[$key]) && ($item['expanded'] ?? false)) {
                $this->expanded[$key] = true;
            }
            if ($lazy && isset($this->loadedItems[$key])) {
                $children = $this->loadedItems[$key];
                $this->nodes[$key]['loaded'] = true;
            } elseif ($lazy && ($old[$key]['loading'] ?? false)) {
                $this->nodes[$key]['loading'] = true;      // its load is still on its way
            }
            if ($children !== null) {
                $this->nodes[$key]['children'] = $this->build($children, $key, $depth + 1, $old);
            }
            $keys[] = $node['key'];
        }
        return $keys;
    }

    /** Make the list of open nodes (what is laid out) from the tree. */
    private function flatten(): void
    {
        $rows = [];
        $this->needLoad = [];
        $stack = array_reverse($this->roots);
        while ($stack !== []) {
            $key = array_pop($stack);
            $rows[] = $key;
            $node = $this->nodes[$key];
            if (!isset($this->expanded[$key])) {
                continue;
            }
            if ($node['lazy'] && !$node['loaded'] && !$node['loading']) {
                $this->needLoad[] = $key;
            }
            for ($i = \count($node['children'] ?? []) - 1; $i >= 0; $i--) {
                $stack[] = $node['children'][$i];
            }
        }
        $this->rows = $rows;
        $this->pos = array_flip($rows);
        if ($this->currentKey !== null && !isset($this->pos[$this->currentKey])) {
            $this->currentKey = $this->nodes[$this->currentKey]['parent'] ?? null;
            while ($this->currentKey !== null && !isset($this->pos[$this->currentKey])) {
                $this->currentKey = $this->nodes[$this->currentKey]['parent'];
            }
        }
    }

    /** Start the loads that wait (opened before the tree was shown, or while it could not load). */
    private function loadWaiting(): void
    {
        while ($this->needLoad !== []) {
            $before = $this->needLoad;
            foreach ($before as $key) {
                $this->startLoad($key);
            }
            if ($this->needLoad === $before) {
                break;          // nothing could be started (not mounted yet)
            }
        }
    }

    private function startLoad(int|string $key): void
    {
        $node = $this->nodes[$key] ?? null;
        if ($node === null || !$node['lazy'] || $this->load === null || $node['loading'] || $node['loaded']) {
            return;
        }
        try {
            $result = ($this->load)(['key' => $key, 'label' => $node['label'], 'icon' => $node['icon'], 'value' => $node['value'], 'depth' => $node['depth']]);
            if ($result instanceof \Traversable) {
                $result = iterator_to_array($result, false);
            }
        } catch (\Throwable $e) {
            $this->loadFailed($key);
            throw $e;
        }
        if (\is_array($result)) {
            $this->loaded($key, $result);
            return;
        }
        if (!\is_object($result)) {
            throw new \UnexpectedValueException('Tree: `load` returns a list of items or an awaitable');
        }
        if (!$this->isMounted()) {
            return;             // loaded at the first render
        }
        $this->nodes[$key]['loading'] = true;
        $this->loading[$key] = true;
        $this->needLoad = array_values(array_diff($this->needLoad, [$key]));
        $this->animate();
        $this->go(function () use ($key, $result): void {
            try {
                $items = \fiber\await($result);
                if ($items instanceof \Traversable) {
                    $items = iterator_to_array($items, false);
                }
            } catch (\Throwable) {
                $this->loadFailed($key);
                return;
            }
            $this->loaded($key, (array) $items);
        });
    }

    /** The children of $key arrived. */
    private function loaded(int|string $key, array $items): void
    {
        unset($this->loading[$key]);
        if (!isset($this->nodes[$key])) {
            return;
        }
        $this->dropChildren($key);
        $this->loadedItems[$key] = $items;
        $this->nodes[$key]['children'] = $this->build($items, $key, $this->nodes[$key]['depth'] + 1);
        $this->nodes[$key]['loaded'] = true;
        $this->nodes[$key]['loading'] = false;
        $this->nodes[$key]['failed'] = false;
        $this->flatten();
        $this->requestRender();
    }

    private function loadFailed(int|string $key): void
    {
        unset($this->loading[$key]);
        if (isset($this->nodes[$key])) {
            $this->nodes[$key]['loading'] = false;
            $this->nodes[$key]['failed'] = true;
            unset($this->expanded[$key]);
            $this->flatten();
            $this->requestRender();
        }
    }

    private function dropChildren(int|string $key): void
    {
        $stack = $this->nodes[$key]['children'] ?? [];
        while ($stack !== []) {
            $k = array_pop($stack);
            array_push($stack, ...($this->nodes[$k]['children'] ?? []));
            unset($this->nodes[$k], $this->expanded[$k], $this->marked[$k], $this->loadedItems[$k]);
        }
        $this->nodes[$key]['children'] = null;
    }

    /** Spin the signs of the loading nodes for as long as there are any. */
    private function animate(): void
    {
        if ($this->animating) {
            return;
        }
        $this->animating = true;
        $this->go(function (): void {
            try {
                while ($this->loading !== [] && $this->sleep(0.08)) {
                    $this->frame++;
                }
            } finally {
                $this->animating = false;
            }
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading and setting

    /** The key of the current node, or null for an empty tree. */
    public function current(): int|string|null
    {
        return $this->currentKey ?? ($this->rows[0] ?? null);
    }

    /** Make the node $key current, opening the nodes above it and scrolling it into view; false if there is no such node. */
    public function setCurrent(int|string $key): bool
    {
        if (!isset($this->nodes[$key])) {
            return false;
        }
        $reveal = false;
        for ($p = $this->nodes[$key]['parent']; $p !== null; $p = $this->nodes[$p]['parent']) {
            if (!isset($this->expanded[$p])) {
                $this->expanded[$p] = true;
                $reveal = true;
                $this->fire('expand', $this->onExpand, $p);
                $this->startLoad($p);
            }
        }
        if ($reveal) {
            $this->flatten();
        }
        if (!isset($this->pos[$key])) {
            return false;
        }
        $this->moveTo($this->pos[$key]);
        return true;
    }

    /** True if the node exists. */
    public function has(int|string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    /** The node's label, or null. */
    public function label(int|string $key): ?string
    {
        return $this->nodes[$key]['label'] ?? null;
    }

    /** The `value` the node's item was given. */
    public function value(int|string $key): mixed
    {
        return $this->nodes[$key]['value'] ?? null;
    }

    /** The key of the node's parent (null for a top-level node and for no such node). */
    public function parentOf(int|string $key): int|string|null
    {
        return $this->nodes[$key]['parent'] ?? null;
    }

    /** True if the node is open (it may be waiting for its children). */
    public function isExpanded(int|string $key): bool
    {
        return isset($this->expanded[$key]);
    }

    /** True while the children of the node are being fetched. */
    public function isLoading(int|string $key): bool
    {
        return $this->nodes[$key]['loading'] ?? false;
    }

    /** True if the last load of the node's children failed (opening it tries again). */
    public function hasFailed(int|string $key): bool
    {
        return $this->nodes[$key]['failed'] ?? false;
    }

    /** True if the node has (or may load) children. */
    public function isBranch(int|string $key): bool
    {
        $n = $this->nodes[$key] ?? null;
        return $n !== null && ($n['lazy'] || ($n['children'] ?? []) !== []);
    }

    /** How many nodes are shown (the open ones, scrolled out of view or not). */
    public function visibleCount(): int
    {
        return \count($this->rows);
    }

    /**
     * The keys of the nodes shown, in order.
     *
     * @return list<int|string>
     */
    public function visibleKeys(): array
    {
        return $this->rows;
    }

    /**
     * The marked nodes' keys (with `multiple`), in the order of the tree; without `multiple`
     * the current node's.
     *
     * @return list<int|string>
     */
    public function selection(): array
    {
        if (!$this->multiple) {
            $c = $this->current();
            return $c === null ? [] : [$c];
        }
        return array_keys(array_intersect_key($this->nodes, $this->marked));
    }

    /**
     * Mark exactly these nodes (with `multiple`; without it the first becomes current).
     *
     * @param list<int|string> $keys
     */
    public function setSelection(array $keys): void
    {
        if (!$this->multiple) {
            $keys === [] || $this->setCurrent($keys[0]);
            return;
        }
        $this->marked = array_fill_keys(array_values(array_filter($keys, fn($k) => isset($this->nodes[$k]))), true);
        $this->changed();
        $this->requestRender();
    }

    /** True if the node is marked. */
    public function isMarked(int|string $key): bool
    {
        return isset($this->marked[$key]);
    }

    /**
     * Open a node (the current one without $key); false when it has no children or is open.
     * A node with lazy children starts loading them.
     */
    public function expand(int|string|null $key = null): bool
    {
        $key ??= $this->current();
        if ($key === null || !$this->isBranch($key) || isset($this->expanded[$key])) {
            return false;
        }
        $this->expanded[$key] = true;
        $this->fire('expand', $this->onExpand, $key);
        $this->flatten();
        $this->startLoad($key);
        $this->requestRender();
        return true;
    }

    /** Close a node (the current one without $key); false when it is closed or a leaf. */
    public function collapse(int|string|null $key = null): bool
    {
        $key ??= $this->current();
        if ($key === null || !isset($this->expanded[$key])) {
            return false;
        }
        unset($this->expanded[$key]);
        $before = $this->current();
        $this->flatten();
        $this->fire('collapse', $this->onCollapse, $key);
        if ($this->current() !== $before) {
            $this->fire('select', $this->onSelect, $this->current());      // the current node was inside: the collapsed one is now
        }
        $this->requestRender();
        return true;
    }

    /** Open or close a node (the current one without $key); false for a leaf. */
    public function toggle(int|string|null $key = null): bool
    {
        $key ??= $this->current();
        if ($key === null || !$this->isBranch($key)) {
            return false;
        }
        return isset($this->expanded[$key]) ? $this->collapse($key) : $this->expand($key);
    }

    /** Forget the loaded children of a node and (when it is open) load them again. */
    public function reload(int|string $key): void
    {
        if (!isset($this->nodes[$key]) || !$this->nodes[$key]['lazy']) {
            return;
        }
        $this->dropChildren($key);
        unset($this->loadedItems[$key]);
        $this->nodes[$key]['loaded'] = false;
        $this->nodes[$key]['failed'] = false;
        $this->flatten();
        $this->loadWaiting();
        $this->requestRender();
    }

    // ------------------------------------------------------------------------------------------
    // Keys

    protected function bindings(): array
    {
        return [
            'up' => 'previous', 'down' => 'next',
            'pageup' => 'pageUp', 'pagedown' => 'pageDown', 'home' => 'first', 'end' => 'last',
            'right' => 'open', 'left' => 'close', 'enter' => 'activate', 'space' => 'toggleOrMark',
        ];
    }

    /** The node above becomes current; false on the first (the key goes on). */
    public function previous(): bool
    {
        return $this->move(-1);
    }

    /** The node below becomes current; false on the last. */
    public function next(): bool
    {
        return $this->move(1);
    }

    /** One screen up. */
    public function pageUp(): bool
    {
        return $this->move(-max(1, $this->surface->rows() - 1));
    }

    /** One screen down. */
    public function pageDown(): bool
    {
        return $this->move(max(1, $this->surface->rows() - 1));
    }

    /** The first node becomes current. */
    public function first(): bool
    {
        return $this->move(-\count($this->rows));
    }

    /** The last node becomes current. */
    public function last(): bool
    {
        return $this->move(\count($this->rows));
    }

    /** Right: open a closed node, else go to its first child; false on a leaf. */
    public function open(): bool
    {
        $c = $this->current();
        if ($c === null) {
            return false;
        }
        if (!isset($this->expanded[$c])) {
            return $this->expand($c);
        }
        $child = $this->nodes[$c]['children'][0] ?? null;
        if ($child === null) {
            return false;
        }
        $this->moveTo($this->pos[$child]);
        return true;
    }

    /** Left: close an open node, else go to the parent; false on a top-level node. */
    public function close(): bool
    {
        $c = $this->current();
        if ($c === null) {
            return false;
        }
        if (isset($this->expanded[$c])) {
            return $this->collapse($c);
        }
        $parent = $this->nodes[$c]['parent'];
        if ($parent === null) {
            return false;
        }
        $this->moveTo($this->pos[$parent]);
        return true;
    }

    /** Enter: open or close a branch, and activate the node (`onActivate`). */
    public function activate(): bool
    {
        $c = $this->current();
        if ($c === null) {
            return false;
        }
        $this->toggle($c);
        $this->fire('activate', $this->onActivate, $c);
        return true;
    }

    /** Space: with `multiple` mark or unmark the current node, else open or close a branch. */
    public function toggleOrMark(): bool
    {
        $c = $this->current();
        if ($c === null) {
            return false;
        }
        if (!$this->multiple) {
            return $this->toggle($c);
        }
        $this->mark($c);
        return true;
    }

    private function mark(int|string $key): void
    {
        isset($this->marked[$key]) ? $this->marked = array_diff_key($this->marked, [$key => true]) : $this->marked[$key] = true;
        $this->changed();
        $this->requestRender();
    }

    protected function onKey(Key $key): bool
    {
        if (!$key->isText() || $this->rows === []) {
            return false;
        }
        $now = microtime(true);
        $this->typed = $now - $this->typedAt > 1.0 ? $key->text : $this->typed . $key->text;
        $this->typedAt = $now;
        $needle = mb_strtolower($this->typed);
        $n = \count($this->rows);
        $start = $this->pos[$this->current()] + (mb_strlen($needle) === 1 || $this->sameLetters($needle) ? 1 : 0);
        $one = $this->sameLetters($needle) ? mb_substr($needle, 0, 1) : $needle;
        for ($i = 0; $i < $n; $i++) {
            $at = ($start + $i) % $n;
            if (str_starts_with(mb_strtolower($this->nodes[$this->rows[$at]]['label']), $one)) {
                $this->moveTo($at);
                return true;
            }
        }
        return true;
    }

    /** True for "aaa": repeating one letter goes through the nodes that start with it. */
    private function sameLetters(string $s): bool
    {
        return mb_strlen($s) > 1 && mb_strlen(str_replace(mb_substr($s, 0, 1), '', $s)) === 0;
    }

    /** Move the current node by $n rows; false when it could not move at all. */
    private function move(int $n): bool
    {
        if ($this->rows === []) {
            return false;
        }
        $at = $this->pos[$this->current()];
        $to = max(0, min(\count($this->rows) - 1, $at + $n));
        if ($to === $at) {
            return false;
        }
        $this->moveTo($to);
        return true;
    }

    private function moveTo(int $at): void
    {
        if ($this->rows === []) {
            return;
        }
        $this->free = false;
        $key = $this->rows[max(0, min(\count($this->rows) - 1, $at))];
        $old = $this->current();
        $this->currentKey = $key;
        if ($old !== $key) {
            $this->fire('select', $this->onSelect, $key);
        }
        $this->requestRender();
    }

    private function changed(): void
    {
        $keys = $this->selection();
        $this->call($this->onChange, $keys, $this);
        $this->emit('change', $keys, $this);
    }

    private function fire(string $event, ?\Closure $callback, int|string $key): void
    {
        $this->call($callback, $key, $this);
        $this->emit($event, $key, $this);
    }

    // ------------------------------------------------------------------------------------------
    // Mouse and drawing

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->isWheel()) {
            $this->free = $this->scrollWheel($mouse) || $this->free;
            return true;
        }
        if ($mouse->action !== Mouse::PRESS || $mouse->button !== Mouse::LEFT) {
            return $mouse->action === Mouse::RELEASE || $mouse->action === Mouse::DRAG;
        }
        $this->focus();
        $at = $this->scrollTop() + $mouse->y;
        if ($mouse->y < 0 || $at >= \count($this->rows)) {
            return true;
        }
        $key = $this->rows[$at];
        if ($mouse->ctrl && $this->multiple) {
            $this->moveTo($at);
            $this->mark($key);
            return true;
        }
        $this->moveTo($at);
        if ($this->isBranch($key) && $mouse->x === $this->markerX($key)) {
            $this->toggle($key);
        }
        return true;
    }

    /** The column of the marker of a node. */
    private function markerX(int|string $key): int
    {
        return $this->nodes[$key]['depth'] * ($this->guides ? 3 : 2);
    }

    protected function render(): null
    {
        $this->loadWaiting();
        $cols = $this->surface->cols();
        $body = max(1, $this->surface->rows());
        $count = \count($this->rows);
        $cur = $this->current();
        if ($cur !== null && !$this->free) {
            // the current node in view
            $at = $this->pos[$cur];
            $this->scrollTo(min(max($this->scrollTop(), $at - $body + 1), $at));
        }
        $top = $this->scrollTop();
        $base = $this->style();
        $out = "\e[0m\e[?7l\e[?25l" . Ansi::style($base->text) . "\e[2J";
        $this->drawn = [];
        $signs = Spinner::DOTS;
        for ($r = 0; $r < $body && $top + $r < $count; $r++) {
            $key = $this->rows[$top + $r];
            $this->drawn[$r] = $key;
            $node = $this->nodes[$key];
            $states = [];
            if ($key === $cur) {
                $states[] = 'current';
            }
            if (isset($this->marked[$key])) {
                $states[] = 'selected';
            }
            if ($node['loading']) {
                $states[] = 'loading';
            }
            if ($node['failed']) {
                $states[] = 'failed';
            }
            // the guide and the marker on the row: in its state, its colours under theirs
            $row = $this->style('node', ...$states)->text;
            $guide = Ansi::style($this->style(['node', 'guide'], ...$states)->text);
            $marker = Ansi::style($this->style(['node', 'marker'], ...$states)->text);
            $rowSgr = Ansi::style($row);
            $y = $r;
            $line = Ansi::moveTo(0, $y) . $rowSgr . str_repeat(' ', $cols) . Ansi::moveTo(0, $y);
            // the guide columns: a column per ancestor below the top level, then the node's own connector
            $depth = $node['depth'];
            $prefix = '';
            if ($depth > 0) {
                $segments = [];
                $last = $this->isLast($key);
                $segments[] = $this->guides ? ($last ? '└─ ' : '├─ ') : '  ';
                for ($p = $node['parent'], $d = $depth - 1; $d >= 1; $p = $this->nodes[$p]['parent'], $d--) {
                    $segments[] = $this->guides ? ($this->isLast($p) ? '   ' : '│  ') : '  ';
                }
                $prefix = implode('', array_reverse($segments));
            }
            $sign = $node['loading'] ? $signs[$this->frame % \count($signs)] : ($this->isBranch($key) ? (isset($this->expanded[$key]) ? '▾' : '▸') : ' ');
            $x = Text::width($prefix);
            $line .= $guide . Text::truncate($prefix, $cols);
            if ($x < $cols) {
                $line .= $marker . $sign;
                $x++;
            }
            $rest = $cols - $x - 1;
            if ($rest > 0) {
                $text = ($node['icon'] === '' ? '' : $node['icon'] . ' ') . $node['label'];
                $line .= $rowSgr . ' ' . Text::truncate($text, $rest);
            }
            $out .= $line;
        }
        $this->surface->write($out . "\e[0m\e[?7h");
        $this->scrolled();
        return null;
    }

    private function isLast(int|string $key): bool
    {
        $parent = $this->nodes[$key]['parent'];
        $siblings = $parent === null ? $this->roots : $this->nodes[$parent]['children'];
        return $siblings[array_key_last($siblings)] === $key;
    }
}
