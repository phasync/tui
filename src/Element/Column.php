<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * Elements on top of each other, top to bottom: the element-tree form of `Split::vertical()`.
 *
 *     new Column([
 *         new Text('Title', class: 'title'),             // as high as its text
 *         $this->child('log', fn() => new Log()),         // the rest
 *         $this->child('input', fn() => new Editor()),    // one row (a one-line Editor's size)
 *     ])
 *
 * Each child is as high as its `height` says (cells, `'25%'`, `'1fr'` for a share of the rows
 * left, `'auto'` for what its content needs); without one, text and boxes take what their
 * content needs, components what their `measure()` says (a one-line field: one row), and
 * containers what their children need (the sum of their heights in a column, the highest in a
 * row); a component that takes any room (a `Log`), and a container with one in it, share what
 * is left. Every child is as wide as
 * the column. `$gap` blank rows go between the children. Null children are left out, for
 * `$open ? $panel : null`.
 */
final class Column extends Element
{
    /** @var list<Element> */
    public readonly array $children;

    /**
     * @param list<Element|null> $children
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     * @param int|string|null $padding blank cells inside it, around the children (`1`, `'0 1'`; the `padding` style)
     * @param string|null $align where the children go across it when narrower: `start`, `center`, `end` or `stretch` (the default; the `align-items` style)
     * @param string|list<string> $state its own states for the stylesheet; `$selected` adds `selected` (see `Element`)
     * @param (\Closure(Element): mixed)|null $onFocus called when the focus enters it, `$onBlur` when it leaves (see `Element`)
     */
    public function __construct(
        array $children,
        public readonly int $gap = 0,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        int|string|null $padding = null,
        ?string $align = null,
        bool $disabled = false,
        bool $selected = false,
        string|array $state = [],
        ?\Closure $onFocus = null,
        ?\Closure $onBlur = null,
    ) {
        $this->children = Children::of($children);
        $this->with($key, $id, $class, Children::style($style, ['padding' => $padding, 'align-items' => $align]), $width, $height, $disabled, $selected ?: null, $state === [] ? null : $state, $onFocus, $onBlur);
    }
}
