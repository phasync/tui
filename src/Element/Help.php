<?php

namespace phasync\Tui\Element;

use phasync\Tui\Component;
use phasync\Tui\Element;

/**
 * The keys that work right now: the bindings of the focused component and of its parents
 * (`Component::bindings()`), nearest first, as "key action" pairs on one wrapped line:
 *
 *     new Column([$this->mainScreen(), new Help()])     // a footer like "ctrl+s save  esc cancel"
 *
 * It follows the focus by itself. With `$for`, the bindings of that component and its parents
 * instead. It lists the bindings that have a description (`'ctrl+s' => ['save', 'Save']` in
 * `bindings()` or `bind()`); keys bound without one (an editor's arrow keys) are left out.
 * Styled as type `Help`, with the parts `key` and `action`.
 */
final class Help extends Element
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public readonly ?Component $for = null,
        public readonly string $separator = '  ',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
    ) {
        $this->with($key, $id, $class, $style, $width, $height);
    }
}
