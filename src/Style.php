<?php

namespace phasync\Tui;

/**
 * The look of a cell: foreground color, background color and attributes, and the hyperlink the
 * cell belongs to (OSC 8), if any.
 *
 * Styles are immutable and interned: `Style::of()` returns the same object for the same values,
 * so two cells look the same exactly when their styles are `===`. Read a cell's style with
 * `Surface::cell()`; produce styled output with `Ansi::style()` or the `with*()` methods here.
 *
 *     $warning = Style::of(fg: Color::YELLOW, attrs: Style::BOLD);
 *     $surface->write(Ansi::style($warning) . 'careful' . Ansi::reset());
 *
 * The hyperlink is set apart from the colours, as terminals do: `Ansi::style()` and SGR (`ESC [
 * 0 m` included) leave it as it is, `Ansi::link()` sets and ends it.
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
    /** What blank() returns, once asked. */
    private ?Style $blank = null;

    private function __construct(
        /** Foreground color (see `Color`). */
        public readonly int $fg,
        /** Background color (see `Color`). */
        public readonly int $bg,
        /** Attribute bits: `Style::BOLD | Style::UNDERLINE` and so on. */
        public readonly int $attrs,
        /** The URI of the hyperlink the cell belongs to, '' for none. */
        public readonly string $link = '',
        /** The hyperlink's id ('' for none): cells with the same id and URI are one link, also across rows. */
        public readonly string $linkId = '',
    ) {
    }

    /** The style with these colors, attributes and hyperlink (the same object every time). */
    public static function of(int $fg = Color::DEFAULT, int $bg = Color::DEFAULT, int $attrs = 0, string $link = '', string $linkId = ''): Style
    {
        $key = $link === '' ? "$fg,$bg,$attrs" : "$fg,$bg,$attrs,$linkId;$link";
        return self::$interned[$key] ??= new Style($fg, $bg, $attrs, $link, $link === '' ? '' : $linkId);
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
        return self::of($fg, $this->bg, $this->attrs, $this->link, $this->linkId);
    }

    /** This style with another background color. */
    public function withBg(int $bg): Style
    {
        return self::of($this->fg, $bg, $this->attrs, $this->link, $this->linkId);
    }

    /** This style with these attribute bits added. */
    public function with(int $attrs): Style
    {
        return self::of($this->fg, $this->bg, $this->attrs | $attrs, $this->link, $this->linkId);
    }

    /** This style with these attribute bits removed. */
    public function without(int $attrs): Style
    {
        return self::of($this->fg, $this->bg, $this->attrs & ~$attrs, $this->link, $this->linkId);
    }

    /** This style in the hyperlink to $uri (with an id, to join parts of one link), or in none with ''. */
    public function withLink(string $uri, string $id = ''): Style
    {
        return self::of($this->fg, $this->bg, $this->attrs, $uri, $id);
    }

    /**
     * The style a blank cell (a space) in this style looks the same as: the background and the
     * hyperlink alone, since a space shows no foreground, bold, dim, italic or blink; the style
     * itself when it underlines, strikes through or inverts (those draw in the foreground colour).
     * Two blanks look the same exactly when their `blank()` styles are `===`, and a blank on the
     * default background looks like an empty cell (`Style::plain()`). A terminal device compares
     * frames this way, so a space that only changed its invisible colour costs nothing to draw.
     */
    public function blank(): Style
    {
        return $this->blank ??= ($this->attrs & (self::INVERSE | self::UNDERLINE | self::STRIKE)) !== 0
            ? $this
            : self::of(Color::DEFAULT, $this->bg, 0, $this->link, $this->linkId);
    }

    /**
     * A readable description such as `bold fg=1 bg=#203040 link=https://example.com`, empty for
     * the plain style. This is the notation `Surface::dump()` uses.
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
        if ($this->link !== '') {
            $parts[] = 'link=' . ($this->linkId === '' ? '' : $this->linkId . ';') . $this->link;
        }
        return implode(' ', $parts);
    }
}
