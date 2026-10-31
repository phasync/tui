<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Component;
use phasync\Tui\Element;

/**
 * @internal The component that shows an element that is not a component in an overlay
 * (`Component::overlay(new Text('tip'))`): it renders the element, and measures as it.
 */
final class Floating extends Component
{
    public function __construct(public Element $element)
    {
    }

    protected function render(): Element
    {
        return $this->element;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->measureElement($this->element, $cols, $rows);
    }
}
