<?php

namespace phasync\Tui;

/**
 * A key press.
 *
 * `name` is the key: a typed character as typed (`a`, `A`, `é`, `中`, `?`) or a named key in
 * lowercase: `enter`, `tab`, `backspace`, `escape`, `space`, `up`, `down`, `left`, `right`,
 * `home`, `end`, `pageup`, `pagedown`, `insert`, `delete`, `f1` to `f12`. `text` is what the key
 * types (empty for named keys and for Ctrl/Alt combinations).
 *
 * Match keys with `is()`, which takes the notation `__toString()` produces:
 *
 *     if ($key->is('ctrl+c')) ...
 *     if ($key->is('shift+tab') || $key->is('up')) ...
 *     if ($key->is('A')) ...          // a typed capital A (Shift is part of the character)
 *
 * Build keys with `Key::parse('ctrl+shift+up')` or the constructor, for example to send them to
 * a surface with `Surface::dispatch()`.
 */
final class Key implements Event
{
    /** The named keys (besides typed characters). */
    public const NAMES = ['enter', 'tab', 'backspace', 'escape', 'space', 'up', 'down', 'left', 'right',
        'home', 'end', 'pageup', 'pagedown', 'insert', 'delete',
        'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9', 'f10', 'f11', 'f12'];

    public readonly string $text;

    /**
     * @param string      $name  a character or one of `Key::NAMES`
     * @param string|null $text  the typed text; by default the character itself when no Ctrl or
     *                           Alt is held (and ' ' for space), else ''
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
        ?string $text = null,
    ) {
        $this->text = $text ?? (($ctrl || $alt) ? '' : (mb_strlen($name) === 1 ? $name : ($name === 'space' ? ' ' : '')));
    }

    /**
     * A key from its notation: modifiers `ctrl+`, `alt+`, `shift+` (any order, case-insensitive)
     * before a key name or character, such as `ctrl+c`, `shift+tab`, `alt+enter`, `f5`, `A`.
     */
    public static function parse(string $spec): Key
    {
        $ctrl = $alt = $shift = false;
        $rest = $spec;
        while (preg_match('/^(ctrl|alt|shift|meta)\+(.+)$/i', $rest, $m)) {
            match (strtolower($m[1])) {
                'ctrl' => $ctrl = true,
                'alt', 'meta' => $alt = true,
                'shift' => $shift = true,
            };
            $rest = $m[2];
        }
        $lower = strtolower($rest);
        $aliases = ['return' => 'enter', 'esc' => 'escape', 'del' => 'delete', 'ins' => 'insert', 'pgup' => 'pageup', 'pgdn' => 'pagedown', ' ' => 'space'];
        $lower = $aliases[$lower] ?? $lower;
        if (\in_array($lower, self::NAMES, true)) {
            return new Key($lower, $ctrl, $alt, $shift);
        }
        if (mb_strlen($rest) !== 1) {
            throw new \InvalidArgumentException("Unknown key '$spec'");
        }
        // with Ctrl or Alt a letter is named in lowercase; Shift is then a modifier
        if (($ctrl || $alt) && mb_strtolower($rest) !== $rest) {
            return new Key(mb_strtolower($rest), $ctrl, $alt, true);
        }
        return new Key(($ctrl || $alt) ? mb_strtolower($rest) : $rest, $ctrl, $alt, $shift);
    }

    /** True if this key matches the notation (see `parse()`). */
    public function is(string $spec): bool
    {
        $other = self::parse($spec);
        return $other->name === $this->name && $other->ctrl === $this->ctrl && $other->alt === $this->alt && $other->shift === $this->shift;
    }

    /** True for a key that types text (no Ctrl or Alt). */
    public function isText(): bool
    {
        return $this->text !== '';
    }

    /** The notation: modifiers in the order ctrl, alt, shift, then the name: `ctrl+shift+up`. */
    public function __toString(): string
    {
        return ($this->ctrl ? 'ctrl+' : '') . ($this->alt ? 'alt+' : '') . ($this->shift ? 'shift+' : '') . $this->name;
    }
}
