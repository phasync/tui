<?php

namespace phasync\Tui\Component;

use phasync\Canvas\ColorParser;
use phasync\Canvas\Context;
use phasync\Canvas\Gradient;
use phasync\Canvas\RendererInterface;
use phasync\Tui\Color;
use phasync\Tui\Text;

/**
 * The drawing context a `Canvas` gives its `draw:` closure: the browser's
 * `CanvasRenderingContext2D` of phasync/canvas (paths, fills, strokes, gradients, transforms,
 * `drawImage()`; see its README), measured in cells, plus what a terminal adds:
 *
 * - **Units are cells.** `(0, 0)` is the top-left corner of the canvas, `(width(), height())`
 *   its bottom-right; `(3.5, 1.25)` is a point inside the cell at column 3, row 1. A cell is
 *   about twice as tall as wide, so a circle of radius 4 is 4 cells across and 4 rows high,
 *   which shows twice as tall as wide: draw `ellipse($x, $y, $r, $r / 2, ...)` for a round one.
 *   The context's own `scale()`, `translate()`, `rotate()` and `setTransform()` work in these
 *   units (a graph often begins with `$c->translate(0, $c->height()); $c->scale(1, -1)`).
 * - `resolution()`: how finely it draws, in dots (or pixels, on the `pixels` marker) per cell,
 *   for a drawing that wants to put one dot exactly. `marker()` is the marker in use.
 * - **Text is real text.** `fillText()` and `strokeText()` put the characters in the cells
 *   nearest the point, one cell high, in the `fillStyle` colour (as a terminal can show text
 *   on every marker, axis labels stay readable); `textAlign` and `textBaseline` place them as
 *   in a browser. `measureText()` gives their width in cells.
 * - `lineWidth` is in the canvas's units too, as in a browser: `0.25` is a line one Braille
 *   dot wide (a dot is a quarter of a cell's width), `1` a cell wide.
 */
final class CanvasContext extends Context
{
    /** @var array{float, float, float, float, float, float} the drawing's own transform, in cells (a, b, c, d, e, f) */
    private array $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    /** @var list<array{float, float, float, float, float, float}> */
    private array $saved = [];
    /** @var list<array{int, int, string, int}> @internal text to put in the cells: column, row, text, colour */
    public array $texts = [];

    /**
     * @internal Made by `Canvas` for each drawing.
     *
     * @param array{int, int} $pixels    pixels of the renderer per cell
     * @param array{int, int} $resolution dots (or pixels) per cell of the marker
     */
    public function __construct(
        RendererInterface $surface,
        private readonly int $cols,
        private readonly int $rows,
        private readonly array $pixels,
        private readonly array $resolution,
        private readonly string $marker,
        private readonly int $color,
    ) {
        parent::__construct($surface);
        parent::setTransform($pixels[0], 0, 0, $pixels[1], 0, 0);
        [$r, $g, $b] = $color === Color::DEFAULT ? [229, 229, 229] : Color::toRgb($color);
        $this->fillStyle = $this->strokeStyle = sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** The canvas's width in cells: x runs from 0 to this. */
    public function width(): int
    {
        return $this->cols;
    }

    /** The canvas's height in cells: y runs from 0 to this. */
    public function height(): int
    {
        return $this->rows;
    }

    /**
     * [x, y]: how many dots a cell holds on this marker (`braille` [2, 4], `quarter` [2, 2],
     * `half` [1, 2], `block` [1, 1]), or how many pixels on `pixels` (the cell size).
     *
     * @return array{int, int}
     */
    public function resolution(): array
    {
        return $this->resolution;
    }

    /** The marker it draws with: `braille`, `quarter`, `half`, `block` or `pixels` (never `auto`). */
    public function marker(): string
    {
        return $this->marker;
    }

    public function save(): void
    {
        parent::save();
        $this->saved[] = $this->matrix;
    }

    public function restore(): void
    {
        parent::restore();
        if ($this->saved !== []) {
            $this->matrix = array_pop($this->saved);
        }
    }

    public function translate(float $x, float $y): void
    {
        parent::translate($x, $y);
        $this->multiply(1, 0, 0, 1, $x, $y);
    }

    public function scale(float $x, float $y): void
    {
        parent::scale($x, $y);
        $this->multiply($x, 0, 0, $y, 0, 0);
    }

    public function rotate(float $angle): void
    {
        parent::rotate($angle);
        $this->multiply(cos($angle), sin($angle), -sin($angle), cos($angle), 0, 0);
    }

    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        parent::transform($a, $b, $c, $d, $e, $f);
        $this->multiply($a, $b, $c, $d, $e, $f);
    }

    /** Set the transform, in cells (the canvas keeps cells its units under it). */
    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        [$px, $py] = $this->pixels;
        parent::setTransform($px * $a, $py * $b, $px * $c, $py * $d, $px * $e, $py * $f);
        $this->matrix = [$a, $b, $c, $d, $e, $f];
    }

    /** Back to plain cells. */
    public function resetTransform(): void
    {
        $this->setTransform(1, 0, 0, 1, 0, 0);
    }

    /**
     * A hairline: a stroke thinner than a dot in some direction. A browser draws it faint
     * (a half-dot line is two dots at 25%), which a terminal cannot show. So a path of lines
     * (no curves, no rotation in the transform) is stroked snapped to the dot grid: a point is
     * moved to a dot's centre across each line it belongs to (a horizontal line keeps its x
     * extent and sits on one dot row), and the stroke is at least half a dot wide, which lights
     * exactly one dot. The path is kept as it was for a `fill()` after.
     */
    private function hairline(): bool
    {
        [$a, $b, $c, $d] = $this->matrix;
        [$rx, $ry] = $this->pixels;
        return $b == 0.0 && $c == 0.0 && $this->lineWidth * min(abs($a) * $rx, abs($d) * $ry) < 1.0;
    }

    /** @var list<array{string, float, float}> the path's commands since beginPath (M, L, Z) while it has only lines */
    private array $lines = [];
    private bool $onlyLines = true;

    public function beginPath(): void
    {
        parent::beginPath();
        $this->lines = [];
        $this->onlyLines = true;
    }

    public function moveTo(float $x, float $y): void
    {
        parent::moveTo($x, $y);
        $this->lines[] = ['M', $x, $y];
    }

    public function lineTo(float $x, float $y): void
    {
        parent::lineTo($x, $y);
        $this->lines[] = ['L', $x, $y];
    }

    public function closePath(): void
    {
        parent::closePath();
        $this->lines[] = ['Z', 0.0, 0.0];
    }

    public function arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        parent::arc($x, $y, $radius, $startAngle, $endAngle, $counterclockwise);
        $this->onlyLines = false;
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        parent::arcTo($x1, $y1, $x2, $y2, $radius);
        $this->onlyLines = false;
    }

    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): void
    {
        parent::bezierCurveTo($cp1x, $cp1y, $cp2x, $cp2y, $x, $y);
        $this->onlyLines = false;
    }

    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): void
    {
        parent::quadraticCurveTo($cpx, $cpy, $x, $y);
        $this->onlyLines = false;
    }

    public function ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        parent::ellipse($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $counterclockwise);
        $this->onlyLines = false;
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        parent::rect($x, $y, $w, $h);
        $this->onlyLines = false;
    }

    public function stroke(): void
    {
        if (!$this->hairline() || !$this->onlyLines || $this->lines === []) {
            parent::stroke();
            return;
        }
        [$a, , , $d, $e, $f] = $this->matrix;
        [$rx, $ry] = $this->pixels;
        // Which axis each point snaps on: x across a vertical line, y across a horizontal one,
        // both on a diagonal.
        $n = \count($this->lines);
        $snapX = $snapY = array_fill(0, $n, false);
        $prev = null;
        foreach ($this->lines as $i => [$op, $x, $y]) {
            if ($op === 'L' && $prev !== null) {
                [$j, $px, $py] = $prev;
                $vertical = abs($x - $px) < 1e-9;
                $horizontal = abs($y - $py) < 1e-9;
                $snapX[$i] = $snapX[$j] = $snapX[$i] || $snapX[$j] || !$horizontal;
                $snapY[$i] = $snapY[$j] = $snapY[$i] || $snapY[$j] || !$vertical;
            }
            $prev = $op === 'Z' ? null : [$i, $x, $y];
        }
        $width = $this->lineWidth;
        parent::beginPath();
        foreach ($this->lines as $i => [$op, $x, $y]) {
            if ($op === 'Z') {
                parent::closePath();
                continue;
            }
            if ($snapX[$i]) {
                $x = ((floor(($a * $x + $e) * $rx) + 0.5) / $rx - $e) / $a;
            }
            if ($snapY[$i]) {
                $y = ((floor(($d * $y + $f) * $ry) + 0.5) / $ry - $f) / $d;
            }
            $op === 'M' ? parent::moveTo($x, $y) : parent::lineTo($x, $y);
        }
        $this->lineWidth = max($width, 0.5 / (abs($a) * $rx), 0.5 / (abs($d) * $ry));
        parent::stroke();
        $this->lineWidth = $width;
        // the path as the drawing built it, for a fill() after
        parent::beginPath();
        foreach ($this->lines as [$op, $x, $y]) {
            match ($op) { 'M' => parent::moveTo($x, $y), 'L' => parent::lineTo($x, $y), 'Z' => parent::closePath() };
        }
    }

    /** Put $text in the cells nearest ($x, $y), in the fill colour (see the class description); `$maxWidth` cuts it, in cells. */
    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        [$a, $b, $c, $d, $e, $f] = $this->matrix;
        $cx = $a * $x + $c * $y + $e;
        $cy = $b * $x + $d * $y + $f;
        if ($maxWidth !== null) {
            $text = Text::truncate($text, max(0, (int) floor($maxWidth * abs($a))), '');
        }
        $width = Text::width($text);
        $col = (int) round(match ($this->textAlign) {
            'center' => $cx - $width / 2,
            'end', 'right' => $cx - $width,
            default => $cx,
        });
        $row = (int) match ($this->textBaseline) {
            'top', 'hanging' => round($cy),
            'middle' => floor($cy),
            default => round($cy) - 1,
        };
        $this->texts[] = [$col, $row, $text, self::colorOf($this->fillStyle, $this->color)];
    }

    /** As `fillText()`: a terminal's characters have no outline. */
    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->fillText($text, $x, $y, $maxWidth);
    }

    /** The width of $text in the canvas's units (cells, through the transform's horizontal scale). */
    public function measureText(string $text): array
    {
        $scale = abs($this->matrix[0]) ?: 1.0;
        return ['width' => Text::width($text) / $scale];
    }

    /** this = this x [a b c d e f] (the browser's transform() order). */
    private function multiply(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        [$ta, $tb, $tc, $td, $te, $tf] = $this->matrix;
        $this->matrix = [
            $ta * $a + $tc * $b, $tb * $a + $td * $b,
            $ta * $c + $tc * $d, $tb * $c + $td * $d,
            $ta * $e + $tc * $f + $te, $tb * $e + $td * $f + $tf,
        ];
    }

    /** A colour of the library for a CSS colour (a gradient: its middle; one that does not parse: $default). */
    private static function colorOf(string|Gradient $style, int $default): int
    {
        if (!\is_string($style)) {
            return $default;
        }
        try {
            $c = ColorParser::parseColor($style);
        } catch (\Throwable) {
            return $default;
        }
        return Color::rgb((int) $c['r'], (int) $c['g'], (int) $c['b']);
    }
}
