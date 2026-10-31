<?php

namespace phasync\Tui;

/**
 * A rectangle of cells copied out of a Surface: what `Surface::copyArea()` returns and
 * `Surface::drawArea()` puts somewhere else. It is how cells go from one Surface to another
 * without being written as escape sequences again: the compositor that shows hosted surfaces
 * on their host copies cells this way, and a program can too (a snapshot of a pane, a
 * thumbnail, a frame kept to show again).
 *
 *     $snapshot = $pane->copyArea(0, 0, $pane->cols(), $pane->rows());
 *     $other->drawArea($snapshot, 2, 1);
 *
 * Cells only ever get onto a Surface through `write()`, so an Area holds what some producer
 * wrote; there is no way to make one cell by cell. A wide character cut by the rectangle's
 * edge is a blank in the Area (half a character cannot be shown), as it is on a terminal.
 */
final class Area
{
    /**
     * @internal Areas are made by `Surface::copyArea()`.
     *
     * @param list<list<string>> $chars  each row's graphemes, '' for the second cell of a wide character
     * @param list<list<Style>>  $styles each row's styles, parallel to $chars
     */
    public function __construct(
        /** Width in cells. */
        public readonly int $width,
        /** Height in rows. */
        public readonly int $height,
        /** @var list<list<string>> each row's graphemes ('' for the second cell of a wide character) */
        public readonly array $chars,
        /** @var list<list<Style>> each row's styles, parallel to `chars` */
        public readonly array $styles,
    ) {
    }

    /** The rows as text, trailing blanks cut, joined with "\n". */
    public function text(): string
    {
        return implode("\n", array_map(fn(array $row) => rtrim(implode('', $row), ' '), $this->chars));
    }

    /**
     * The same cells as they look under a shadow: dimmed, on the background $color
     * (`Color::DEFAULT`: dimmed only, in their own colours). The compositor shades the windows
     * under a dialog's shadow this way.
     */
    public function shaded(int $color = Color::DEFAULT): Area
    {
        $styles = [];
        foreach ($this->styles as $row) {
            $styles[] = array_map(fn(Style $s) => self::shade($s, $color), $row);
        }
        return new Area($this->width, $this->height, $this->chars, $styles);
    }

    /** @internal $style under a shadow of $color. */
    public static function shade(Style $style, int $color): Style
    {
        $style = $style->with(Style::DIM);
        return $color === Color::DEFAULT ? $style : $style->withBg($color);
    }

    /**
     * @internal The cells $from to $from + $n - 1 of a row ($chars, $styles), blank where the row
     * has none, a wide character cut at either end made a blank.
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     * @return array{list<string>, list<Style>}
     */
    public static function cut(array $chars, array $styles, int $from, int $n): array
    {
        $len = \count($chars);
        if ($n <= 0) {
            return [[], []];
        }
        if ($from === 0 && $n === $len) {
            $c = $chars;
            $s = $styles;
        } elseif ($from >= 0 && $from + $n <= $len) {
            $c = \array_slice($chars, $from, $n);
            $s = \array_slice($styles, $from, $n);
        } else {
            $plain = Style::plain();
            $c = $s = [];
            for ($i = $from, $end = $from + $n; $i < $end; $i++) {
                $c[] = $chars[$i] ?? ' ';
                $s[] = $styles[$i] ?? $plain;
            }
        }
        if ($c[0] === '') {
            $c[0] = ' ';
        }
        if ($c[$n - 1] !== '' && ($chars[$from + $n] ?? null) === '') {
            $c[$n - 1] = ' ';
        }
        return [$c, $s];
    }

    /**
     * @internal Put cells into a row ($dc, $ds) at column $x, which they fit in: a wide
     * character of the row they cut in half becomes a blank, as when a terminal prints over half
     * of one.
     *
     * @param list<string> $dc
     * @param list<Style>  $ds
     * @param list<string> $c
     * @param list<Style>  $s
     */
    public static function put(array &$dc, array &$ds, int $x, array $c, array $s): void
    {
        $n = \count($c);
        if ($n === 0) {
            return;
        }
        if ($x === 0 && $n === \count($dc)) {
            $dc = $c;
            $ds = $s;
            return;
        }
        if ($x > 0 && $dc[$x] === '') {
            $dc[$x - 1] = ' ';
        }
        if (($dc[$x + $n] ?? null) === '') {
            $dc[$x + $n] = ' ';
        }
        if ($n > 48) {
            array_splice($dc, $x, $n, $c);
            array_splice($ds, $x, $n, $s);
            return;
        }
        // (a few cells: in place, rather than a new row)
        foreach ($c as $i => $ch) {
            $dc[$x + $i] = $ch;
            $ds[$x + $i] = $s[$i];
        }
    }
}
