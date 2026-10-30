<?php

namespace phasync\Tui;

use phasync\Tui\Internal\Size;

/**
 * A layout that divides its host into windows side by side (`horizontal()`, left to right) or on
 * top of each other (`vertical()`, top to bottom), optionally with a one-cell separator line
 * between them.
 *
 *     $root->setLayout(Split::horizontal(separators: true)
 *         ->add($files, 30)          // 30 columns
 *         ->add($editor)             // the rest ('1fr')
 *         ->add($log, '25%'));       // a quarter of the width
 *
 * Every surface is kept the size of its window (`Viewport::setFit()`), so a program in it (a
 * shell in a `Pty`) is resized with the host. Borders and titles are set on the viewports:
 * `$root->host($files)->setBorder(true)->setTitle('files')`. To nest, give a hosted surface a
 * layout of its own.
 */
final class Split implements Layout
{
    public const HORIZONTAL = 'horizontal';
    public const VERTICAL = 'vertical';

    /** @var list<array{Surface, int|string}> */
    private array $items = [];
    private ?Surface $host = null;
    private Style $separatorStyle;

    /**
     * @param string     $direction  `Split::HORIZONTAL` (side by side) or `Split::VERTICAL` (stacked)
     * @param bool       $separators draw a line between the windows
     * @param Style|null $style      the style of the separator lines
     */
    public function __construct(
        public readonly string $direction = self::HORIZONTAL,
        private readonly bool $separators = false,
        ?Style $style = null,
    ) {
        if ($direction !== self::HORIZONTAL && $direction !== self::VERTICAL) {
            throw new \InvalidArgumentException("Unknown direction '$direction'");
        }
        $this->separatorStyle = $style ?? Style::plain();
    }

    /** Windows side by side, left to right. */
    public static function horizontal(bool $separators = false, ?Style $style = null): self
    {
        return new self(self::HORIZONTAL, $separators, $style);
    }

    /** Windows on top of each other, top to bottom. */
    public static function vertical(bool $separators = false, ?Style $style = null): self
    {
        return new self(self::VERTICAL, $separators, $style);
    }

    /**
     * Add $surface as the next window. $size is its width (horizontal) or height (vertical):
     * cells as an int, `'25%'` of the host, or `'2fr'` for a weighted share of what is left.
     * Adding a surface that is already in the split changes its size.
     */
    public function add(Surface $surface, int|string $size = '1fr'): static
    {
        Size::parse($size);
        foreach ($this->items as $i => [$s]) {
            if ($s === $surface) {
                $this->items[$i][1] = $size;
                return $this->rearrange();
            }
        }
        $this->items[] = [$surface, $size];
        return $this->rearrange();
    }

    /** Take $surface out of the split; the others share its space. */
    public function remove(Surface $surface): static
    {
        $this->items = array_values(array_filter($this->items, fn(array $item) => $item[0] !== $surface));
        return $this->rearrange();
    }

    /**
     * The surfaces in order.
     *
     * @return list<Surface>
     */
    public function surfaces(): array
    {
        return array_map(fn(array $item) => $item[0], $this->items);
    }

    private function rearrange(): static
    {
        if ($this->host !== null && $this->host->layout() === $this) {
            $this->host->setLayout($this);
        }
        return $this;
    }

    public function arrange(Surface $host): void
    {
        $this->host = $host;
        $n = \count($this->items);
        if ($n === 0) {
            return;
        }
        $across = $this->direction === self::HORIZONTAL;
        $total = $across ? $host->cols() : $host->rows();
        $gap = $this->separators ? 1 : 0;
        $sizes = Size::distribute(array_map(fn(array $item) => Size::parse($item[1]), $this->items), max(0, $total - $gap * ($n - 1)));
        $at = 0;
        $lines = [];
        foreach ($this->items as $i => [$surface]) {
            $view = $host->host($surface);
            if ($across) {
                $view->move($at, 0)->resize($sizes[$i], $host->rows());
            } else {
                $view->move(0, $at)->resize($host->cols(), $sizes[$i]);
            }
            $view->setFit(true);
            $at += $sizes[$i];
            if ($gap && $i < $n - 1) {
                $lines[] = $at;
                $at += $gap;
            }
        }
        if ($lines !== []) {
            $out = Ansi::style($this->separatorStyle);
            foreach ($lines as $p) {
                if ($across) {
                    for ($y = 0; $y < $host->rows(); $y++) {
                        $out .= Ansi::moveTo($p, $y) . '│';
                    }
                } else {
                    $out .= Ansi::moveTo(0, $p) . str_repeat('─', $host->cols());
                }
            }
            $host->write("\e7\e[?6l" . $out . "\e[0m\e8");
        }
    }
}
