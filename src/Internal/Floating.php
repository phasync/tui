<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Component;
use phasync\Tui\Component\ScrollView;
use phasync\Tui\Element;

/**
 * @internal The component that shows an element that is not a component in an overlay
 * (`Component::overlay(new Text('tip'))`): it renders the element in a `ScrollView` (an overlay
 * shrunk to the room there is scrolls), and measures as it.
 */
final class Floating extends Component
{
    public function __construct(public Element $element)
    {
    }

    protected function render(): Element
    {
        return $this->scroll();
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->scroll()->measure($cols, $rows);
    }

    private function scroll(): ScrollView
    {
        $scroll = $this->child('scroll', fn() => new ScrollView($this->element));
        if ($scroll->content !== $this->element) {
            $scroll->content = $this->element;
            $scroll->markDirty();
        }
        return $scroll;
    }
}
