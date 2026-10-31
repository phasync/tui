<?php

namespace phasync\Tui;

/**
 * The state of a component: properties whose writes render the component again.
 *
 * Declare the state as a class with typed public properties, and give it to the component as
 * its `$state` (in the constructor or `mount()`):
 *
 *     final class CounterState extends State
 *     {
 *         public int $count = 0;
 *         public string $label = 'clicks';
 *     }
 *
 *     final class Counter extends Component
 *     {
 *         public function __construct()
 *         {
 *             $this->state = new CounterState();
 *         }
 *
 *         protected function onKey(Key $key): bool
 *         {
 *             $this->state->count++;          // renders again in the next frame; no requestRender()
 *             return true;
 *         }
 *     }
 *
 * A component that declares none gets a plain `State`, which takes properties of any name
 * (`$this->state->open = true`), untyped.
 *
 * Writes are checked against the declared types as usual (a `TypeError` for a string in an
 * int). A write that does not change the value (`===`) renders nothing. Many writes in one
 * moment are one render. Assign arrays whole (`$this->state->items = [...$this->state->items,
 * $item]`): an element written through the property (`$this->state->items[] = $item`) is not
 * seen (PHP reports it as an indirect modification). Objects in the state are not watched.
 *
 * **Shared state.** One State object may be the `$state` of several components (a parent
 * passes its own to its children): a write renders all of them.
 */
class State
{
    /** @var array<string, mixed> the values, while the properties are watched */
    private array $values = [];
    /** @var array<int, \WeakReference<Component>> the components rendered again on a write */
    private array $owners = [];
    private bool $watching = false;

    /**
     * @internal Render $component again whenever a property changes; called by the component
     * when it starts.
     */
    final public function watchFor(Component $component): void
    {
        $this->owners[spl_object_id($component)] = \WeakReference::create($component);
        if ($this->watching) {
            return;
        }
        $this->watching = true;
        // the declared properties are moved into $values, so every write goes through __set()
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || $p->isReadOnly()) {
                continue;
            }
            $name = $p->getName();
            if ($p->isInitialized($this)) {
                $this->values[$name] = $this->$name;
            }
            unset($this->$name);
        }
    }

    /**
     * Assign several properties at once (named arguments): `$state->set(count: 0, label: 'new')`.
     * One render follows, as for single writes.
     */
    final public function set(mixed ...$values): void
    {
        foreach ($values as $name => $value) {
            if (\is_int($name)) {
                throw new \ArgumentCountError('State::set() takes named arguments: set(count: 1)');
            }
            $this->__set($name, $value);
        }
    }

    /**
     * A read of a property.
     *
     * @return mixed
     */
    final public function __get(string $name)
    {
        if (!\array_key_exists($name, $this->values)) {
            if (property_exists($this, $name)) {
                throw new \Error('Typed property ' . static::class . "::\$$name must not be accessed before initialization");
            }
            throw new \Error('Undefined property ' . static::class . "::\$$name");
        }
        return $this->values[$name];
    }

    /** A write to a property: checked as the declared type, and the components holding the state render. */
    final public function __set(string $name, mixed $value): void
    {
        if (!$this->watching) {
            // (only reached for undeclared properties before the state is watched)
            $this->values[$name] = $value;
            return;
        }
        if (property_exists($this, $name)) {
            // check (and coerce) the value as the declared property does, then keep it in $values
            $this->$name = $value;
            $value = $this->$name;
            unset($this->$name);
        }
        if (\array_key_exists($name, $this->values) && $this->values[$name] === $value) {
            return;
        }
        $this->values[$name] = $value;
        foreach ($this->owners as $id => $owner) {
            $component = $owner->get();
            if ($component === null) {
                unset($this->owners[$id]);
            } else {
                $component->markDirty();
            }
        }
    }

    /** True if the property is set and not null. */
    final public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /** Forget a property (an undeclared one), as unset() does. */
    final public function __unset(string $name): void
    {
        unset($this->values[$name]);
    }

    /**
     * The properties and their values (for debugging, saving, comparing).
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        $declared = [];
        if (!$this->watching) {
            foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if (!$p->isStatic() && $p->isInitialized($this)) {
                    $declared[$p->getName()] = $p->getValue($this);
                }
            }
        }
        return [...$declared, ...$this->values];
    }
}
