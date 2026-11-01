<?php

namespace phasync\Tui\Internal;

/**
 * @internal DEC Sixel graphics: the pictures of `DCS P1;P2;P3 q <data> ST`, decoded to RGBA for
 * the emulator, and encoded from a GD image for a terminal device whose terminal has Sixel.
 *
 * The data is a stream of commands over bands of six pixel rows: a sixel character (`?` to `~`,
 * its value minus 63 being the six pixels of a column, the lowest bit on top) paints in the
 * current colour and moves right; `!n` repeats the next sixel n times; `$` returns to the left
 * of the band; `-` moves to the next band; `#c` selects colour register c, `#c;u;a;b;d` defines
 * it (u = 1 HLS, 2 RGB, both in percent and degrees as DEC defined them) and selects it (a
 * pixel keeps the colour its register had when it was painted);
 * `"pan;pad;w;h` gives the raster attributes (the image's size; the aspect ratio is taken as
 * 1:1, as current terminals do). Pixels nothing painted are transparent when P2 is 1, else the
 * background colour.
 */
final class Sixel
{
    /** Colour registers (as xterm and foot have); register numbers wrap around them. */
    public const REGISTERS = 1024;
    /** The largest image decoded, in pixels each way; what is painted beyond is cut. */
    private const MAX = 10000;
    /** The VT340's colour registers 0 to 15 at power-up (the others start black). */
    private const DEFAULT_PALETTE = [
        [0, 0, 0], [51, 51, 204], [204, 33, 33], [51, 204, 51], [204, 51, 204], [51, 204, 204], [204, 204, 51], [135, 135, 135],
        [66, 66, 66], [84, 84, 153], [153, 66, 66], [84, 153, 84], [153, 84, 153], [84, 153, 153], [153, 153, 84], [204, 204, 204],
    ];

    /**
     * The picture of a sixel string: [width, height, RGBA bytes], or null when it paints
     * nothing and gives no size. $params are the DCS parameters before `q`, $data what follows
     * it; $background is the colour of unpainted pixels when P2 is not 1, as [r, g, b].
     *
     * @param array{int, int, int} $background
     * @return array{int, int, string}|null
     */
    public static function decode(string $params, string $data, array $background): ?array
    {
        $p = explode(';', $params);
        $blank = (int) ($p[1] ?? 0) === 1 ? "\0\0\0\0" : \chr($background[0]) . \chr($background[1]) . \chr($background[2]) . "\xff";
        $palette = [];
        foreach (self::DEFAULT_PALETTE as $k => [$r, $g, $b]) {
            $palette[$k] = \chr($r) . \chr($g) . \chr($b) . "\xff";
        }
        $pen = $palette[0];        // (a pixel takes its register's colour when it is painted)
        $x = $y = 0;
        $width = $height = 0;      // what the raster attributes say
        $right = $bottom = 0;      // what was painted reaches
        /** @var array<int, array<int, string>> $rows the painted pixels of the band, RGBA by x, by y */
        $rows = [];
        /** @var array<int, string> $lines the rows of the bands above, RGBA, as wide as they were painted */
        $lines = [];
        $n = \strlen($data);
        $i = 0;
        while ($i < $n) {
            $c = $data[$i];
            $o = \ord($c);
            if ($o >= 63 && $o <= 126) {
                $repeat = 1;
            } elseif ($c === '!') {
                $j = $i + 1;
                $repeat = max(1, (int) self::digits($data, $j));
                if ($j >= $n || \ord($data[$j]) < 63 || \ord($data[$j]) > 126) {
                    $i = $j;        // (a repeat of something else: dropped)
                    continue;
                }
                $i = $j;
                $o = \ord($data[$i]);
            } else {
                $i++;
                if ($c === '$') {
                    $x = 0;
                } elseif ($c === '-') {
                    // the band is done: its rows become strings (a band is never painted again)
                    self::flush($rows, $lines, $blank);
                    $x = 0;
                    $y += 6;
                } elseif ($c === '#') {
                    $args = self::numbers($data, $i);
                    $register = $args[0] % self::REGISTERS;
                    if (\count($args) >= 5) {
                        $rgb = self::colour($args[1], $args[2], $args[3], $args[4]);
                        if ($rgb !== null) {
                            $palette[$register] = \chr($rgb[0]) . \chr($rgb[1]) . \chr($rgb[2]) . "\xff";
                        }
                    }
                    $pen = $palette[$register] ?? "\0\0\0\xff";
                } elseif ($c === '"') {
                    $args = self::numbers($data, $i);
                    if (($args[2] ?? 0) > 0 && ($args[3] ?? 0) > 0) {
                        $width = min(self::MAX, $args[2]);
                        $height = min(self::MAX, $args[3]);
                    }
                }
                // (anything else, controls included, is ignored)
                continue;
            }
            $i++;
            $bits = $o - 63;
            if ($bits !== 0 && $y < self::MAX) {
                $to = min(self::MAX, $x + $repeat);
                for ($b = 0; $b < 6; $b++) {
                    if ($bits & (1 << $b)) {
                        $row = &$rows[$y + $b];
                        for ($k = $x; $k < $to; $k++) {
                            $row[$k] = $pen;
                        }
                        unset($row);
                        $bottom = max($bottom, $y + $b + 1);
                    }
                }
                $right = max($right, $to);
            }
            $x += $repeat;
        }
        self::flush($rows, $lines, $blank);
        $width = max($width, $right);
        $height = min(self::MAX, max($height, $bottom));
        if ($width === 0 || $height === 0) {
            return null;
        }
        $out = '';
        for ($j = 0; $j < $height; $j++) {
            $line = $lines[$j] ?? '';
            $out .= $line . str_repeat($blank, $width - \intdiv(\strlen($line), 4));
        }
        return [$width, $height, $out];
    }

    /**
     * The painted rows of a band as RGBA strings into $lines, each as wide as it was painted
     * (the blanks to its right are added at the end, when the image's width is known).
     *
     * @param array<int, array<int, string>> $rows
     * @param array<int, string> $lines
     */
    private static function flush(array &$rows, array &$lines, string $blank): void
    {
        foreach ($rows as $j => $row) {
            $line = array_fill(0, max(array_keys($row)) + 1, $blank);
            foreach ($row as $k => $rgba) {
                $line[$k] = $rgba;
            }
            $lines[$j] = implode('', $line);
        }
        $rows = [];
    }

    /** The decimal number at $i (moving $i past it); '' when there is none. */
    private static function digits(string $data, int &$i): string
    {
        $len = strspn($data, '0123456789', $i);
        $s = substr($data, $i, $len);
        $i += $len;
        return $s;
    }

    /**
     * The numbers of a command's parameters at $i, separated by ';' (an empty one is 0).
     *
     * @return list<int>
     */
    private static function numbers(string $data, int &$i): array
    {
        $out = [(int) self::digits($data, $i)];
        while (($data[$i] ?? '') === ';') {
            $i++;
            $out[] = (int) self::digits($data, $i);
        }
        return $out;
    }

    /**
     * A colour definition as [r, g, b]: $u 2 is RGB in percent, 1 is DEC's HLS (hue in
     * degrees with blue at 0, red at 120 and green at 240; lightness and saturation in percent).
     *
     * @return array{int, int, int}|null
     */
    private static function colour(int $u, int $a, int $b, int $c): ?array
    {
        if ($u === 2) {
            return [self::percent($a), self::percent($b), self::percent($c)];
        }
        if ($u !== 1) {
            return null;
        }
        $h = fmod($a + 240, 360) / 360;     // (DEC's hue to the usual one, red at 0)
        $l = min(100, $b) / 100;
        $s = min(100, $c) / 100;
        if ($s == 0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = function (float $t) use ($p, $q): int {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            $v = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
            return (int) round($v * 255);
        };
        return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
    }

    private static function percent(int $v): int
    {
        return (int) round(min(100, $v) * 255 / 100);
    }

    /**
     * The sixel string (DCS to ST) of a true-colour GD image: its colours reduced to at most
     * $colours (GD's quantiser, dithered), pixels mostly transparent left unpainted (P2 = 1),
     * the raster attributes giving its exact size.
     */
    public static function encode(\GdImage $image, int $colours = 256): string
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $palette = imagecreatetruecolor($w, $h);
        imagealphablending($palette, false);
        imagesavealpha($palette, true);
        imagecopy($palette, $image, 0, 0, 0, 0, $w, $h);
        imagetruecolortopalette($palette, true, max(2, min(256, $colours)));
        $out = "\eP0;1;0q\"1;1;$w;$h";
        $total = imagecolorstotal($palette);
        $opaque = [];
        for ($k = 0; $k < $total; $k++) {
            $rgba = imagecolorsforindex($palette, $k);
            $opaque[$k] = $rgba['alpha'] < 64;
        }
        $used = [];
        $bands = '';
        for ($y0 = 0; $y0 < $h; $y0 += 6) {
            /** @var array<int, array<int, int>> $bits sixel bits by x, by colour */
            $bits = [];
            $last = min($h, $y0 + 6);
            for ($y = $y0; $y < $last; $y++) {
                $bit = 1 << ($y - $y0);
                for ($x = 0; $x < $w; $x++) {
                    $k = imagecolorat($palette, $x, $y);
                    if ($opaque[$k] ?? false) {
                        $bits[$k][$x] = ($bits[$k][$x] ?? 0) | $bit;
                    }
                }
            }
            $bands .= $y0 > 0 ? '-' : '';      // (no '-' after the last band: it would move below the image)
            $first = true;
            foreach ($bits as $k => $columns) {
                $used[$k] = true;
                ksort($columns);
                $bands .= ($first ? '' : '$') . "#$k";
                $first = false;
                $at = 0;
                $char = '';
                $run = 0;
                foreach ($columns as $x => $v) {
                    if ($x > $at) {
                        $bands .= self::run($char, $run);
                        [$char, $run] = ['?', $x - $at];
                    }
                    $c = \chr(63 + $v);
                    if ($c === $char) {
                        $run++;
                    } else {
                        $bands .= self::run($char, $run);
                        [$char, $run] = [$c, 1];
                    }
                    $at = $x + 1;
                }
                $bands .= self::run($char, $run);
            }
        }
        foreach ($used as $k => $_) {
            $rgba = imagecolorsforindex($palette, $k);
            $out .= "#$k;2;" . (int) round($rgba['red'] * 100 / 255) . ';' . (int) round($rgba['green'] * 100 / 255) . ';' . (int) round($rgba['blue'] * 100 / 255);
        }
        return $out . $bands . "\e\\";
    }

    /** $n sixels $char, with a repeat introducer when that is shorter. */
    private static function run(string $char, int $n): string
    {
        return $n > 3 ? "!$n$char" : str_repeat($char, $n);
    }
}
