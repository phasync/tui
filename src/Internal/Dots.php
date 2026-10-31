<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Color;

/**
 * @internal Dot bitmaps to cells of block and Braille glyphs: the glyph markers of `Canvas`, and
 * what a terminal device without an image protocol shows for image cells (quarter blocks).
 *
 * A bitmap is `['width' => w, 'height' => h, 'on' => one byte per dot (0 off), 'rgb' => three
 * bytes per dot]`, rows top to bottom: what phasync/canvas's `toDots()` returns. Each marker
 * takes a fixed number of dots per cell (`RESOLUTION`) and makes one cell of each: a glyph, its
 * foreground and its background (`Color::DEFAULT` where nothing is drawn).
 */
final class Dots
{
    /** Dots per cell [columns, rows] by marker. */
    public const RESOLUTION = ['braille' => [2, 4], 'quarter' => [2, 2], 'half' => [1, 2], 'block' => [1, 1]];

    /** Quarter blocks by the dots they show: 1 top left, 2 top right, 4 bottom left, 8 bottom right. */
    private const QUARTERS = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];
    /** Braille dot bits by dot [column][row] (U+2800 + the bits). */
    private const BRAILLE = [[0x01, 0x02, 0x04, 0x40], [0x08, 0x10, 0x20, 0x80]];

    /**
     * The cells of $dots in $marker, row by row: [glyph, fg, bg] each.
     *
     * @param array{width: int, height: int, on: string, rgb: string} $dots
     * @return list<list<array{string, int, int}>>
     */
    public static function cells(string $marker, array $dots): array
    {
        [$dx, $dy] = self::RESOLUTION[$marker];
        $w = $dots['width'];
        $cols = intdiv($w, $dx);
        $rows = intdiv($dots['height'], $dy);
        $on = $dots['on'];
        $rgb = $dots['rgb'];
        $out = [];
        for ($row = 0; $row < $rows; $row++) {
            $line = [];
            for ($col = 0; $col < $cols; $col++) {
                // the cell's dots: [on, r, g, b] in reading order (row by row)
                $cell = [];
                for ($j = 0; $j < $dy; $j++) {
                    for ($i = 0; $i < $dx; $i++) {
                        $k = ($row * $dy + $j) * $w + $col * $dx + $i;
                        $cell[] = $on[$k] === "\0" ? null : [\ord($rgb[3 * $k]), \ord($rgb[3 * $k + 1]), \ord($rgb[3 * $k + 2])];
                    }
                }
                $line[] = match ($marker) {
                    'braille' => self::braille($cell),
                    'quarter' => self::quarter($cell),
                    'half' => self::half($cell),
                    default => $cell[0] === null ? [' ', Color::DEFAULT, Color::DEFAULT] : ['█', Color::rgb(...$cell[0]), Color::DEFAULT],
                };
            }
            $out[] = $line;
        }
        return $out;
    }

    /** @param list<array{int,int,int}|null> $cell 8 dots, two per row */
    private static function braille(array $cell): array
    {
        $bits = 0;
        $counts = [];
        foreach ($cell as $k => $dot) {
            if ($dot !== null) {
                $bits |= self::BRAILLE[$k % 2][intdiv($k, 2)];
                $key = ($dot[0] << 16) | ($dot[1] << 8) | $dot[2];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        if ($bits === 0) {
            return [' ', Color::DEFAULT, Color::DEFAULT];
        }
        // (the dots of a cell share one foreground: the colour most of them have)
        arsort($counts);
        $c = array_key_first($counts);
        return [mb_chr(0x2800 + $bits, 'UTF-8'), Color::rgb($c >> 16, ($c >> 8) & 255, $c & 255), Color::DEFAULT];
    }

    /** @param list<array{int,int,int}|null> $cell top left, top right, bottom left, bottom right */
    private static function quarter(array $cell): array
    {
        $mask = 0;
        foreach ($cell as $k => $dot) {
            if ($dot !== null) {
                $mask |= 1 << $k;
            }
        }
        if ($mask === 0) {
            return [' ', Color::DEFAULT, Color::DEFAULT];
        }
        if ($mask !== 15) {
            // dots off are the background: the glyph shows the ones on, in their mean colour
            return [self::QUARTERS[$mask], self::mean($cell, $mask), Color::DEFAULT];
        }
        // all four on: the split into two colours that loses least (one colour: a full block)
        $best = 15;
        $error = self::error($cell, 15);
        for ($m = 1; $m < 8; $m++) {
            $e = self::error($cell, $m) + self::error($cell, 15 & ~$m);
            if ($e < $error) {
                [$best, $error] = [$m, $e];
            }
        }
        return [self::QUARTERS[$best], self::mean($cell, $best), $best === 15 ? Color::DEFAULT : self::mean($cell, 15 & ~$best)];
    }

    /** @param list<array{int,int,int}|null> $cell top, bottom */
    private static function half(array $cell): array
    {
        [$top, $bottom] = $cell;
        if ($top === null && $bottom === null) {
            return [' ', Color::DEFAULT, Color::DEFAULT];
        }
        if ($top === null) {
            return ['▄', Color::rgb(...$bottom), Color::DEFAULT];
        }
        return ['▀', Color::rgb(...$top), $bottom === null ? Color::DEFAULT : Color::rgb(...$bottom)];
    }

    /** The mean colour of the dots in $mask. */
    private static function mean(array $cell, int $mask): int
    {
        $sum = [0, 0, 0];
        $n = 0;
        foreach ($cell as $k => $dot) {
            if ($mask & (1 << $k)) {
                $sum[0] += $dot[0];
                $sum[1] += $dot[1];
                $sum[2] += $dot[2];
                $n++;
            }
        }
        return Color::rgb((int) round($sum[0] / $n), (int) round($sum[1] / $n), (int) round($sum[2] / $n));
    }

    /** How far the dots in $mask are from their mean (sum of squares). */
    private static function error(array $cell, int $mask): int
    {
        [$r, $g, $b] = Color::toRgb(self::mean($cell, $mask));
        $e = 0;
        foreach ($cell as $k => $dot) {
            if ($mask & (1 << $k)) {
                $e += ($dot[0] - $r) ** 2 + ($dot[1] - $g) ** 2 + ($dot[2] - $b) ** 2;
            }
        }
        return $e;
    }

    /**
     * The dot bitmap of an image's source rectangle scaled to $w x $h dots (through GD): a dot
     * is on where the pixels under it are mostly opaque. Null without GD or for an image GD
     * cannot read.
     *
     * @return array{width: int, height: int, on: string, rgb: string}|null
     */
    public static function ofImage(\phasync\Tui\Image $image, int $x, int $y, int $width, int $height, int $w, int $h): ?array
    {
        $gd = $image->gd();
        if ($gd === null) {
            return null;
        }
        $scaled = imagecreatetruecolor($w, $h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $gd, 0, 0, $x, $y, $w, $h, $width, $height);
        $on = '';
        $rgb = '';
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $c = imagecolorat($scaled, $i, $j);
                $on .= (($c >> 24) & 127) < 64 ? "\1" : "\0";
                $rgb .= \chr(($c >> 16) & 255) . \chr(($c >> 8) & 255) . \chr($c & 255);
            }
        }
        return ['width' => $w, 'height' => $h, 'on' => $on, 'rgb' => $rgb];
    }
}
