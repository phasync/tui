<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Text;

/**
 * @internal A closure as a component: what `Tui::run(fn() => ...)` runs, and what shows an
 * element whose content is a closure (`new Text(fn() => ...)`, a `Box` title). Its render calls
 * the closure (or makes the element with the closure's value), so a `State` read there renders
 * this component again, and only it. It has no keys of its own; the focus goes on to what it
 * shows.
 */
final class Live extends Component
{
    /**
     * @param Element|\Closure(): (Element|null) $show what it shows: an element with a closure
     *        in it, an element, or a closure returning an element (a component included) or null
     */
    public function __construct(public Element|\Closure $show)
    {
    }

    /** @var array{?Element}|null the closure's value for the next render, when it was called already */
    private ?array $first = null;

    /** The closure was called already (the runner did, to see what it gives): its first render shows $value. */
    public function prime(?Element $value): void
    {
        $this->first = [$value];
    }

    protected function render(): ?Element
    {
        $show = $this->show;
        if ($show instanceof \Closure) {
            // (the closure's first value, when the runner called it already to see what it is)
            [$element, $this->first] = [$this->first !== null ? $this->first[0] : $show(), null];
            if ($element !== null && !$element instanceof Element) {
                throw new \UnexpectedValueException('The closure must return an Element or a Component, not ' . get_debug_type($element));
            }
            return $element ?? new Text('');
        }
        return $show instanceof Text || $show instanceof Box ? $show->resolved() : $show;
    }
}
