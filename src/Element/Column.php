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
 * content needs, and components and containers share what is left. Every child is as wide as
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
