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
    /** The most cells its content size may take (`max-width`, `max-height`), null for no limit. */
    public ?int $maxWidth = null;
    public ?int $maxHeight = null;
    /** @var array<string, array{int,int}|null> natural() by room, for an item with no component below */
    private array $naturals = [];

    public function __construct(
        public readonly Surface $surface,
        /** The size asked for: cells, '25%', '1fr' or 'auto'. */
        public int|string $width = 'auto',
        public int|string $height = 'auto',
    ) {
    }

    /**
     * The outer size the content needs within $cols x $rows, null when it takes any; capped by
     * `max-width`/`max-height` (an item that takes any room takes up to them).
     */
    public function natural(int $cols, int $rows): ?array
    {
        if (!$this->focusable) {
            // (nothing below changes by itself: the answer for a room stays the answer)
            $key = $cols . ',' . $rows;
            if (\array_key_exists($key, $this->naturals)) {
                return $this->naturals[$key];
            }
            if (\count($this->naturals) > 16) {
                $this->naturals = [];
            }
            return $this->naturals[$key] = $this->measureNow($cols, $rows);
        }
        return $this->measureNow($cols, $rows);
    }

    private function measureNow(int $cols, int $rows): ?array
    {
        $n = $this->measure === null ? null : ($this->measure)(min($cols, $this->maxWidth ?? $cols), min($rows, $this->maxHeight ?? $rows));
        if ($this->maxWidth === null && $this->maxHeight === null) {
            return $n;
        }
        [$w, $h] = $n ?? [$cols, $rows];
        return [min($w, $this->maxWidth ?? $w), min($h, $this->maxHeight ?? $h)];
    }
}
