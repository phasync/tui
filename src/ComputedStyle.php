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
     * @param int|null         $maxWidth    the most columns its content size may take (`max-width`), null for no limit
     * @param int|null         $maxHeight   the most rows its content size may take (`max-height`), null for no limit
     * @param array{int,int,int,int} $margin blank cells around it, inside its parent's room: top, right, bottom, left
     * @param int|null         $gap         a container's blank cells between its children (`gap`), null: its own `gap:`
     * @param string           $titleAlign  where a border's title goes: `'left'`, `'center'` or `'right'`
     * @param bool|null        $wrap        text wraps at spaces (true) or each line is cut (false); null: as the element says
     * @param float|null       $grow        the share (`fr`) of its parent's room left that it takes along the parent, null for none
     * @param float|null       $shrink      0: it keeps the size its content needs when siblings are squeezed; null: as usual
     * @param string           $alignItems  a container's children across it: `'stretch'`, `'start'`, `'center'` or `'end'`
     * @param string           $justify     a container's children along it: `'start'`, `'center'`, `'end'` or `'between'`
     * @param bool             $hidden      `display: none`: not shown, as if not in the tree
     * @param string|null      $direction   a container laid out as a `'row'` or a `'column'`, null: as its element is
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
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly array $margin = [0, 0, 0, 0],
        public readonly ?int $gap = null,
        public readonly string $titleAlign = 'left',
        public readonly ?bool $wrap = null,
        public readonly ?float $grow = null,
        public readonly ?float $shrink = null,
        public readonly string $alignItems = 'stretch',
        public readonly string $justify = 'start',
        public readonly bool $hidden = false,
        public readonly ?string $direction = null,
    ) {
    }

    /** The plain style: default colours, no border, no padding. */
    public static function plain(): self
    {
        static $plain = null;
        return $plain ??= new self(Style::plain());
    }

    /**
     * The colours and attributes as inline style properties (`['color' => ..., 'bold' => true]`),
     * for a `Text` or `Span` a widget draws in one of its parts' styles:
     * `new Text($title, style: $this->style('title')->properties())`.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $s = $this->text;
        $out = ['color' => $s->fg, 'background' => $s->bg];
        foreach (['bold' => Style::BOLD, 'dim' => Style::DIM, 'italic' => Style::ITALIC, 'underline' => Style::UNDERLINE, 'inverse' => Style::INVERSE, 'strike' => Style::STRIKE] as $name => $bit) {
            $out[$name] = ($s->attrs & $bit) !== 0;
        }
        return $out;
    }

    /** $text in this style's colours and attributes, followed by a reset (see `Ansi::styled()`). */
    public function apply(string $text): string
    {
        return Ansi::styled($text, $this->text);
    }
}
