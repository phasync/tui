<?php

namespace phasync\Tui\Element;

use phasync\Tui\Component;
use phasync\Tui\Element;

/**
 * The keys that work right now: the bindings of the focused component and of its parents
 * (`Component::bindings()`), nearest first, as "key action" pairs on one line:
 *
 *     new Column([$this->mainScreen(), new Help()])     // a footer like "ctrl+s save  esc cancel"
 *
 * It follows the focus by itself. With `$for`, the bindings of that component and its parents
 * instead. `$show` chooses what it lists: `'all'` (the default), `'focused'` (the focused
 * component's own keys only) or `'application'` (those of the components around it, up to the
 * root: the program's keys). It lists the bindings that have a description (`'ctrl+s' =>
 * ['save', 'Save']` in `bindings()` or `bind()`); keys bound without one (an editor's arrow
 * keys) are left out. It is one row, cut with "…" where it does not fit; given a `height` it
 * wraps onto that many rows. Styled as type `Help`, with the parts `key` and `action`.
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
        public readonly string $show = 'all',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
    ) {
        if (!\in_array($show, ['all', 'focused', 'application'], true)) {
            throw new \InvalidArgumentException("Unknown Help \$show '$show': use 'all', 'focused' or 'application'");
        }
        $this->with($key, $id, $class, $style, $width, $height);
    }
}
