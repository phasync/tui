<?php

namespace phasync\Tui;

/**
 * Colors as plain integers, the way a cell stores them.
 *
 * A color is one of:
 *
 * - `Color::DEFAULT` (-1): the terminal's default foreground or background
 * - `0` to `255`: an index into the 256-color palette; 0-7 are the classic ANSI colors
 *   (`Color::RED` and friends), 8-15 their bright variants, 16-231 a 6x6x6 color cube and
 *   232-255 a gray ramp
 * - a truecolor value made with `Color::rgb()`
 *
 * Use these helpers to make and inspect colors for `Style` and `Ansi`; compare colors with `===`.
 */
final class Color
{
    public const DEFAULT = -1;
    public const BLACK = 0;
    public const RED = 1;
    public const GREEN = 2;
    public const YELLOW = 3;
    public const BLUE = 4;
    public const MAGENTA = 5;
    public const CYAN = 6;
    public const WHITE = 7;
    public const BRIGHT_BLACK = 8;
    public const BRIGHT_RED = 9;
    public const BRIGHT_GREEN = 10;
    public const BRIGHT_YELLOW = 11;
    public const BRIGHT_BLUE = 12;
    public const BRIGHT_MAGENTA = 13;
    public const BRIGHT_CYAN = 14;
    public const BRIGHT_WHITE = 15;

    private const RGB_FLAG = 0x1000000;

    /** A truecolor value from red, green and blue components (0-255 each). */
    public static function rgb(int $r, int $g, int $b): int
    {
        return self::RGB_FLAG | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }

    /** A truecolor value from a hex string such as `'#ff8800'` or `'ff8800'`. */
    public static function hex(string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new \InvalidArgumentException("Not a hex color: '$hex'");
        }
        return self::RGB_FLAG | hexdec($hex);
    }

    /** True if `$color` is a truecolor value (as opposed to the default or a palette index). */
    public static function isRgb(int $color): bool
    {
        return $color >= self::RGB_FLAG;
    }

    /**
     * The red, green and blue components of a color, or null for `Color::DEFAULT`. Palette
     * indexes are converted with the standard xterm palette.
     *
     * @return array{int,int,int}|null
     */
    public static function toRgb(int $color): ?array
    {
        if ($color < 0) {
            return null;
        }
        if ($color >= self::RGB_FLAG) {
            return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
        }
        if ($color < 16) {
            static $base = [
                [0, 0, 0], [205, 0, 0], [0, 205, 0], [205, 205, 0], [0, 0, 238], [205, 0, 205], [0, 205, 205], [229, 229, 229],
                [127, 127, 127], [255, 0, 0], [0, 255, 0], [255, 255, 0], [92, 92, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
            ];
            return $base[$color];
        }
        if ($color < 232) {
            $c = $color - 16;
            $level = static fn(int $v): int => $v === 0 ? 0 : 55 + $v * 40;
            return [$level(intdiv($c, 36)), $level(intdiv($c, 6) % 6), $level($c % 6)];
        }
        $v = 8 + ($color - 232) * 10;
        return [$v, $v, $v];
    }

    /** A readable name for a color: `default`, a palette index such as `1`, or `#rrggbb`. */
    public static function name(int $color): string
    {
        if ($color < 0) {
            return 'default';
        }
        if ($color >= self::RGB_FLAG) {
            return sprintf('#%06x', $color & 0xFFFFFF);
        }
        return (string) $color;
    }
}
