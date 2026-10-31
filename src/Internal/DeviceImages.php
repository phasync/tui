<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Image;
use phasync\Tui\ImageFragment;
use phasync\Tui\Style;

/**
 * @internal What a terminal device does with the image cells of its frames, by the terminal's
 * protocol:
 *
 * - `kitty`: after the text of a frame, the image cells are grouped into rectangles of
 *   contiguous fragments of one placement; each rectangle not on the terminal yet is placed
 *   (`a=p` with its source rectangle and cell size, the cursor kept), each one on the terminal
 *   that is no longer in the frame is deleted. An image is transmitted once (by its id) and
 *   deleted from the terminal when no cell shows it any more.
 * - `iterm2`: it cannot place parts of an image, and an image's cells are replaced by what is
 *   written over them, as cells are. So only the image cells that changed are painted, a
 *   rectangle at a time: cropped through GD, or without GD the whole placement when the frame
 *   shows all of it.
 * - `none`: image cells are drawn as quarter blocks of their pixels (through GD; blank without).
 */
final class DeviceImages
{
    /** Image ids on the terminal start here (far from the small ids programs pick). */
    private const BASE = 1 << 30;

    /** A changed cell showed or shows an image this frame. */
    public bool $changed = false;
    /** @var array<int, array<int, true>> iTerm2: the changed image cells of the frame, by row */
    public array $cells = [];
    /** @var array<int, array{int, \WeakReference<Image>}> kitty: images on the terminal by Image id: [terminal id, the image] */
    private array $uploaded = [];
    /** @var array<string, array{int, int, int, int, int, int}> kitty: placements on the terminal by rectangle key: [x, y, w, h, terminal id, placement id] */
    private array $placed = [];
    private int $placements = 0;
    /** @var array<int, true> kitty: terminal ids of images frozen above an inline region: never deleted */
    private array $pinned = [];
    /** @var \WeakMap<Image, array<string, list<list<array{string, int, int}>>>> none: quarter-block cells per placement */
    private \WeakMap $blocks;

    public function __construct(public string $protocol = 'none')
    {
        $this->blocks = new \WeakMap();
    }

    /**
     * None: the row with its image cells drawn as quarter blocks (or blanks without GD).
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     * @return array{list<string>, list<Style>}
     */
    public function fallback(array $chars, array $styles): array
    {
        foreach ($styles as $x => $style) {
            $f = $style->image;
            if ($f === null) {
                continue;
            }
            $key = "$f->x,$f->y,$f->width,$f->height,$f->cols,$f->rows";
            $grids = $this->blocks[$f->image] ?? [];
            if (!isset($grids[$key])) {
                $grids[$key] = $this->quarters($f);
                $this->blocks[$f->image] = $grids;
            }
            $grid = $grids[$key];
            [$glyph, $fg, $bg] = $grid[$f->row][$f->col] ?? [' ', Color::DEFAULT, Color::DEFAULT];
            $chars[$x] = $glyph;
            $styles[$x] = Style::of($fg, $bg === Color::DEFAULT ? $style->bg : $bg, 0, $style->link, $style->linkId);
        }
        return [$chars, $styles];
    }

    /** @return list<list<array{string, int, int}>> */
    private function quarters(ImageFragment $f): array
    {
        $dots = Dots::ofImage($f->image, $f->x, $f->y, $f->width, $f->height, 2 * $f->cols, 2 * $f->rows);
        return $dots === null ? [] : Dots::cells('quarter', $dots);
    }

    /**
     * Kitty and iTerm2: what shows the frame's image cells, after its text. $move gives the
     * sequence that moves the cursor to a cell; $at is told where an iTerm2 image left the
     * cursor (right of its last row; a column past the last when it ends at the right edge).
     *
     * @param array<int, array{list<string>, list<Style>}> $frame the rows on the terminal
     * @param \Closure(int, int): string $move
     * @param \Closure(int, int): void $at
     */
    public function paint(array $frame, \Closure $move, \Closure $at): string
    {
        if (!$this->changed) {
            return '';
        }
        $this->changed = false;
        if ($this->protocol === 'kitty') {
            return $this->kitty($frame, $move);
        }
        $cells = $this->cells;
        $this->cells = [];
        return $this->protocol === 'iterm2' ? $this->iterm($frame, $cells, $move, $at) : '';
    }

    /** @param array<int, array{list<string>, list<Style>}> $frame */
    private function kitty(array $frame, \Closure $move): string
    {
        $want = [];
        foreach (self::rectangles($frame) as [$x, $y, $w, $h, $f]) {
            $want["$x,$y,$w,$h,$f->id"] = [$x, $y, $w, $h, $f];
        }
        $out = '';
        foreach ($this->placed as $key => [, , , , $id, $p]) {
            if (!isset($want[$key])) {
                $out .= Ansi::kitty(['a' => 'd', 'd' => 'i', 'i' => $id, 'p' => $p, 'q' => 2]);
                unset($this->placed[$key]);
            }
        }
        foreach ($want as $key => [$x, $y, $w, $h, $f]) {
            if (isset($this->placed[$key])) {
                continue;
            }
            $id = $this->upload($f->image, $out);
            if ($id === null) {
                continue;
            }
            [$px, $py, $pw, $ph] = self::source($f, $w, $h);
            $p = ++$this->placements;
            $out .= $move($x, $y) . Ansi::kitty(['a' => 'p', 'i' => $id, 'p' => $p, 'x' => $px, 'y' => $py, 'w' => $pw, 'h' => $ph, 'c' => $w, 'r' => $h, 'C' => 1, 'q' => 2]);
            $this->placed[$key] = [$x, $y, $w, $h, $id, $p];
        }
        // images no cell shows any more leave the terminal's memory
        foreach ($this->uploaded as $imageId => [$id, $ref]) {
            if ($ref->get() === null) {
                if (!isset($this->pinned[$id])) {
                    $out .= Ansi::kitty(['a' => 'd', 'd' => 'I', 'i' => $id, 'q' => 2]);
                }
                unset($this->uploaded[$imageId]);
            }
        }
        return $out;
    }

    /** The terminal's id for $image, transmitting it first (to $out) when it is not there yet; null when it cannot be sent. */
    private function upload(Image $image, string &$out): ?int
    {
        if (isset($this->uploaded[$image->id])) {
            return $this->uploaded[$image->id][0];
        }
        $raw = $image->format === 'png' || $image->format === 'rgb' || $image->format === 'rgba';
        $data = $raw ? $image->data : $image->png();
        if ($data === null) {
            return null;
        }
        $id = self::BASE + $image->id;
        $out .= Ansi::kitty(['a' => 't', 'i' => $id, 'q' => 2] + ($raw ? Ansi::kittyFormat($image) : ['f' => 100]), $data);
        $this->uploaded[$image->id] = [$id, \WeakReference::create($image)];
        return $id;
    }

    /**
     * The pixels of a $w x $h rectangle of cells whose top-left cell shows fragment $f.
     *
     * @return array{int, int, int, int}
     */
    private static function source(ImageFragment $f, int $w, int $h): array
    {
        $x0 = $f->x + (int) round($f->col * $f->width / $f->cols);
        $x1 = $f->x + (int) round(($f->col + $w) * $f->width / $f->cols);
        $y0 = $f->y + (int) round($f->row * $f->height / $f->rows);
        $y1 = $f->y + (int) round(($f->row + $h) * $f->height / $f->rows);
        return [$x0, $y0, max(1, $x1 - $x0), max(1, $y1 - $y0)];
    }

    /**
     * @param array<int, array{list<string>, list<Style>}> $frame
     * @param array<int, array<int, true>> $cells
     */
    private function iterm(array $frame, array $cells, \Closure $move, \Closure $at): string
    {
        $out = '';
        foreach (self::rectangles($frame, $cells) as [$x, $y, $w, $h, $f]) {
            $png = null;
            $gd = $f->image->gd();
            if ($gd !== null) {
                [$px, $py, $pw, $ph] = self::source($f, $w, $h);
                $crop = imagecrop($gd, ['x' => $px, 'y' => $py, 'width' => $pw, 'height' => $ph]);
                if ($crop !== false) {
                    imagesavealpha($crop, true);
                    ob_start();
                    imagepng($crop);
                    $png = (string) ob_get_clean();
                }
            } elseif ($f->x === 0 && $f->y === 0 && $f->width === $f->image->width && $f->height === $f->image->height
                && self::whole($frame, $x - $f->col, $y - $f->row, $f)) {
                // (without GD only a whole image can be sent: when the frame shows all of it)
                [$x, $y, $w, $h] = [$x - $f->col, $y - $f->row, $f->cols, $f->rows];
                $png = $f->image->png();
            }
            if ($png === null) {
                continue;
            }
            $out .= $move($x, $y) . "\e]1337;File=inline=1;size=" . \strlen($png) . ";width=$w;height=$h;preserveAspectRatio=0:" . base64_encode($png) . "\x07";
            $at($x + $w, $y + $h - 1);
        }
        return $out;
    }

    /** True if the frame shows every cell of $f's placement, its top-left cell at ($x, $y). */
    private static function whole(array $frame, int $x, int $y, ImageFragment $f): bool
    {
        for ($r = 0; $r < $f->rows; $r++) {
            $styles = $frame[$y + $r][1] ?? null;
            for ($c = 0; $c < $f->cols; $c++) {
                $g = $styles[$x + $c]->image ?? null;
                if ($g === null || $g->col !== $c || $g->row !== $r || !$g->samePlacement($f)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The image cells of $frame (those in $only, when given) as rectangles of contiguous
     * fragments of one placement: [x, y, w, h, the top-left fragment] each, rows first.
     *
     * @param array<int, array{list<string>, list<Style>}> $frame
     * @param array<int, array<int, true>>|null $only
     * @return list<array{int, int, int, int, ImageFragment}>
     */
    public static function rectangles(array $frame, ?array $only = null): array
    {
        $done = [];
        $out = [];
        $ys = array_keys($frame);
        sort($ys);
        foreach ($ys as $y) {
            $styles = $frame[$y][1];
            foreach ($styles as $x => $style) {
                $f = $style->image;
                if ($f === null || isset($done[$y][$x]) || ($only !== null && !isset($only[$y][$x]))) {
                    continue;
                }
                $fits = function (int $cx, int $cy, int $col, int $row) use ($frame, $f, $done, $only): bool {
                    $g = ($frame[$cy][1][$cx] ?? null)?->image;
                    return $g !== null && $g->col === $col && $g->row === $row && !isset($done[$cy][$cx])
                        && ($only === null || isset($only[$cy][$cx])) && $g->samePlacement($f);
                };
                $w = 1;
                while ($fits($x + $w, $y, $f->col + $w, $f->row)) {
                    $w++;
                }
                $h = 1;
                while (true) {
                    for ($i = 0; $i < $w && $fits($x + $i, $y + $h, $f->col + $i, $f->row + $h); $i++) {
                    }
                    if ($i < $w) {
                        break;
                    }
                    $h++;
                }
                for ($j = 0; $j < $h; $j++) {
                    for ($i = 0; $i < $w; $i++) {
                        $done[$y + $j][$x + $i] = true;
                    }
                }
                $out[] = [$x, $y, $w, $h, $f];
            }
        }
        return $out;
    }

    /** Kitty: delete every placement this device made (a redraw, a detach); the images stay unless $images. */
    public function clear(bool $images = false): string
    {
        $out = '';
        foreach ($this->placed as [, , , , $id, $p]) {
            $out .= Ansi::kitty(['a' => 'd', 'd' => 'i', 'i' => $id, 'p' => $p, 'q' => 2]);
        }
        $this->placed = [];
        if ($images) {
            foreach ($this->uploaded as [$id]) {
                if (!isset($this->pinned[$id])) {
                    $out .= Ansi::kitty(['a' => 'd', 'd' => 'I', 'i' => $id, 'q' => 2]);
                }
            }
            $this->uploaded = [];
        }
        $this->changed = true;
        return $out;
    }

    /**
     * Inline: the rows written over the top of the region as it scrolls ($rows, numbered from
     * the region's top as written) leave it and are frozen: their images stay on the terminal
     * for good. Kitty: the placements already there for them are kept (and their images never
     * deleted), the missing ones placed, the others on those rows deleted, and the region's
     * placements below are renumbered; iTerm2: their changed image cells are painted.
     *
     * @param array<int, array{list<string>, list<Style>}> $rows
     * @param \Closure(int, int): string $move
     * @param \Closure(int, int): void $at
     */
    public function freeze(array $rows, \Closure $move, \Closure $at): string
    {
        $n = \count($rows);
        $out = '';
        if ($this->protocol === 'iterm2') {
            $cells = array_filter($this->cells, fn(int $y) => $y < $n, ARRAY_FILTER_USE_KEY);
            $out = $cells === [] ? '' : $this->iterm($rows, $cells, $move, $at);
            $this->cells = [];
        } elseif ($this->protocol === 'kitty') {
            $frozen = [];
            foreach (self::rectangles($rows) as [$x, $y, $w, $h, $f]) {
                $frozen["$x,$y,$w,$h,$f->id"] = [$x, $y, $w, $h, $f];
            }
            foreach ($this->placed as $key => [, $y, , , $id, $p]) {
                if ($y >= $n) {
                    continue;
                }
                if (isset($frozen[$key])) {
                    $this->pinned[$id] = true;
                    unset($frozen[$key]);
                } else {
                    $out .= Ansi::kitty(['a' => 'd', 'd' => 'i', 'i' => $id, 'p' => $p, 'q' => 2]);
                }
                unset($this->placed[$key]);
            }
            foreach ($frozen as [$x, $y, $w, $h, $f]) {
                $id = $this->upload($f->image, $out);
                if ($id !== null) {
                    [$px, $py, $pw, $ph] = self::source($f, $w, $h);
                    $out .= $move($x, $y) . Ansi::kitty(['a' => 'p', 'i' => $id, 'p' => ++$this->placements, 'x' => $px, 'y' => $py, 'w' => $pw, 'h' => $ph, 'c' => $w, 'r' => $h, 'C' => 1, 'q' => 2]);
                    $this->pinned[$id] = true;
                }
            }
            $placed = [];
            foreach ($this->placed as $key => [$x, $y, $w, $h, $id, $p]) {
                $fid = explode(',', $key)[4];
                $placed[$x . ',' . ($y - $n) . ",$w,$h,$fid"] = [$x, $y - $n, $w, $h, $id, $p];
            }
            $this->placed = $placed;
        }
        return $out;
    }
}
