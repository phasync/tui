<?php

namespace phasync\Tui\Surface;

use phasync\Tui\Style;

/**
 * @internal One row of cells: parallel arrays of graphemes and styles. A blank cell is ' ', the
 * continuation cell after a wide character is ''.
 */
final class Line
{
    /** @var list<string> */
    public array $chars;
    /** @var list<Style> */
    public array $styles;
    /** True when the text continues on the next row because of autowrap. */
    public bool $wrapped = false;

    public function __construct(int $cols, Style $style)
    {
        $this->chars = $cols > 0 ? array_fill(0, $cols, ' ') : [];
        $this->styles = $cols > 0 ? array_fill(0, $cols, $style) : [];
    }

    /** Blank the cells from $from to $to inclusive, repairing wide characters cut at the edges. */
    public function erase(int $from, int $to, Style $style): void
    {
        $cols = \count($this->chars);
        if ($from < 0) {
            $from = 0;
        }
        if ($to >= $cols) {
            $to = $cols - 1;
        }
        if ($from > $to) {
            return;
        }
        if ($this->chars[$from] === '' && $from > 0) {
            $this->chars[$from - 1] = ' ';
        }
        if ($to + 1 < $cols && $this->chars[$to + 1] === '') {
            $this->chars[$to + 1] = ' ';
        }
        for ($i = $from; $i <= $to; $i++) {
            $this->chars[$i] = ' ';
            $this->styles[$i] = $style;
        }
    }

    /** Change the width, cutting or padding at the right. */
    public function resize(int $cols, Style $blank): void
    {
        $old = \count($this->chars);
        if ($cols < $old) {
            $cutWide = $cols > 0 && $this->chars[$cols] === '';
            $this->chars = \array_slice($this->chars, 0, $cols);
            $this->styles = \array_slice($this->styles, 0, $cols);
            if ($cutWide) {
                $this->chars[$cols - 1] = ' ';
            }
            $this->wrapped = false;
        } elseif ($cols > $old) {
            for ($i = $old; $i < $cols; $i++) {
                $this->chars[] = ' ';
                $this->styles[] = $blank;
            }
        }
    }
}
