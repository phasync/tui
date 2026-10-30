<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Size;

/**
 * A layout that layers windows on top of each other: the first added is at the bottom, each
 * later one covers it. For overlays, pop-ups and modal dialogs.
 *
 *     $stack = (new Stack())->add($main);                         // fills the host
 *     $root->setLayout($stack);
 *     $stack->add($dialog, width: 40, height: 10);                // centered on top
 *     $root->host($dialog)->setBorder(true)->setTitle('Save?');
 *     $dialog->focus();                                           // keys go to the dialog
 *     ...
 *     $stack->remove($dialog);                                    // what was below shows again
 *
 * Sizes are cells (int), a share of the host (`'50%'`); the default `'100%'` fills it. A window
 * is placed by `$align` (`center`, `top`, `bottom`, `left`, `right`, `top-left`, `top-right`,
 * `bottom-left`, `bottom-right`) or at an explicit `$x`/`$y`. Surfaces are kept the size of their
 * windows (`Viewport::setFit()`). A layout can be added where a surface would go: the stack shows
 * it in a surface of its own (see `Split`), for a dialog made of several windows.
 */
final class Stack implements Layout
{
    private const ALIGN = ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /** @var list<array{Surface|Layout, int|string, int|string, string, ?int, ?int}> */
    private array $items = [];
    private ?Surface $host = null;

    /**
     * Put $item, a surface or a layout to nest, on top of the stack (or change how an item already
     * in it is placed).
     *
     * @param int|string $width  cells, or a share of the host such as `'50%'`
     * @param int|string $height cells, or a share of the host such as `'50%'`
     * @param string     $align  where the window goes when $x/$y are not given
     * @param int|null   $x      left column, overriding the horizontal part of $align
     * @param int|null   $y      top row, overriding the vertical part of $align
     */
    public function add(Surface|Layout $item, int|string $width = '100%', int|string $height = '100%', string $align = 'center', ?int $x = null, ?int $y = null): static
    {
        Size::parse($width);
        Size::parse($height);
        if (!\in_array($align, self::ALIGN, true)) {
            throw new \InvalidArgumentException("Unknown alignment '$align'");
        }
        $entry = [$item, $width, $height, $align, $x, $y];
        foreach ($this->items as $i => [$s]) {
            if ($s === $item) {
                $this->items[$i] = $entry;
                return $this->rearrange();
            }
        }
        $this->items[] = $entry;
        return $this->rearrange();
    }

    /** Take $item (a surface or a nested layout) off the stack; what it covered shows again. */
    public function remove(Surface|Layout $item): static
    {
        $this->items = array_values(array_filter($this->items, fn(array $entry) => $entry[0] !== $item));
        return $this->rearrange();
    }

    /**
     * The surfaces and nested layouts, bottom first.
     *
     * @return list<Surface|Layout>
     */
    public function items(): array
    {
        return array_map(fn(array $item) => $item[0], $this->items);
    }

    /**
     * The surface this stack arranges: the one it was set on, or for a stack nested in another
     * layout, the surface made for it. Null until it is first arranged.
     */
    public function host(): ?Surface
    {
        return $this->host;
    }

    private function rearrange(): static
    {
        if ($this->host !== null && $this->host->layout() === $this) {
            $this->host->setLayout($this);
        }
        return $this;
    }

    /** Place the windows on $host for its size; the host calls this (see `Layout`). */
    public function arrange(Surface $host): void
    {
        $this->host = $host;
        $cols = $host->cols();
        $rows = $host->rows();
        $order = [];
        $nested = 0;
        foreach ($this->items as [$item, $width, $height, $align, $x, $y]) {
            $surface = $item instanceof Layout ? $host->nestedSurface($nested++, $item) : $item;
            $order[] = $surface;
            $w = self::measure($width, $cols);
            $h = self::measure($height, $rows);
            $x ??= match (true) {
                str_contains($align, 'left') => 0,
                str_contains($align, 'right') => $cols - $w,
                default => intdiv($cols - $w, 2),
            };
            $y ??= match (true) {
                str_starts_with($align, 'top') => 0,
                str_starts_with($align, 'bottom') => $rows - $h,
                default => intdiv($rows - $h, 2),
            };
            $host->host($surface)->move($x, $y)->resize($w, $h)->setFit(true);
        }
        // hosting order is z-order: keep it the stack's order
        $current = array_values(array_filter(array_map(fn(Viewport $v) => $v->source, $host->viewports()), fn(Surface $s) => \in_array($s, $order, true)));
        if ($current !== $order) {
            foreach ($order as $surface) {
                $host->raise($surface);
            }
        }
    }

    private static function measure(int|string $size, int $total): int
    {
        [$kind, $value] = Size::parse($size);
        return min($total, $kind === 'cells' ? (int) $value : (int) floor($total * ($kind === 'percent' ? $value : 100) / 100));
    }
}
