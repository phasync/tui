<?php

namespace phasync\Tui;

/**
 * A piece of an element tree: what `Component::render()` may return to describe its screen.
 *
 *     protected function render(): Element
 *     {
 *         return new Column([
 *             new Text('Name', class: 'title'),
 *             $this->child('name', fn() => new Editor(placeholder: 'your name')),
 *             new Text("Hello {$this->state->name}", height: '1fr'),
 *         ]);
 *     }
 *
 * The elements are `Column` and `Row` (windows on top of each other or side by side, sized as
 * `Split` sizes them), `Layers` (on top of each other, as `Stack`), `Box` (a border and a title
 * around one element), `Text` (styled text, wrapped) and `Help` (the keys of the focused
 * component); every `Component` is an element too, so widgets and your own components go in
 * the tree where they show. Elements are cheap values made anew on every render; the library
 * matches them with the previous render's by position (or by `key:`), so the surfaces, the
 * focus, the scroll positions and the components behind them stay.
 *
 * Every element takes these attributes, as named arguments of its constructor or with
 * `with()` (the way to give them to a component):
 *
 * - `key`: matches it with the previous render's element of the same key instead of by position,
 *   for items of a list that come and go;
 * - `id` and `class` (a name or a list of names): for the stylesheet's `#id` and `.class`;
 * - `style`: inline style properties (see `Stylesheet`), over every rule;
 * - `width`, `height`: its size in a `Row` (width) or `Column` (height), and in `Layers` both:
 *   cells, `'25%'`, `'1fr'` (a share of what is left) or `'auto'` (as much as its content needs);
 * - `disabled`: shown in the `:disabled` style; a disabled widget takes no input.
 */
abstract class Element
{
    /** @var array{key: ?string, id: ?string, class: list<string>, style: array<string, mixed>, width: int|string|null, height: int|string|null, disabled: bool} */
    private array $elementAttributes = ['key' => null, 'id' => null, 'class' => [], 'style' => [], 'width' => null, 'height' => null, 'disabled' => false];

    /**
     * Set attributes (see the class description); those not given stay as they are. Returns the
     * element itself: `$this->child('name', fn() => new Editor())->with(id: 'name', height: 1)`.
     *
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    final public function with(
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        ?bool $disabled = null,
    ): static {
        $before = $this->elementAttributes;
        foreach (['key' => $key, 'id' => $id, 'width' => $width, 'height' => $height, 'disabled' => $disabled, 'style' => $style] as $name => $value) {
            if ($value !== null) {
                if ($name === 'width' || $name === 'height') {
                    Internal\Size::parse($value === 'auto' ? 0 : $value);
                }
                $this->elementAttributes[$name] = $value;
            }
        }
        if ($class !== null) {
            $this->elementAttributes['class'] = \is_array($class) ? array_values($class) : preg_split('/\s+/', trim($class), -1, \PREG_SPLIT_NO_EMPTY);
        }
        if ($this instanceof Component && $this->elementAttributes !== $before) {
            $this->markDirty();     // (its look may depend on them)
        }
        return $this;
    }

    /**
     * An attribute: `'key'`, `'id'`, `'class'` (a list), `'style'` (an array), `'width'`,
     * `'height'` or `'disabled'`.
     *
     * @throws \InvalidArgumentException for another name
     */
    final public function attribute(string $name): mixed
    {
        if (!\array_key_exists($name, $this->elementAttributes)) {
            throw new \InvalidArgumentException("Unknown attribute '$name'");
        }
        return $this->elementAttributes[$name];
    }

    /**
     * The size the element needs for its content when it may use up to $cols x $rows cells, as
     * [cols, rows]; null when it takes whatever room it is given. Used for the size `'auto'`;
     * a component that knows its size (a one-line field) overrides it.
     *
     * @return array{int,int}|null
     */
    public function measure(int $cols, int $rows): ?array
    {
        return null;
    }

    /**
     * The names this element answers to as a type in a stylesheet: its class's short name and
     * those of its parent classes (`Confirm` is a `Dialog` too).
     *
     * @return list<string>
     */
    final public function types(): array
    {
        static $cache = [];
        $class = static::class;
        if (!isset($cache[$class])) {
            $names = [];
            for ($c = new \ReflectionClass($class); $c !== false; $c = $c->getParentClass()) {
                if (\in_array($c->getName(), [self::class, Component::class], true)) {
                    break;
                }
                if (!$c->isAnonymous()) {
                    $names[] = $c->getShortName();
                }
            }
            $cache[$class] = $names;
        }
        return $cache[$class];
    }

    /** @internal True if $other has the same attributes. */
    final protected function sameAttributes(Element $other): bool
    {
        return $other->elementAttributes === $this->elementAttributes;
    }

    /** @internal Take the attributes of $other (a component made anew by a render, for the one kept). */
    final protected function adoptAttributes(Element $other): bool
    {
        $changed = $other->elementAttributes !== $this->elementAttributes;
        $this->elementAttributes = $other->elementAttributes;
        return $changed;
    }
}
