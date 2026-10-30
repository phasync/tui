<?php

namespace phasync\Tui;

/**
 * What the stylesheet says about one element (or one part of a widget) in its current states:
 * the cascade of the matching rules, the classes and the inline style, applied over what it
 * inherits from its parent. `Stylesheet::compute()` makes them; a widget asks for its own and
 * its parts' with `Component::style()`, and draws with `$computed->text` through `Ansi`.
 *
 *     $row = $this->style('row', 'current');
 *     $this->surface->write(Ansi::style($row->text) . $label . Ansi::reset());
 */
final class ComputedStyle
{
    /**
     * @param Style            $text        colours and attributes of the text (and the fill)
     * @param string|false     $border      the kind of border line (see `Viewport::BORDERS`), false for none
     * @param Style            $borderStyle colours and attributes of the border
     * @param array{int,int,int,int} $padding blank cells inside the border: top, right, bottom, left
     * @param string           $align       `'left'`, `'center'` or `'right'`
     * @param int|string|null  $width       the width asked for (cells, `'50%'`, `'1fr'`, `'auto'`), null if none
     * @param int|string|null  $height      the height asked for, null if none
     * @param bool             $hoverAware  @internal a `:hover` rule was considered for it
     */
    public function __construct(
        public readonly Style $text,
        public readonly string|false $border = false,
        public readonly ?Style $borderStyle = null,
        public readonly array $padding = [0, 0, 0, 0],
        public readonly string $align = 'left',
        public readonly int|string|null $width = null,
        public readonly int|string|null $height = null,
        public readonly bool $hoverAware = false,
    ) {
    }

    /** The plain style: default colours, no border, no padding. */
    public static function plain(): self
    {
        static $plain = null;
        return $plain ??= new self(Style::plain());
    }

    /** $text in this style's colours and attributes, followed by a reset (see `Ansi::styled()`). */
    public function apply(string $text): string
    {
        return Ansi::styled($text, $this->text);
    }
}
