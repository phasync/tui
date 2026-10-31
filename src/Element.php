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
 * - `disabled`: shown in the `:disabled` style; a disabled widget takes no input;
 * - `selected` and `state`: put the element in the state `selected` (`selected: $id === $current`)
 *   or in states of your own (`state: ['done']`), for `selected:` classes and `:selected` rules
 *   (`new Text('<b>Card</>', class: 'selected:bg-sky-700', selected: true)`);
 * - `onFocus`, `onBlur`: closures called (with the element) when the focus enters the element
 *   (a component in it, or the component itself, gets the keys) and when it leaves, so a
 *   program without classes can follow the focused row: `onFocus: fn() => $s->current = $id`.
 * - `onKey`: the element's keys, for a program without classes. An element with `onKey` takes
 *   the focus: Tab and Shift-Tab stop at it, a click focuses it, `focus:` classes and
 *   `:focused` rules show it, and while it has the focus its keys come here first. Either a
 *   map of keys to closures in the notation of `Component::bindings()`
 *   (`['enter' => fn() => $s->open = $id, 'delete' => [fn() => $s->remove($id), 'remove']]`:
 *   a bound key is taken), or one closure for every key, `fn(Key $key, Element $element):
 *   bool`, that returns true when it took the key (`fn(Key $k) => $k->isText() && $s->find($k->text)`).
 *   A key nobody takes goes on to the component that placed the element and its parents.
 * - `onMouse`: `fn(Mouse $mouse, Element $element)`, called for the mouse over the element:
 *   presses, drags and releases (taken unless it returns false; a drag and the release go to
 *   the element that took the press, also outside it), and the wheel (taken only when it
 *   returns true, else it scrolls as usual). `$mouse->x`/`y` are relative to the element;
 *   `$mouse->over()` lists the elements under the mouse, innermost first, so the release of a
 *   drag tells where it was dropped.
 *
 * Handlers may wait (`fiber\await(Confirm::ask(...))`): the input after them goes on meanwhile.
 *
 * An element is in the state `hover` while the mouse is over it, when a `hover:` class or a
 * `:hover` rule asks for it (it then follows the mouse); `focused` while the focus is in it.
 */
abstract class Element
{
    /** @var array{key: ?string, id: ?string, class: list<string>, style: array<string, mixed>, width: int|string|null, height: int|string|null, disabled: bool, state: list<string>, onFocus: ?\Closure, onBlur: ?\Closure, onKey: \Closure|array|null, onMouse: ?\Closure} */
    private array $elementAttributes = ['key' => null, 'id' => null, 'class' => [], 'style' => [], 'width' => null, 'height' => null,
        'disabled' => false, 'state' => [], 'onFocus' => null, 'onBlur' => null, 'onKey' => null, 'onMouse' => null];

    /**
     * Set attributes (see the class description); those not given stay as they are. Returns the
     * element itself: `$this->child('name', fn() => new Editor())->with(id: 'name', height: 1)`.
     *
     * `selected: true` adds the state `selected` to `state`, `selected: false` takes it out.
     *
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     * @param string|list<string>|null $state the element's own states (replacing those it had)
     * @param (\Closure(Element): mixed)|null $onFocus
     * @param (\Closure(Element): mixed)|null $onBlur
     * @param (\Closure(Key, Element): bool)|array<string, mixed>|null $onKey
     * @param (\Closure(Mouse, Element): mixed)|null $onMouse
     */
    final public function with(
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        ?bool $disabled = null,
        ?bool $selected = null,
        string|array|null $state = null,
        ?\Closure $onFocus = null,
        ?\Closure $onBlur = null,
        \Closure|array|null $onKey = null,
        ?\Closure $onMouse = null,
    ): static {
        $before = $this->elementAttributes;
        if ($state !== null) {
            $this->elementAttributes['state'] = \is_array($state) ? array_values($state) : preg_split('/[\s,]+/', trim($state), -1, \PREG_SPLIT_NO_EMPTY);
        }
        if ($selected !== null) {
            $states = array_values(array_diff($this->elementAttributes['state'], ['selected']));
            $this->elementAttributes['state'] = $selected ? [...$states, 'selected'] : $states;
        }
        if (\is_array($onKey)) {
            Component::normalizeBindings($onKey);     // (a mistake shows where it is made)
        }
        foreach (['key' => $key, 'id' => $id, 'width' => $width, 'height' => $height, 'disabled' => $disabled, 'style' => $style,
            'onFocus' => $onFocus, 'onBlur' => $onBlur, 'onKey' => $onKey, 'onMouse' => $onMouse] as $name => $value) {
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
     * `'height'`, `'disabled'`, `'state'` (a list), `'onFocus'`, `'onBlur'`, `'onKey'` or `'onMouse'`.
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
