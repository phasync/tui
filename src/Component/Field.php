<?php

namespace phasync\Tui\Component;

/**
 * A component that holds a value a `Form` collects: `Editor` (its text), `Checkbox` (checked or
 * not), `Select` (the key picked, or the keys with `multiple`). Implement it in a field of your
 * own to have a `Form` read it; the form finds fields by their `id`.
 */
interface Field
{
    /** The field's current value. */
    public function value(): mixed;
}
