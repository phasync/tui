<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * Elements side by side, left to right: the element-tree form of `Split::horizontal()`.
 *
 *     new Row([
 *         $this->child('files', fn() => new Select())->with(width: 30),
 *         $this->child('editor', fn() => new Editor()),   // the rest
 *     ])
 *
 * Each child is as wide as its `width` says (cells, `'25%'`, `'1fr'` for a share of the columns
 * left, `'auto'` for what its content needs); without one, text and boxes take what their
 * content needs, components what their `measure()` says, and containers what their children
 * need (so a toolbar `Row` of one-line fields and buttons in a `Column` is one row high); a
 * component that takes any room, and a container with one in it, share what is left. Every
 * child is as high as
 * the row. `$gap` blank columns go between the children. Null children are left out, for
 * `$open ? $panel : null`.
 */
final class Row extends Element
{
    /** @var list<Element> */
    public readonly array $children;

    /**
     * @param list<Element|null> $children
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
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
        bool $disabled = false,
    ) {
        $this->children = Children::of($children);
        $this->with($key, $id, $class, $style, $width, $height, $disabled);
    }
}
