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

    /** Colour depths of a terminal (see `TerminalDevice::setColors()`): 16 colours, 256, or truecolour. */
    public const DEPTH_16 = 16;
    public const DEPTH_256 = 256;
    public const DEPTH_TRUECOLOR = 16777216;

    /** The 16 colour names, as the stylesheet and markup know them. */
    public const NAMES = [
        'black' => 0, 'red' => 1, 'green' => 2, 'yellow' => 3, 'blue' => 4, 'magenta' => 5, 'cyan' => 6, 'white' => 7,
        'bright-black' => 8, 'gray' => 8, 'grey' => 8, 'bright-red' => 9, 'bright-green' => 10, 'bright-yellow' => 11,
        'bright-blue' => 12, 'bright-magenta' => 13, 'bright-cyan' => 14, 'bright-white' => 15,
    ];

    private const RGB_FLAG = 0x1000000;

    /** @var array<string, int>|null the Tailwind palette by `family-shade`, loaded on first use */
    private static ?array $tailwind = null;
    /** @var array<int, array<int, int>> nearest() answers by depth and colour */
    private static array $nearest = [];

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

    /**
     * A colour from what a person writes: a name of the 16 (`red`, `bright-blue`, `gray`), a
     * Tailwind colour (`sky-400`, `slate-800`: family and shade, as truecolour), a hex value
     * (`#ff5500`, also `[#ff5500]` as in a utility class), a palette index (`208`, 0-255),
     * `default`, or an int as `Color` keeps it. The stylesheet, utility classes and markup all
     * read colours with this.
     *
     * @throws \InvalidArgumentException for anything else
     */
    public static function parse(string|int $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        $name = strtolower(trim($value));
        if ($name === 'default' || $name === '') {
            return self::DEFAULT;
        }
        if (isset(self::NAMES[$name])) {
            return self::NAMES[$name];
        }
        if (str_starts_with($name, '[') && str_ends_with($name, ']')) {
            $name = substr($name, 1, -1);
        }
        if (str_starts_with($name, '#')) {
            return self::hex($name);
        }
        if (ctype_digit($name) && (int) $name <= 255) {
            return (int) $name;
        }
        if (self::$tailwind === null) {
            $data = require __DIR__ . '/Style/tailwind.php';
            self::$tailwind = [];
            foreach ($data['families'] as $family => $hexes) {
                foreach ($hexes as $i => $hex) {
                    self::$tailwind[$family . '-' . $data['shades'][$i]] = self::hex($hex);
                }
            }
        }
        return self::$tailwind[$name] ?? throw new \InvalidArgumentException("Unknown colour '$value': a name (red, bright-blue), a Tailwind colour (sky-400), '#rrggbb' or 0-255");
    }

    /**
     * The colour a terminal of $depth colours shows for $color: itself when the terminal has
     * it, else the nearest one it has (by a weighted distance of red, green and blue): a
     * truecolour becomes one of the 256-colour cube and grays on a 256-colour terminal, and
     * anything above 15 one of the 16 on a 16-colour one. The terminal device does this in
     * one place for everything it draws.
     */
    public static function nearest(int $color, int $depth): int
    {
        if ($color < 0 || $depth >= self::DEPTH_TRUECOLOR || ($color < 256 && ($depth >= 256 || $color < 16))) {
            return $color;
        }
        if (isset(self::$nearest[$depth][$color])) {
            return self::$nearest[$depth][$color];
        }
        [$r, $g, $b] = self::toRgb($color);
        $best = 0;
        $bestDistance = \PHP_INT_MAX;
        // (on 256 colours, the cube and the grays: the first 16 are the user's theme, not fixed)
        for ($i = $depth >= 256 ? 16 : 0, $n = $depth >= 256 ? 256 : 16; $i < $n; $i++) {
            [$r2, $g2, $b2] = self::toRgb($i);
            $mean = ($r + $r2) >> 1;
            $d = (((512 + $mean) * ($r - $r2) ** 2) >> 8) + 4 * ($g - $g2) ** 2 + (((767 - $mean) * ($b - $b2) ** 2) >> 8);
            if ($d < $bestDistance) {
                $best = $i;
                $bestDistance = $d;
            }
        }
        if (\count(self::$nearest[$depth] ?? []) > 4096) {
            self::$nearest[$depth] = [];
        }
        return self::$nearest[$depth][$color] = $best;
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
