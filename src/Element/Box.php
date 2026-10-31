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
 *
 * The title takes markup (`'<b>log</b> <dim>(3 new)</dim>'`, see `Markup`) and goes at the
 * left of the border, or in the middle or at the right with the `title-align` style
 * (`class: 'title-center'`). A closure for the title (`fn() => "log ({$state->lines})"`)
 * makes the box a small component of its own, drawn again when a `State` the closure read
 * changes, without the component that placed it rendering.
 */
final class Box extends Element
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public readonly Element $child,
        /** @var string|\Closure(): string */
        public readonly string|\Closure $title = '',
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

    /** @internal This box with a closure's title as it is now (what the component that shows it renders). */
    public function resolved(): self
    {
        if (!$this->title instanceof \Closure) {
            return $this;
        }
        return new self($this->child, (string) ($this->title)(), null, $this->attribute('id'), $this->attribute('class'), $this->attribute('style'), disabled: $this->attribute('disabled'));
    }
}
