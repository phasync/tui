<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Style;
use phasync\Tui\Surface;

/**
 * @internal One window of an element layout: the surface that shows an element, how large it
 * wants to be, its frame, and how to fill it (a nested layout, or text painted into it).
 */
final class LayoutItem
{
    /** @var (\Closure(int $cols, int $rows): ?array{int,int})|null the outer size its content needs; null: any */
    public ?\Closure $measure = null;
    /** @var (\Closure(Surface): void)|null draws the element into its surface once it has its size */
    public ?\Closure $paint = null;
    public ?ElementLayout $layout = null;
    public string|false $border = false;
    public ?Style $borderStyle = null;
    public string $title = '';
    public string $align = 'left';
    /** Something in it takes the focus (a component). */
    public bool $focusable = false;

    public function __construct(
        public readonly Surface $surface,
        /** The size asked for: cells, '25%', '1fr' or 'auto'. */
        public int|string $width = 'auto',
        public int|string $height = 'auto',
    ) {
    }

    /** The outer size the content needs within $cols x $rows, null when it takes any. */
    public function natural(int $cols, int $rows): ?array
    {
        return $this->measure === null ? null : ($this->measure)($cols, $rows);
    }
}
