<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\ComputedStyle;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Layers;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text;
use phasync\Tui\Layout;
use phasync\Tui\Markup;
use phasync\Tui\Stylesheet;
use phasync\Tui\Surface;
use phasync\Tui\TerminalDevice;
use phasync\Tui\Text as TextUtil;

/**
 * @internal Lays a component's element tree out into its surface tree: a surface for every
 * text and container element, kept from render to render by the element's place in the tree
 * (its path of positions and types, or its `key`), the child components placed where they
 * are, and the layouts that arrange them. Painting is writing: text goes into its surface as
 * escape sequences, only when it or its size changed.
 */
final class Tree
{
    /** @var array<string, Surface> the surfaces made for elements, by path */
    private array $surfaces = [];
    /** @var array<string, string> what was painted into each text surface */
    private array $painted = [];
    /** @var array<string, true> paths used by the build in progress */
    private array $seen = [];
    /** The last build had a `Help` element (it follows the focus). */
    public bool $showsHelp = false;
    /** A measure that places nothing: component elements are only looked up (see measure()). */
    private bool $dry = false;
    /** The element the last build laid out, and the item that measures it as that build did. */
    private ?Element $builtFor = null;
    private ?LayoutItem $builtItem = null;
    /** @var array<string, array{string, LayoutItem, ComputedStyle}> per path: the item the last build made there, from what (its signature, its style) */
    private array $items = [];
    /** @var array<string, ElementLayout> per container path: the layout the last build made there */
    private array $layouts = [];
    /** @var array<string, array{string, array<string, mixed>}> per text path: its content and what was computed from it (lines per width, the widest line) */
    private array $wrapped = [];
    /** @var array<string, Surface> the elements of the last build with a `hover:` class or `:hover` rule, by path */
    private array $hovers = [];
    /** @var array<string, array{bool, Element}> per path: whether the element was focused at the last build, the element */
    private array $focused = [];
    /** @var array<string, array{bool, Element}> the same, for the build in progress */
    private array $focusing = [];
    /** @var list<array{\Closure, Element}> onFocus/onBlur callbacks the last builds found due, to call after the render */
    private array $focusCalls = [];

    /**
     * @param \Closure(string $key, Component $fresh, bool $create): ?Component $place  the child component for a component element (without $create: the one there, or null)
     * @param \Closure(?Component $for, string $show): list<array{string, string}> $help the bindings for a Help element
     */
    public function __construct(
        private readonly Component $owner,
        private readonly \Closure $place,
        private readonly \Closure $help,
    ) {
    }

    /** The layout for the owner's surface that shows $root. */
    public function build(Element $root, Stylesheet $sheet, ComputedStyle $base): Layout
    {
        $this->seen = [];
        $this->showsHelp = false;
        $this->hovers = [];
        $this->focusing = [];
        $style = null;
        // a root shown inline is as tall as its content (the region is), not the terminal
        $inline = $this->owner->surface->viewport() === null && (TerminalDevice::of($this->owner->surface)?->isInline() ?? false);
        if ($inline) {
            $item = $this->item($root, 'r', $sheet, $base);
            $layout = new ElementLayout(ElementLayout::COLUMN, $item === null ? [] : [$item], fill: $base->text);
            $item ??= self::nothing();
        } elseif ($root instanceof Column || $root instanceof Row || $root instanceof Layers) {
            // the root container arranges the component's own surface
            $style = $sheet->compute($root, $this->states($root, $this->owner->surface, 'r'), $base);
            if ($style->hoverAware) {
                $this->hovers['r'] = $this->owner->surface;
            }
        }
        if ($inline) {
            // (laid out above)
        } elseif ($style !== null && !$style->hidden) {
            $layout = $this->container($root, 'r', $sheet, $style);
            $item = $this->frame(new LayoutItem($this->owner->surface), $root, $style, 'auto', 'auto');
            $item->measure = self::containerMeasure($layout, $item->border === false ? 0 : 2);
            $item->layout = $layout;    // (for least(): this item only measures, it is placed by no layout)
        } else {
            $item = $style === null ? $this->item($root, 'r', $sheet, $base) : null;
            if ($item === null) {
                // (hidden: nothing shows)
                $item = new LayoutItem($this->surface('r'));
                $item->measure = static fn(): array => [0, 0];
                $layout = new ElementLayout(ElementLayout::SINGLE, [], fill: $base->text);
            } else {
                $layout = new ElementLayout(ElementLayout::SINGLE, [$item], fill: $base->text);
            }
        }
        if ($this->owner instanceof Live) {
            // (the margin of a live element is laid out where the element is, around its
            // component; the component takes the focus when something in it does)
            $item->margin = [0, 0, 0, 0];
            $focusable = false;
            foreach ($layout->items as $child) {
                $focusable = $focusable || $child->focusable;
            }
            $this->owner->surface->setFocusable($focusable || $this->owner->surface->root() === $this->owner->surface);
        }
        $this->builtFor = $root;
        $this->builtItem = $item;
        // the focus entered or left an element with onFocus/onBlur: called after the render
        // (as the frame shows it: blurs first, then what has the focus now)
        $focus = [];
        foreach ($this->focusing + $this->focused as $path => [, $node]) {
            $was = $this->focused[$path][0] ?? false;
            $now = $this->focusing[$path][0] ?? false;
            $fn = $now !== $was ? $node->attribute($now ? 'onFocus' : 'onBlur') : null;
            if ($fn !== null) {
                $now ? $focus[] = [$fn, $node] : $this->focusCalls[] = [$fn, $node];
            }
        }
        array_push($this->focusCalls, ...$focus);
        $this->focused = $this->focusing;
        foreach ($this->surfaces as $path => $surface) {
            if (!isset($this->seen[$path])) {
                unset($this->surfaces[$path], $this->painted[$path], $this->wrapped[$path], $this->items[$path], $this->layouts[$path]);
                $surface->giveUp();
            }
        }
        return $layout;
    }

    /**
     * The outer size $root needs within $cols x $rows (null: it takes any) when $root is what
     * the last build laid out: measured with the items of that build, without making them again
     * (what a component's own render left; its child components are asked as they are now).
     * False when the last build was of another element.
     *
     * @return array{int,int}|null|false
     */
    public function measureBuilt(Element $root, int $cols, int $rows): array|null|false
    {
        if ($this->builtFor !== $root || $this->builtItem === null) {
            return false;
        }
        return $this->builtItem->natural($cols, $rows);
    }

    /** LayoutItem::least() of $root as the last build laid it out; false when that was another element. */
    public function leastBuilt(Element $root, int $cols): int|false
    {
        return $this->builtFor === $root && $this->builtItem !== null ? $this->builtItem->least($cols) : false;
    }

    /**
     * The outer size $root needs within $cols x $rows (null: it takes any), as the next build
     * lays it out: the surfaces and child components it makes are those the build uses. With
     * $place false nothing is made or changed: a component element is measured by the child it
     * matches, and one that matches none (or was disposed since) takes any room (a measure of
     * a tree rendered earlier, whose components may have gone).
     *
     * @return array{int,int}|null
     */
    public function measure(Element $root, int $cols, int $rows, Stylesheet $sheet, ComputedStyle $base, bool $place = true): ?array
    {
        $dry = $this->dry;
        $this->dry = !$place;
        try {
            return ($this->item($root, 'r', $sheet, $base) ?? self::nothing())->natural($cols, $rows);
        } finally {
            $this->dry = $dry;
        }
    }

    private function container(Column|Row|Layers $node, string $path, Stylesheet $sheet, ComputedStyle $style): ElementLayout
    {
        $items = [];
        foreach ($node->children as $i => $child) {
            $item = $this->item($child, $path . '/' . self::segment($child, $i), $sheet, $style);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        $kind = match (true) {
            $node instanceof Layers => ElementLayout::LAYERS,
            $style->direction !== null => $style->direction === 'row' ? ElementLayout::ROW : ElementLayout::COLUMN,
            $node instanceof Column => ElementLayout::COLUMN,
            default => ElementLayout::ROW,
        };
        $gap = $node instanceof Layers ? 0 : ($style->gap ?? $node->gap);
        // the same items in the same way: the layout of the previous build (it knows its arrangement)
        $kept = $this->dry ? null : ($this->layouts[$path] ?? null);
        if ($kept !== null && $kept->kind === $kind && $kept->gap === $gap && $kept->fill === $style->text && $kept->items === $items
            && $kept->padding === $style->padding && $kept->alignItems === $style->alignItems && $kept->justify === $style->justify) {
            return $kept;
        }
        $layout = new ElementLayout($kind, $items, $gap, $style->padding, $style->text, $style->alignItems, $style->justify);
        if (!$this->dry) {
            $this->layouts[$path] = $layout;
        }
        return $layout;
    }

    private static function segment(Element $node, int $i): string
    {
        $key = $node->attribute('key');
        return $key !== null ? 'k:' . $key : $i . ':' . $node::class;
    }

    /**
     * The window that shows $node. An item made by the previous build for the same place from
     * the same things (the same text in the same style, the same component, a container of the
     * same items) is that build's item again, so its layout and its measures are kept and
     * nothing below it is laid out anew (see ElementLayout::arrange()).
     */
    private function item(Element $node, string $path, Stylesheet $sheet, ComputedStyle $parent): ?LayoutItem
    {
        if (($node instanceof Text && $node->content instanceof \Closure) || ($node instanceof Box && $node->title instanceof \Closure)) {
            return $this->live($node, $path, $sheet, $parent);
        }
        if ($node instanceof Component) {
            $key = $node->attribute('key');
            $component = $node->isMounted() ? $node : ($this->place)($key !== null ? "\0k:" . $key : "\0p:" . $path, $node, !$this->dry);
            if ($component === null || !$component->isMounted()) {
                return new LayoutItem(new Surface(1, 1, 0));     // (a dry measure: gone, it takes any room)
            }
            if (!$this->dry) {
                $component->inheritStyle($parent);
            }
            $style = $component->style();
            if ($style->hidden) {
                return null;
            }
            $sig = 'c' . spl_object_id($component) . '|' . self::sizes($component);
            return $this->kept($path, $sig, $style) ?? $this->keep($path, $sig, $style, $this->componentItem($component, $style));
        }
        if ($node instanceof Span) {
            $node = new Text([$node]);
        }
        $surface = $this->surface($path);
        $style = $sheet->compute($node, $this->states($node, $surface, $path), $parent);
        if ($style->hoverAware && !$this->dry) {
            $this->hovers[$path] = $surface;
        }
        if ($style->hidden) {
            return null;
        }
        if ($node instanceof Text || $node instanceof Help) {
            $surface->setFocusable(false);
            $text = $node instanceof Help ? $this->helpText($node, $sheet, $style) : $node;
            $content = $this->content($text, $sheet, $style);
            $wrap = $text->wrap && $style->wrap !== false;
            $sig = 't' . ($wrap ? 'w' : 'c') . self::sizes($node) . "\0" . $content;
            return $this->kept($path, $sig, $style)
                ?? $this->keep($path, $sig, $style, $this->text($this->frame(new LayoutItem($surface), $node, $style, 'auto', 'auto'), $content, $wrap, $path, $style));
        }
        if ($node instanceof Box) {
            $inner = $this->item($node->child, $path . '/' . self::segment($node->child, 0), $sheet, $style) ?? self::nothing();
            $surface->setFocusable($inner->focusable);
            $title = $node->title === '' ? '' : Markup::toAnsi($node->title, $style->borderStyle ?? $style->text, $sheet);
            $sig = 'b' . spl_object_id($inner) . '|' . self::sizes($node) . "\0" . $title;
            if (($item = $this->kept($path, $sig, $style)) !== null) {
                return $item;
            }
            $item = $this->frame(new LayoutItem($surface), $node, $style, 'auto', 'auto');
            $item->title = $title;
            $item->layout = new ElementLayout(ElementLayout::SINGLE, $inner->surface === self::nothing()->surface ? [] : [$inner], padding: $style->padding, fill: $style->text);
            $item->focusable = $inner->focusable;
            [$t, $r, $b, $l] = $style->padding;
            $extraW = $l + $r + ($item->border === false ? 0 : 2);
            $extraH = $t + $b + ($item->border === false ? 0 : 2);
            $item->measure = static function (int $cols, int $rows) use ($inner, $extraW, $extraH): ?array {
                $n = $inner->natural(max(0, $cols - $extraW), max(0, $rows - $extraH));
                return $n === null ? null : [$n[0] + $extraW, $n[1] + $extraH];
            };
            return $this->keep($path, $sig, $style, $item);
        }
        if ($node instanceof Column || $node instanceof Row || $node instanceof Layers) {
            // as large as its children need ('auto'), so a toolbar Row is one row; a child that
            // takes any room (null measure) makes it take a share ('1fr', see ElementLayout)
            $layout = $this->container($node, $path, $sheet, $style);
            $focusable = false;
            foreach ($layout->items as $child) {
                $focusable = $focusable || $child->focusable;
            }
            $surface->setFocusable($focusable);
            $sig = 'l' . spl_object_id($layout) . '|' . self::sizes($node);
            if (($item = $this->kept($path, $sig, $style)) !== null) {
                return $item;
            }
            $item = $this->frame(new LayoutItem($surface), $node, $style, 'auto', 'auto');
            $item->layout = $layout;
            $item->focusable = $focusable;
            $item->measure = self::containerMeasure($layout, $item->border === false ? 0 : 2);
            return $this->keep($path, $sig, $style, $item);
        }
        throw new \InvalidArgumentException('Unknown element ' . $node::class);
    }

    private function componentItem(Component $component, ComputedStyle $style, ?Element $for = null): LayoutItem
    {
        $item = $this->frame(new LayoutItem($component->surface), $for ?? $component, $style, 'auto', 'auto');
        $item->focusable = true;
        if ($for !== null) {
            $item->border = false;      // (the element's own border is drawn inside its component)
            $item->title = '';
        }
        $border = $item->border === false ? 0 : 2;
        $item->measure = static function (int $cols, int $rows) use ($component, $border): ?array {
            if (!$component->isMounted()) {
                return null;    // (gone since: it takes any room)
            }
            $n = $component->measureFor(max(0, $cols - $border), max(0, $rows - $border));
            return $n === null ? null : [$n[0] + $border, $n[1] + $border];
        };
        $item->least = $component->leastRows(...);
        return $item;
    }

    /** The size attributes of $node, for a signature. */
    private static function sizes(Element $node): string
    {
        return $node->attribute('width') . ',' . $node->attribute('height');
    }

    /** The item the previous build made at $path, if it was made from $sig in $style. */
    private function kept(string $path, string $sig, ComputedStyle $style): ?LayoutItem
    {
        if ($this->dry) {
            return null;
        }
        $kept = $this->items[$path] ?? null;
        return $kept !== null && $kept[0] === $sig && $kept[2] === $style ? $kept[1] : null;
    }

    /** Keep $item as the one made at $path from $sig in $style (the entry holds what its signature names). */
    private function keep(string $path, string $sig, ComputedStyle $style, LayoutItem $item): LayoutItem
    {
        if (!$this->dry) {
            $this->items[$path] = [$sig, $item, $style];
        }
        return $item;
    }

    /**
     * What a container needs: its children's sizes along it added up (with the gaps), across
     * it the largest, its padding and its border; null when a child takes any room. A child that
     * grows (`flex-grow`) needs what its content needs.
     */
    private static function containerMeasure(ElementLayout $layout, int $border): \Closure
    {
        $items = $layout->items;
        $gap = $layout->kind === ElementLayout::LAYERS ? 0 : $layout->gap * max(0, \count($items) - 1);
        $kind = $layout->kind === ElementLayout::COLUMN ? 0 : ($layout->kind === ElementLayout::ROW ? 1 : 2);
        [$t, $r, $b, $l] = $layout->padding;
        $extraW = $border + $l + $r;
        $extraH = $border + $t + $b;
        return static function (int $cols, int $rows) use ($kind, $items, $extraW, $extraH, $gap, $layout, $border): ?array {
            $w = $h = 0;
            // (side by side, each child is as high as it needs at the width the row gives it:
            // text that wraps in a narrow share needs more rows than at the row's whole width)
            $widths = $kind === 1 ? array_column($layout->rects(max(0, $cols - $border), max(0, $rows - $border)), 2) : [];
            foreach ($items as $i => $child) {
                $n = $child->natural(max(0, $cols - $extraW), max(0, $rows - $extraH));
                if ($n === null) {
                    return null;
                }
                if ($kind === 1 && isset($widths[$i]) && $widths[$i] < $n[0]) {
                    $at = $child->natural($widths[$i], max(0, $rows - $extraH));
                    $n = [$n[0], $at === null ? $n[1] : max($n[1], $at[1])];
                }
                [$w, $h] = match ($kind) {
                    0 => [max($w, $n[0]), $h + $n[1]],
                    1 => [$w + $n[0], max($h, $n[1])],
                    default => [max($w, $n[0]), max($h, $n[1])],
                };
            }
            return match ($kind) {
                0 => [$w + $extraW, $h + $gap + $extraH],
                1 => [$w + $gap + $extraW, $h + $extraH],
                default => [$w + $extraW, $h + $extraH],
            };
        };
    }

    /** The size, border and placement of $node's window from its attributes and style. */
    private function frame(LayoutItem $item, Element $node, ComputedStyle $style, string $width, string $height): LayoutItem
    {
        $item->width = $node->attribute('width') ?? $style->width ?? $width;
        $item->height = $node->attribute('height') ?? $style->height ?? $height;
        $item->maxWidth = $style->maxWidth;
        $item->maxHeight = $style->maxHeight;
        $item->border = $style->border;
        $item->borderStyle = $style->borderStyle;
        $item->shadow = $style->shadow;
        $item->align = $style->align;
        $item->titleAlign = $style->titleAlign;
        $item->margin = $style->margin;
        $item->grow = $style->grow;
        $item->shrink = $style->shrink;
        return $item;
    }

    /** The content of a text element as one string, its spans in their styles. */
    private function content(Text $node, Stylesheet $sheet, ComputedStyle $style): string
    {
        $base = null;
        $content = '';
        $markup = !$node->isRaw();
        foreach ($node->content as $run) {
            if (\is_string($run)) {
                $content .= $markup ? Markup::toAnsi($run, $style, $sheet) : $run;
            } else {
                $base ??= Ansi::style($style->text);
                $span = $sheet->compute($run, $this->widthStates(), $style);
                $content .= Ansi::style($span->text) . ($markup ? Markup::toAnsi($run->text, $span, $sheet) : $run->text) . $base;
            }
        }
        return $content;
    }

    /** A text element: measured by its wrapped lines, painted line by line. */
    private function text(LayoutItem $item, string $content, bool $wrap, string $path, ComputedStyle $style): LayoutItem
    {
        $base = Ansi::style($style->text);
        [$t, $r, $b, $l] = $style->padding;
        $border = $item->border === false ? 0 : 2;
        // the lines for a width, laid out once per content and width (renders that keep the text keep them)
        $what = ($wrap ? "w\0" : "c\0") . $content;
        if (($this->wrapped[$path][0] ?? null) !== $what) {
            $this->wrapped[$path] = [$what, []];
        }
        $lines = function (int $cols) use ($what, $content, $wrap, $path): array {
            if (($this->wrapped[$path][0] ?? null) === $what && isset($this->wrapped[$path][1][$cols])) {
                return $this->wrapped[$path][1][$cols];
            }
            $lines = $wrap
                ? TextUtil::wrap($content, max(1, $cols), true)
                : array_map(fn(string $line) => TextUtil::truncate($line, max(0, $cols)), explode("\n", str_replace("\r\n", "\n", $content)));
            if (($this->wrapped[$path][0] ?? null) === $what) {
                $this->wrapped[$path][1][$cols] = $lines;
            }
            return $lines;
        };
        $widest = function () use ($what, $content, $path): int {
            if (($this->wrapped[$path][0] ?? null) === $what && isset($this->wrapped[$path][1]['widest'])) {
                return $this->wrapped[$path][1]['widest'];
            }
            $widest = 0;
            foreach (explode("\n", $content) as $line) {
                $widest = max($widest, TextUtil::width($line));
            }
            if (($this->wrapped[$path][0] ?? null) === $what) {
                $this->wrapped[$path][1]['widest'] = $widest;
            }
            return $widest;
        };
        $item->measure = static function (int $cols, int $rows) use ($lines, $widest, $t, $r, $b, $l, $border): array {
            $inner = max(1, $cols - $l - $r - $border);
            return [min($cols, $widest() + $l + $r + $border), \count($lines($inner)) + $t + $b + $border];
        };
        $align = $style->align;
        $item->paint = function (Surface $surface) use ($lines, $base, $path, $align, $t, $r, $b, $l, $content): void {
            $cols = $surface->cols();
            $rows = $surface->rows();
            $key = "$cols,$rows,$align,$t,$r,$b,$l,$base,$content";
            if (($this->painted[$path] ?? null) === $key) {
                return;
            }
            $this->painted[$path] = $key;
            $width = max(0, $cols - $l - $r);
            // (no autowrap, no cursor: the surface is the Tree's, nothing else writes to it)
            $out = "\e[?7;25l" . $base . "\e[2J";
            foreach ($lines($width) as $y => $line) {
                if ($t + $y >= $rows - $b) {
                    break;
                }
                $gap = max(0, $width - TextUtil::width($line));
                $x = $l + match ($align) {
                    'right' => $gap,
                    'center' => intdiv($gap, 2),
                    default => 0,
                };
                $out .= Ansi::moveTo($x, $t + $y) . $line;
            }
            $surface->write($out);
        };
        return $item;
    }

    /** The bindings in force, as a Text of "key action" pairs. */
    private function helpText(Help $node, Stylesheet $sheet, ComputedStyle $style): Text
    {
        $this->showsHelp = true;
        $runs = [];
        foreach (($this->help)($node->for, $node->show) as $i => [$key, $action]) {
            $runs[] = $i === 0 ? '' : $node->separator;
            $runs[] = new Span(Markup::escape($key), style: $sheet->compute($node, [], $style, 'key')->properties());
            $runs[] = ' ';
            $runs[] = new Span($action, style: $sheet->compute($node, [], $style, 'action')->properties());
        }
        // one row, cut where it does not fit, unless it is given a height
        return new Text($runs, wrap: $node->attribute('height') !== null || $style->height !== null);
    }


    /** @return list<string> the states of the element at $path, shown in $surface (its own come with it, see Stylesheet) */
    private function states(Element $node, Surface $surface, string $path): array
    {
        $states = [];
        $focused = $node instanceof Component ? $node->hasFocus() : ($surface->viewport() !== null && $surface->hasFocus() && $this->owner->hasFocus());
        if ($focused) {
            $states[] = 'focused';
        }
        if (!$this->dry && ($node->attribute('onFocus') !== null || $node->attribute('onBlur') !== null)) {
            $this->focusing[$path] = [$focused, $node];
        }
        if (Component::pointerOver($surface)) {
            $states[] = 'hover';
        }
        return [...$states, ...$this->widthStates()];
    }

    /**
     * The paths of the elements with `hover:` classes or `:hover` rules that the mouse is over
     * now, as one string: when it changes, the owner lays its tree out again.
     */
    public function hovered(): string
    {
        $over = '';
        foreach ($this->hovers as $path => $surface) {
            if (Component::pointerOver($surface)) {
                $over .= $path . "\0";
            }
        }
        return $over;
    }

    /** True if an element of the last build follows the mouse. */
    public function followsPointer(): bool
    {
        return $this->hovers !== [];
    }

    /**
     * The onFocus/onBlur callbacks that came due in the builds since the last call, with
     * their elements: the owner calls them after its render.
     *
     * @return list<array{\Closure, Element}>
     */
    public function takeFocusCalls(): array
    {
        [$calls, $this->focusCalls] = [$this->focusCalls, []];
        return $calls;
    }

    /** @return list<string> the breakpoints in force on the screen the owner is on (`sm` ... `xl`), as states */
    private function widthStates(): array
    {
        return Stylesheet::breakpoints($this->owner->surface->root()->cols());
    }

    /** An item that shows nothing and needs no room (for what is hidden). */
    private static function nothing(): LayoutItem
    {
        static $nothing = null;
        if ($nothing === null) {
            $nothing = new LayoutItem(new Surface(1, 1, 0));
            $nothing->measure = static fn(): array => [0, 0];
        }
        return $nothing;
    }

    /**
     * An element with a closure for content (a `Text`, a `Box` title): a small component of its
     * own (`Live`) shows it, so the closure is evaluated in that component's render, a `State`
     * it reads renders that component alone, and the tree around it is not built again. The
     * element's size and margin are laid out here, its look inside the component.
     */
    private function live(Text|Box $node, string $path, Stylesheet $sheet, ComputedStyle $parent): ?LayoutItem
    {
        // (its focus is its component's: the states here are those that size and hide it)
        $style = $sheet->compute($node, [...($node->attribute('disabled') ? ['disabled'] : []), ...$this->widthStates()], $parent);
        if ($style->hidden) {
            return null;
        }
        $key = $node->attribute('key');
        $live = ($this->place)($key !== null ? "\0k:" . $key : "\0p:" . $path, new Live($node), !$this->dry);
        if ($live === null || !$live->isMounted()) {
            return new LayoutItem(new Surface(1, 1, 0));
        }
        if (!$this->dry) {
            $live->inheritStyle($parent);
        }
        $sig = 'v' . spl_object_id($live) . '|' . self::sizes($node);
        if (($item = $this->kept($path, $sig, $style)) !== null) {
            return $item;
        }
        $item = $this->componentItem($live, $style, $node);
        return $this->keep($path, $sig, $style, $item);
    }

    /** The surface for the element at $path: the same one as in the previous render. */
    private function surface(string $path): Surface
    {
        $this->seen[$path] = true;
        if (!isset($this->surfaces[$path])) {
            $surface = new Surface(1, 1, 0);
            // :focused of the elements follows the focus
            $surface->on('focus', fn() => $this->owner->markDirty());
            $this->surfaces[$path] = $surface;
        }
        return $this->surfaces[$path];
    }
}
