<?php

namespace phasync\Tui;

/**
 * The look of a cell: foreground color, background color and attributes.
 *
 * Styles are immutable and interned: `Style::of()` returns the same object for the same values,
 * so two cells look the same exactly when their styles are `===`. Read a cell's style with
 * `Surface::cell()`; produce styled output with `Ansi::style()` or the `with*()` methods here.
 *
 *     $warning = Style::of(fg: Color::YELLOW, attrs: Style::BOLD);
 *     $surface->write(Ansi::style($warning) . 'careful' . Ansi::reset());
 */
final class Style
{
    public const BOLD = 1;
    public const DIM = 2;
    public const ITALIC = 4;
    public const UNDERLINE = 8;
    public const BLINK = 16;
    public const INVERSE = 32;
    public const HIDDEN = 64;
    public const STRIKE = 128;

    /** Attribute names as used by `dump()` and `Style::__toString()`, by bit. */
    public const NAMES = [
        self::BOLD => 'bold', self::DIM => 'dim', self::ITALIC => 'italic', self::UNDERLINE => 'underline',
        self::BLINK => 'blink', self::INVERSE => 'inverse', self::HIDDEN => 'hidden', self::STRIKE => 'strike',
    ];

    /** @var array<string, Style> */
    private static array $interned = [];
    private static ?Style $plain = null;

    private function __construct(
        /** Foreground color (see `Color`). */
        public readonly int $fg,
        /** Background color (see `Color`). */
        public readonly int $bg,
        /** Attribute bits: `Style::BOLD | Style::UNDERLINE` and so on. */
        public readonly int $attrs,
    ) {
    }

    /** The style with these colors and attributes (the same object every time). */
    public static function of(int $fg = Color::DEFAULT, int $bg = Color::DEFAULT, int $attrs = 0): Style
    {
        return self::$interned["$fg,$bg,$attrs"] ??= new Style($fg, $bg, $attrs);
    }

    /** The default style: default colors, no attributes. */
    public static function plain(): Style
    {
        return self::$plain ??= self::of();
    }

    /** True if all of the given attribute bits are set. */
    public function has(int $attrs): bool
    {
        return ($this->attrs & $attrs) === $attrs;
    }

    /** This style with another foreground color. */
    public function withFg(int $fg): Style
    {
        return self::of($fg, $this->bg, $this->attrs);
    }

    /** This style with another background color. */
    public function withBg(int $bg): Style
    {
        return self::of($this->fg, $bg, $this->attrs);
    }

    /** This style with these attribute bits added. */
    public function with(int $attrs): Style
    {
        return self::of($this->fg, $this->bg, $this->attrs | $attrs);
    }

    /** This style with these attribute bits removed. */
    public function without(int $attrs): Style
    {
        return self::of($this->fg, $this->bg, $this->attrs & ~$attrs);
    }

    /**
     * A readable description such as `bold fg=1 bg=#203040`, empty for the plain style. This is
     * the notation `Surface::dump()` uses.
     */
    public function __toString(): string
    {
        $parts = [];
        foreach (self::NAMES as $bit => $name) {
            if ($this->attrs & $bit) {
                $parts[] = $name;
            }
        }
        if ($this->fg !== Color::DEFAULT) {
            $parts[] = 'fg=' . Color::name($this->fg);
        }
        if ($this->bg !== Color::DEFAULT) {
            $parts[] = 'bg=' . Color::name($this->bg);
        }
        return implode(' ', $parts);
    }
}
