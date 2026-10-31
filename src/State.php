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
 * (`$this->state->open = true`), untyped. A plain `State` can be made with values too, for a
 * program without classes: `$s = new State(['done' => 0])`.
 *
 * **Reading subscribes.** A component whose `render()` reads a property of a `State` (any
 * state, not only its own `$this->state`) renders again when that state is written: a closure
 * given to `Tui::run()` or `Tui::inline()` (which is a component), a `Text` with a closure for
 * content (`new Text(fn() => "{$s->done}%")`), a component showing a state another one owns.
 *
 *     $s = new State(['done' => 0]);
 *     fiber\go(function () use ($s) { while ($s->done < 100) { fiber\sleep(0.1); $s->done++; } });
 *     Tui::inline(fn() => new ProgressBar($s->done / 100));      // renders on every write
 *
 * Writes are checked against the declared types as usual (a `TypeError` for a string in an
 * int). A write that does not change the value (`===`) renders nothing. Many writes in one
 * moment are one render. **Arrays** can be changed in place: `$s->progress[$i] = 0.5`,
 * `$s->items[] = $item`, `unset($s->rows[$id])` count as changes (seen at the next tick, by
 * comparing the array with what it was when the program read it), as does assigning a new
 * array. Objects in the state are not watched: assign them anew, or keep their data in arrays.
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
    /** Reads made during renders, counted (see `reads()`). */
    private static int $reads = 0;
    /** @var array<string, array<mixed>> arrays handed out by reference (see __get()), as they were then */
    private array $lent = [];
    /** @var array<int, State>|null the states with arrays handed out, compared at the next tick */
    private static ?array $lenders = null;

    /**
     * A state with these values (by property name), watched from the start: what a program
     * without classes writes (`new State(['done' => 0, 'status' => 'starting'])`). A subclass
     * with typed properties is made with no arguments; one with a constructor of its own calls
     * `parent::__construct()` so that reads of it subscribe from the start (else only once a
     * component holds it as `$state`).
     *
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->watch();
        foreach ($values as $name => $value) {
            if (\is_int($name)) {
                throw new \InvalidArgumentException('State values are given by name: new State([\'done\' => 0])');
            }
            $this->__set($name, $value);
        }
    }

    /**
     * @internal Render $component again whenever a property changes; called by the component
     * when it starts.
     */
    final public function watchFor(Component $component): void
    {
        $this->owners[spl_object_id($component)] = \WeakReference::create($component);
        $this->watch();
    }

    /** Move the declared properties into $values, so that every read and write goes through __get() and __set(). */
    private function watch(): void
    {
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

    /** @internal How many reads of any State renders made so far: a change tells that code read state. */
    final public static function reads(): int
    {
        return self::$reads;
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
     * A read of a property. It returns a reference, so that an element of an array property
     * can be written in place (`$s->progress[$i] = 0.5`, `$s->items[] = $item`): such a write
     * is seen at the next tick (the array is compared with what it was when it was read) and
     * renders as any write does.
     *
     * @return mixed
     */
    final public function &__get(string $name)
    {
        // (read by a render: that component renders again when this changes)
        $reader = Component::reader();
        if ($reader !== null) {
            self::$reads++;
            if (($this->owners[spl_object_id($reader)] ?? null)?->get() !== $reader) {
                $this->owners[spl_object_id($reader)] = \WeakReference::create($reader);
            }
        }
        if (!\array_key_exists($name, $this->values)) {
            if (property_exists($this, $name)) {
                throw new \Error('Typed property ' . static::class . "::\$$name must not be accessed before initialization");
            }
            throw new \Error('Undefined property ' . static::class . "::\$$name");
        }
        if ($reader === null && \is_array($this->values[$name])) {
            $this->lend($name);     // (outside a render: perhaps written in place, see check())
        }
        return $this->values[$name];
    }

    /** The array $name is handed out by reference: compare it with this copy at the next tick. */
    private function lend(string $name): void
    {
        $this->lent[$name] ??= $this->values[$name];
        if (self::$lenders === null) {
            try {
                \fiber\go(static function (): void {
                    \fiber\sleep(0);
                    self::check();
                });
            } catch (\fiber\NotInRunException) {
                $this->lent = [];       // (no program runs: no component to render either)
                return;
            }
            self::$lenders = [];
        }
        self::$lenders[spl_object_id($this)] = $this;
    }

    /** @internal The arrays written in place since they were read render their components now (before a frame is drawn). */
    final public static function flush(): void
    {
        if (self::$lenders !== null && self::$lenders !== []) {
            self::check();
            self::$lenders = [];    // (the coroutine scheduled for them finds nothing)
        }
    }

    /** The arrays written in place since they were handed out render their components. */
    private static function check(): void
    {
        [$states, self::$lenders] = [self::$lenders ?? [], null];
        foreach ($states as $state) {
            [$lent, $state->lent] = [$state->lent, []];
            foreach ($lent as $name => $was) {
                if (($state->values[$name] ?? null) !== $was) {
                    $state->changed();
                    break;
                }
            }
        }
    }

    /** Render the components that hold or read this state. */
    private function changed(): void
    {
        foreach ($this->owners as $id => $owner) {
            $component = $owner->get();
            if ($component === null) {
                unset($this->owners[$id]);
            } else {
                $component->markDirty();
            }
        }
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
        unset($this->lent[$name]);
        $this->changed();
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
