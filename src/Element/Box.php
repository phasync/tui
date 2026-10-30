<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * A border, with a title in it, around one element:
 *
 *     new Box($this->child('log', fn() => new Log()), 'log')
 *     new Box(new Text($summary), 'summary', style: ['border' => 'double', 'padding' => '0 1'])
 *
 * The border's line and colour come from the stylesheet (`border`; the default theme draws a
 * round line in the muted colour, in the accent colour while the content has the focus), and
 * `padding` puts blank cells between the border and the content. Without a size of its own a
 * box is as large as its content needs (`'auto'`); give it a `height`/`width` to fix it.
 */
final class Box extends Element
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public readonly Element $child,
        public readonly string $title = '',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, $width, $height, $disabled);
    }
}
