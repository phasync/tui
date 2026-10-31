<?php

namespace phasync\Tui\Element;

use phasync\Tui\Element;

/**
 * Elements on top of each other, the first at the bottom: the element-tree form of `Stack`,
 * for a pop-up or a panel over the rest.
 *
 *     new Layers([
 *         $this->mainScreen(),
 *         $this->state->help ? new Box(new Help(), 'keys', width: 40, height: 10, style: ['align' => 'center']) : null,
 *     ])
 *
 * Each layer is `width` x `height` (cells or `'50%'`; `'auto'` for what its content needs;
 * the whole area by default) and placed by its `align` style: `left` (the default: the top-left
 * corner), `center` (in the middle) or `right` (the top-right corner). Keys go to the layer
 * focused last, as everywhere. For a modal dialog with an answer, see `Component::dialog()`.
 */
final class Layers extends Element
{
    /** @var list<Element> */
    public readonly array $children;

    /**
     * @param list<Element|null> $children bottom first
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     * @param string|list<string> $state its own states for the stylesheet; `$selected` adds `selected` (see `Element`)
     * @param (\Closure(Element): mixed)|null $onFocus called when the focus enters it, `$onBlur` when it leaves (see `Element`)
     */
    public function __construct(
        array $children,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        bool $disabled = false,
        bool $selected = false,
        string|array $state = [],
        ?\Closure $onFocus = null,
        ?\Closure $onBlur = null,
    ) {
        $this->children = Children::of($children);
        $this->with($key, $id, $class, $style, $width, $height, $disabled, $selected ?: null, $state === [] ? null : $state, $onFocus, $onBlur);
    }
}
