<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/** @internal The children of a container element: nulls left out, anything else refused. */
final class Children
{
    /**
     * @param array<mixed> $children
     * @return list<Element>
     */
    public static function of(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            if ($child === null) {
                continue;
            }
            if (!$child instanceof Element) {
                throw new \InvalidArgumentException('A child of an element is an Element or a Component, not ' . get_debug_type($child) . (\is_string($child) ? ' (use new Text())' : ''));
            }
            $out[] = $child;
        }
        return $out;
    }

    /**
     * The inline style of an element with what its constructor arguments set (`padding:`,
     * `align:`) under what its `style:` sets itself.
     *
     * @param array<string, mixed>|null $style
     * @param array<string, mixed>      $arguments null for not given
     * @return array<string, mixed>|null
     */
    public static function style(?array $style, array $arguments): ?array
    {
        $arguments = array_filter($arguments, fn($v) => $v !== null);
        return $arguments === [] ? $style : [...$arguments, ...($style ?? [])];
    }
}
