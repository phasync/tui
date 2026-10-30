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
use phasync\Tui\Stylesheet;
use phasync\Tui\Surface;
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

    /**
     * @param \Closure(string $key, Component $fresh): Component $place  the child component for a component element
     * @param \Closure(?Component $for): list<array{string, string}> $help the bindings for a Help element
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
        if ($root instanceof Column || $root instanceof Row || $root instanceof Layers) {
            // the root container arranges the component's own surface
            $style = $sheet->compute($root, $this->states($root, $this->owner->surface), $base);
            $layout = $this->container($root, 'r', $sheet, $style);
        } else {
            $layout = new ElementLayout(ElementLayout::SINGLE, [$this->item($root, 'r', $sheet, $base)], fill: $base->text);
        }
        foreach ($this->surfaces as $path => $surface) {
            if (!isset($this->seen[$path])) {
                unset($this->surfaces[$path], $this->painted[$path]);
                $surface->giveUp();
            }
        }
        return $layout;
    }

    private function container(Column|Row|Layers $node, string $path, Stylesheet $sheet, ComputedStyle $style): ElementLayout
    {
        $items = [];
        foreach ($node->children as $i => $child) {
            $items[] = $this->item($child, $path . '/' . self::segment($child, $i), $sheet, $style);
        }
        return new ElementLayout(match (true) {
            $node instanceof Column => ElementLayout::COLUMN,
            $node instanceof Row => ElementLayout::ROW,
            default => ElementLayout::LAYERS,
        }, $items, $node instanceof Layers ? 0 : $node->gap, fill: $style->text);
    }

    private static function segment(Element $node, int $i): string
    {
        $key = $node->attribute('key');
        return $key !== null ? 'k:' . $key : $i . ':' . $node::class;
    }

    /** The window that shows $node. */
    private function item(Element $node, string $path, Stylesheet $sheet, ComputedStyle $parent): LayoutItem
    {
        if ($node instanceof Component) {
            $component = $node->isMounted() ? $node : ($this->place)("\0" . ($node->attribute('key') ?? $path), $node);
            $component->inheritStyle($parent);
            $style = $component->style();
            $item = $this->frame(new LayoutItem($component->surface), $component, $style, 'auto', 'auto');
            $item->focusable = true;
            $border = $item->border === false ? 0 : 2;
            $item->measure = static function (int $cols, int $rows) use ($component, $border): ?array {
                $n = $component->measureFor(max(0, $cols - $border), max(0, $rows - $border));
                return $n === null ? null : [$n[0] + $border, $n[1] + $border];
            };
            return $item;
        }
        if ($node instanceof Span) {
            $node = new Text([$node]);
        }
        $surface = $this->surface($path);
        $style = $sheet->compute($node, $this->states($node, $surface), $parent);
        if ($node instanceof Text || $node instanceof Help) {
            $surface->setFocusable(false);
            $text = $node instanceof Help ? $this->helpText($node, $sheet, $style) : $node;
            return $this->text($this->frame(new LayoutItem($surface), $node, $style, 'auto', 'auto'), $text, $path, $sheet, $style);
        }
        if ($node instanceof Box) {
            $item = $this->frame(new LayoutItem($surface), $node, $style, 'auto', 'auto');
            $item->title = $node->title;
            $inner = $this->item($node->child, $path . '/' . self::segment($node->child, 0), $sheet, $style);
            $item->layout = new ElementLayout(ElementLayout::SINGLE, [$inner], padding: $style->padding, fill: $style->text);
            $item->focusable = $inner->focusable;
            $surface->setFocusable($item->focusable);
            [$t, $r, $b, $l] = $style->padding;
            $extraW = $l + $r + ($item->border === false ? 0 : 2);
            $extraH = $t + $b + ($item->border === false ? 0 : 2);
            $item->measure = static function (int $cols, int $rows) use ($inner, $extraW, $extraH): ?array {
                $n = $inner->natural(max(0, $cols - $extraW), max(0, $rows - $extraH));
                return $n === null ? null : [$n[0] + $extraW, $n[1] + $extraH];
            };
            return $item;
        }
        if ($node instanceof Column || $node instanceof Row || $node instanceof Layers) {
            $item = $this->frame(new LayoutItem($surface), $node, $style, '1fr', '1fr');
            $item->layout = $this->container($node, $path, $sheet, $style);
            foreach ($item->layout->items as $child) {
                $item->focusable = $item->focusable || $child->focusable;
            }
            $surface->setFocusable($item->focusable);
            $border = $item->border === false ? 0 : 2;
            $items = $item->layout->items;
            $gap = $node instanceof Layers ? 0 : $node->gap * max(0, \count($items) - 1);
            $item->measure = static function (int $cols, int $rows) use ($node, $items, $border, $gap): ?array {
                $w = $h = 0;
                foreach ($items as $child) {
                    $n = $child->natural(max(0, $cols - $border), max(0, $rows - $border));
                    if ($n === null) {
                        return null;
                    }
                    [$w, $h] = match (true) {
                        $node instanceof Column => [max($w, $n[0]), $h + $n[1]],
                        $node instanceof Row => [$w + $n[0], max($h, $n[1])],
                        default => [max($w, $n[0]), max($h, $n[1])],
                    };
                }
                return $node instanceof Column ? [$w + $border, $h + $gap + $border] : ($node instanceof Row ? [$w + $gap + $border, $h + $border] : [$w + $border, $h + $border]);
            };
            return $item;
        }
        throw new \InvalidArgumentException('Unknown element ' . $node::class);
    }

    /** The size, border and placement of $node's window from its attributes and style. */
    private function frame(LayoutItem $item, Element $node, ComputedStyle $style, string $width, string $height): LayoutItem
    {
        $item->width = $node->attribute('width') ?? $style->width ?? $width;
        $item->height = $node->attribute('height') ?? $style->height ?? $height;
        $item->border = $style->border;
        $item->borderStyle = $style->borderStyle;
        $item->align = $style->align;
        return $item;
    }

    /** A text element: measured by its wrapped lines, painted line by line. */
    private function text(LayoutItem $item, Text $node, string $path, Stylesheet $sheet, ComputedStyle $style): LayoutItem
    {
        $base = Ansi::style($style->text);
        $content = '';
        foreach ($node->content as $run) {
            $content .= \is_string($run)
                ? $run
                : Ansi::style($sheet->compute($run, [], $style)->text) . $run->text . $base;
        }
        $wrap = $node->wrap;
        [$t, $r, $b, $l] = $style->padding;
        $border = $item->border === false ? 0 : 2;
        $lines = static function (int $cols) use ($content, $wrap): array {
            if ($wrap) {
                return TextUtil::wrap($content, max(1, $cols), true);
            }
            return array_map(fn(string $line) => TextUtil::truncate($line, max(0, $cols)), explode("\n", str_replace("\r\n", "\n", $content)));
        };
        $item->measure = static function (int $cols, int $rows) use ($lines, $content, $t, $r, $b, $l, $border): array {
            $inner = max(1, $cols - $l - $r - $border);
            $widest = 0;
            foreach (explode("\n", $content) as $line) {
                $widest = max($widest, TextUtil::width($line));
            }
            return [min($cols, $widest + $l + $r + $border), \count($lines($inner)) + $t + $b + $border];
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
            $out = "\e[0m\e[?7l" . $base . "\e[2J";
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
            $surface->write($out . "\e[0m\e[?7h\e[?25l");
        };
        return $item;
    }

    /** The bindings in force, as a Text of "key action" pairs. */
    private function helpText(Help $node, Stylesheet $sheet, ComputedStyle $style): Text
    {
        $this->showsHelp = true;
        $runs = [];
        foreach (($this->help)($node->for) as $i => [$key, $action]) {
            $runs[] = $i === 0 ? '' : $node->separator;
            $runs[] = new Span($key, style: self::inline($sheet->compute($node, [], $style, 'key')));
            $runs[] = ' ';
            $runs[] = new Span($action, style: self::inline($sheet->compute($node, [], $style, 'action')));
        }
        return new Text($runs);
    }

    /** A computed style as inline properties (for spans made from parts). */
    private static function inline(ComputedStyle $c): array
    {
        $s = $c->text;
        $props = ['color' => $s->fg, 'background' => $s->bg];
        foreach (['bold' => 1, 'dim' => 2, 'italic' => 4, 'underline' => 8, 'inverse' => 32, 'strike' => 128] as $name => $bit) {
            $props[$name] = ($s->attrs & $bit) !== 0;
        }
        return $props;
    }

    /** @return list<string> */
    private function states(Element $node, Surface $surface): array
    {
        $states = [];
        $focused = $node instanceof Component ? $node->hasFocus() : ($surface->viewport() !== null && $surface->hasFocus() && $this->owner->hasFocus());
        if ($focused) {
            $states[] = 'focused';
        }
        if ($node->attribute('disabled')) {
            $states[] = 'disabled';
        }
        return $states;
    }

    /** The surface for the element at $path: the same one as in the previous render. */
    private function surface(string $path): Surface
    {
        $this->seen[$path] = true;
        if (!isset($this->surfaces[$path])) {
            $surface = new Surface(1, 1, 0);
            // :focused of the elements follows the focus
            $surface->on('focus', fn() => $this->owner->requestRender());
            $this->surfaces[$path] = $surface;
        }
        return $this->surfaces[$path];
    }
}
