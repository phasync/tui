<?php

namespace phasync\Tui;

/**
 * The part of an image one cell shows: what a cell's style carries (`Style::$image`) when a
 * program placed an image over it.
 *
 * A placement shows a rectangle of an image's pixels (`x`, `y`, `width`, `height`) stretched
 * over `cols` x `rows` cells; the cell at column `col`, row `row` of that grid shows its share
 * (`pixels()`). Fragments are interned like styles: the same placement gives the same objects,
 * so cells copied between Surfaces (`copyArea()`/`drawArea()`) keep them, and two frames that
 * show the same picture in the same cells compare equal with `===`.
 *
 * A glyph written over an image cell replaces the fragment (the glyph wins, as in kitty);
 * erasing drops it; scrolling moves it with its cell. Read a cell's fragment with
 * `$surface->cell($x, $y)->style->image`.
 */
final class ImageFragment
{
    /** @var array<string, \WeakReference<ImageFragment>> */
    private static array $interned = [];
    private static int $purgeAt = 256;
    private static int $serial = 0;
    private static int $live = 0;

    private function __construct(
        /** A number unique to this fragment in the process. */
        public readonly int $id,
        /** The image. */
        public readonly Image $image,
        /** The placement's source rectangle, in pixels of the image: left. */
        public readonly int $x,
        /** Top of the source rectangle. */
        public readonly int $y,
        /** Width of the source rectangle. */
        public readonly int $width,
        /** Height of the source rectangle. */
        public readonly int $height,
        /** Columns the placement spans. */
        public readonly int $cols,
        /** Rows the placement spans. */
        public readonly int $rows,
        /** This cell's column in the placement, 0 to cols - 1. */
        public readonly int $col,
        /** This cell's row in the placement, 0 to rows - 1. */
        public readonly int $row,
        /** The producer's id for the placement (kitty `p=`), 0 for none. */
        public readonly int $placement = 0,
    ) {
        self::$live++;
    }

    public function __destruct()
    {
        self::$live--;
    }

    /** The fragment for cell ($col, $row) of a placement (the same object for the same values). */
    public static function of(Image $image, int $x, int $y, int $width, int $height, int $cols, int $rows, int $col, int $row, int $placement = 0): ImageFragment
    {
        $key = "$image->id:$x,$y,$width,$height:$cols,$rows:$col,$row:$placement";
        $fragment = (self::$interned[$key] ?? null)?->get();
        if ($fragment !== null) {
            return $fragment;
        }
        if (\count(self::$interned) >= self::$purgeAt) {
            self::$interned = array_filter(self::$interned, fn(\WeakReference $r) => $r->get() !== null);
            self::$purgeAt = max(256, 2 * \count(self::$interned));
        }
        $fragment = new ImageFragment(++self::$serial, $image, $x, $y, $width, $height, $cols, $rows, $col, $row, $placement);
        self::$interned[$key] = \WeakReference::create($fragment);
        return $fragment;
    }

    /** @internal True while any fragment exists (a device skips its image pass when none does). */
    public static function exist(): bool
    {
        return self::$live > 0;
    }

    /**
     * The pixels of the image this cell shows, as [x, y, width, height] (rounded to whole
     * pixels): its share of the placement's source rectangle. Two cells show the same thing
     * exactly when their images and `pixels()` are the same, however they were placed.
     *
     * @return array{int, int, int, int}
     */
    public function pixels(): array
    {
        $x0 = $this->x + (int) round($this->col * $this->width / $this->cols);
        $x1 = $this->x + (int) round(($this->col + 1) * $this->width / $this->cols);
        $y0 = $this->y + (int) round($this->row * $this->height / $this->rows);
        $y1 = $this->y + (int) round(($this->row + 1) * $this->height / $this->rows);
        return [$x0, $y0, $x1 - $x0, $y1 - $y0];
    }

    /** @internal True if $next is the cell right of this one in the same placement. */
    public function before(?ImageFragment $next): bool
    {
        return $next !== null && $next->col === $this->col + 1 && $next->row === $this->row && $this->samePlacement($next);
    }

    /** @internal True if $other belongs to the same placement (same image, source rectangle, grid and id). */
    public function samePlacement(ImageFragment $other): bool
    {
        return $other->image === $this->image && $other->x === $this->x && $other->y === $this->y
            && $other->width === $this->width && $other->height === $this->height
            && $other->cols === $this->cols && $other->rows === $this->rows && $other->placement === $this->placement;
    }

    /** The fragment of the same placement at ($col, $row). */
    public function at(int $col, int $row): ImageFragment
    {
        return self::of($this->image, $this->x, $this->y, $this->width, $this->height, $this->cols, $this->rows, $col, $row, $this->placement);
    }

    /**
     * `img=<image id>:<x>,<y>,<width>x<height>`, the image and the pixels the cell shows (see
     * `pixels()`): the notation of `Style::__toString()`, so two dumps are the same when their
     * cells show the same parts of the same images, however they were placed.
     */
    public function __toString(): string
    {
        [$x, $y, $w, $h] = $this->pixels();
        return "img={$this->image->id}:$x,$y,{$w}x$h";
    }
}
