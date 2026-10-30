<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * A run of text in a `Text` with a look of its own, by class or inline style:
 *
 *     new Text(['Press ', new Span('q', class: 'key'), ' to quit, ', new Span('?', style: ['bold' => true]), ' for help'])
 *
 * It inherits the colours and attributes of the text it is in, and the stylesheet styles it as
 * type `Span` (`'Span.key' => ['color' => '$accent']`). A span on its own is not laid out; put
 * it in a `Text`.
 */
final class Span extends Element
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public readonly string $text,
        string|array|null $class = null,
        ?array $style = null,
        ?string $id = null,
    ) {
        $this->with(id: $id, class: $class, style: $style);
    }
}
