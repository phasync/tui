<?php

namespace phasync\Tui;

/**
 * The look of the elements and widgets of a program, as rules: a selector and properties.
 *
 *     $sheet = Stylesheet::default()->with([
 *         'Text.title'      => ['bold' => true, 'color' => '$accent'],
 *         '#name'           => ['width' => 30],
 *         'Editor:focused'  => ['background' => '#203040'],
 *         'Select::row:current' => ['background' => '$accent', 'color' => 'black'],
 *     ], vars: ['accent' => 'magenta']);
 *     $app->setStylesheet($sheet);
 *
 * **Selectors** (several separated by commas): an element type (`Text`, `Box`, `Editor`, your
 * component's class name; a subclass matches its parents' names too) or `*`, then any number of
 * `#id`, `.class` and states `:focused`, `:disabled`, `:hover`; then optionally `::part` and the
 * part's states, for the pieces a widget draws (`Select::row:current`, `Editor::selection`,
 * `Dialog::button:focused`; each widget lists its parts and their states). `:focused` is true
 * for an element on the focus path (a `Box` whose content has the keys is focused), `:disabled`
 * for one made with `disabled: true`, `:hover` for a component while the mouse is over it (the
 * component asks for mouse motion reports then, and so takes its mouse events). There are no
 * combinators: what a parent sets for the text inherits instead (below).
 *
 * **Properties.** `color`, `background` (a name such as `red` or `bright-blue`, `default`,
 * `'#rrggbb'`, a palette index 0-255, or a variable `'$accent'`); `bold`, `dim`, `italic`,
 * `underline`, `inverse`, `strike` (true or false); `border` (`'none'`, or a kind of line,
 * `single`, `round`, `double`, `heavy`, `ascii`, and/or a colour: `'round $accent'`, `'red'`;
 * what is left out stays as a less specific rule set it); `padding` (cells inside the border:
 * `1`, `'0 1'` for top/bottom and left/right, or `'0 1 0 1'` top, right, bottom, left); `align`
 * (`left`, `center`, `right`: where text goes in its box, and where a layer of `Layers` goes);
 * `width`, `height` (as `Column`/`Row` sizes take them: cells, `'25%'`, `'1fr'`, `'auto'`).
 * Colours and text attributes inherit: a `Box` with `color: green` makes its `Text` green
 * unless a rule for the `Text` says otherwise. The others apply to the element itself.
 *
 * **Cascade**: rules by specificity (ids, then classes/states/parts, then types; a later rule
 * wins a tie), then the element's inline `style:` over everything. **Variables** (`$name` in a
 * value) come from `vars`; the default theme defines `accent`, `muted`, `panel`, `error`,
 * `success` and `warning` in the 16 terminal colours, so it follows the terminal's palette
 * and reads on dark and light backgrounds alike.
 */
final class Stylesheet
{
    private const COLORS = [
        'black' => 0, 'red' => 1, 'green' => 2, 'yellow' => 3, 'blue' => 4, 'magenta' => 5, 'cyan' => 6, 'white' => 7,
        'bright-black' => 8, 'gray' => 8, 'grey' => 8, 'bright-red' => 9, 'bright-green' => 10, 'bright-yellow' => 11,
        'bright-blue' => 12, 'bright-magenta' => 13, 'bright-cyan' => 14, 'bright-white' => 15,
    ];
    private const ATTRS = ['bold' => Style::BOLD, 'dim' => Style::DIM, 'italic' => Style::ITALIC,
        'underline' => Style::UNDERLINE, 'inverse' => Style::INVERSE, 'strike' => Style::STRIKE];
    private const PROPERTIES = ['color', 'background', 'bold', 'dim', 'italic', 'underline', 'inverse', 'strike',
        'border', 'padding', 'align', 'width', 'height'];

    /** @var list<array{array<string, mixed>, list<array{list<string>, ?string, list<string>, list<string>, ?string, list<string>}>, array{int,int,int}, int}> rules: properties, selectors, specificity, order */
    private array $rules = [];
    /** @var array<string, mixed> */
    private array $vars;
    private static ?Stylesheet $default = null;

    /**
     * @param array<string, array<string, mixed>> $rules properties by selector, in order
     * @param array<string, mixed>                $vars  values by variable name (without `$`)
     * @throws \InvalidArgumentException for a selector or property it does not know
     */
    public function __construct(array $rules = [], array $vars = [])
    {
        $this->vars = $vars;
        $this->add($rules);
    }

    /**
     * The default theme: borders in the muted colour that turn to the accent colour with the
     * focus, dimmed placeholders, a highlighted current row, inverse selections and buttons.
     */
    public static function default(): self
    {
        return self::$default ??= new self([
            'Box' => ['border' => 'round $muted'],
            'Box:focused' => ['border' => '$accent'],
            'Editor:focused' => ['background' => '$panel'],
            'Editor:disabled' => ['dim' => true],
            'Editor::placeholder' => ['color' => '$muted'],
            'Editor::selection' => ['inverse' => true],
            'Table::header' => ['bold' => true, 'underline' => true],
            'Table::header:sorted' => ['color' => '$accent'],
            'Table::row:current' => ['inverse' => true],
            'Select::row:selected' => ['color' => '$accent', 'bold' => true],
            'Select:disabled' => ['dim' => true],
            'Select::filter' => ['color' => '$accent'],
            'Dialog' => ['border' => 'round $accent', 'padding' => '0 1', 'align' => 'center'],
            'Dialog::title' => ['bold' => true],
            'Dialog::button' => ['color' => '$muted'],
            'Dialog::button:focused' => ['inverse' => true, 'bold' => true, 'color' => 'default'],
            'Help' => ['color' => '$muted'],
            'Help::key' => ['color' => '$accent', 'bold' => true],
            'Text.error' => ['color' => '$error'],
            'Text.muted' => ['color' => '$muted'],
            'Text.title' => ['bold' => true],
        ], [
            'accent' => 'cyan',
            'muted' => 'bright-black',
            'panel' => 'bright-black',
            'error' => 'red',
            'success' => 'green',
            'warning' => 'yellow',
        ]);
    }

    /**
     * A copy with more rules (after these, so they win ties) and variables set or replaced: how
     * an application builds its sheet on the default theme.
     *
     * @param array<string, array<string, mixed>> $rules
     * @param array<string, mixed>                $vars
     */
    public function with(array $rules = [], array $vars = []): self
    {
        $copy = clone $this;
        $copy->vars = [...$this->vars, ...$vars];
        $copy->add($rules);
        return $copy;
    }

    /** The value of variable $name (without `$`), or null when it is not set. */
    public function var(string $name): mixed
    {
        return $this->vars[$name] ?? null;
    }

    /**
     * The style of $element in $states, inheriting colours and attributes from $parent; or of
     * its $part in $partStates. Widgets use `Component::style()`, which fills in the element's
     * states; this is for code that draws elements of its own.
     *
     * @param list<string> $states     the element's states: `focused`, `disabled`, `hover`
     * @param list<string> $partStates the part's states (each widget lists them)
     */
    public function compute(Element $element, array $states = [], ?ComputedStyle $parent = null, ?string $part = null, array $partStates = []): ComputedStyle
    {
        if ($element->attribute('disabled') && !\in_array('disabled', $states, true)) {
            $states[] = 'disabled';
        }
        $types = $element->types();
        $id = $element->attribute('id');
        $classes = $element->attribute('class');
        $hoverAware = false;
        $matched = [];
        foreach ($this->rules as $i => [$props, $selectors, , $order]) {
            foreach ($selectors as $sel) {
                $m = self::matches($sel, $types, $id, $classes, $states, $part, $partStates);
                $hoverAware = $hoverAware || $m === 'hover' || ($m === true && \in_array('hover', $sel[3], true));
                if ($m === true) {
                    $matched[] = [$sel[6], $order, $props];
                    break;
                }
            }
        }
        usort($matched, fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $set = [];
        foreach ($matched as [, , $props]) {
            $set = self::merge($set, $props);
        }
        if ($part === null) {
            $set = self::merge($set, self::check($element->attribute('style')));
        } else {
            // a part inherits from its element
            $parent = $this->compute($element, $states, $parent);
            $hoverAware = $hoverAware || $parent->hoverAware;
        }
        return $this->resolve($set, $parent, $hoverAware);
    }

    /** @param array<string, array<string, mixed>> $rules */
    private function add(array $rules): void
    {
        foreach ($rules as $selector => $props) {
            $selectors = [];
            foreach (explode(',', (string) $selector) as $one) {
                $selectors[] = self::parseSelector(trim($one));
            }
            $this->rules[] = [self::check($props), $selectors, [0, 0, 0], \count($this->rules)];
        }
    }

    /**
     * @return array{list<string>, ?string, list<string>, list<string>, ?string, list<string>, array{int,int,int}}
     *         type (empty: any), id, classes, states, part, part states, specificity
     */
    private static function parseSelector(string $s): array
    {
        if (!preg_match('/^(\*|[A-Za-z_][A-Za-z0-9_]*)?((?:[#.:][A-Za-z_][A-Za-z0-9_-]*)*)(?:::([A-Za-z_][A-Za-z0-9_-]*)((?::[A-Za-z_][A-Za-z0-9_-]*)*))?$/', $s, $m) || $s === '') {
            throw new \InvalidArgumentException("Invalid selector '$s': use Type#id.class:state::part:state");
        }
        $type = ($m[1] ?? '') === '' || $m[1] === '*' ? [] : [$m[1]];
        $id = null;
        $classes = $states = [];
        preg_match_all('/([#.:])([A-Za-z_][A-Za-z0-9_-]*)/', $m[2] ?? '', $parts, \PREG_SET_ORDER);
        foreach ($parts as [, $sigil, $name]) {
            match ($sigil) {
                '#' => $id = $name,
                '.' => $classes[] = $name,
                ':' => $states[] = $name,
            };
        }
        $part = ($m[3] ?? '') === '' ? null : $m[3];
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_-]*)/', $m[4] ?? '', $ps);
        $partStates = $ps[1];
        $spec = [$id === null ? 0 : 1, \count($classes) + \count($states) + ($part === null ? 0 : 1) + \count($partStates), \count($type)];
        return [$type, $id, $classes, $states, $part, $partStates, $spec];
    }

    /**
     * True when the selector matches; 'hover' when only the hover state is missing.
     *
     * @param array{list<string>, ?string, list<string>, list<string>, ?string, list<string>, array{int,int,int}} $sel
     */
    private static function matches(array $sel, array $types, ?string $id, array $classes, array $states, ?string $part, array $partStates): bool|string
    {
        [$type, $sid, $sclasses, $sstates, $spart, $spartStates] = $sel;
        if ($spart !== $part || ($type !== [] && !\in_array($type[0], $types, true))
            || ($sid !== null && $sid !== $id) || array_diff($sclasses, $classes) !== []) {
            return false;
        }
        $missing = [...array_diff($sstates, $states), ...array_diff($spartStates, $partStates)];
        if ($missing === []) {
            return true;
        }
        return $missing === ['hover'] && \in_array('hover', $sstates, true) ? 'hover' : false;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function check(array $props): array
    {
        foreach (array_keys($props) as $name) {
            if (!\in_array($name, self::PROPERTIES, true)) {
                throw new \InvalidArgumentException("Unknown style property '$name'; one of " . implode(', ', self::PROPERTIES));
            }
        }
        return $props;
    }

    /**
     * $props over $set; a border is merged by its parts (kind, colour).
     *
     * @param array<string, mixed> $set
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function merge(array $set, array $props): array
    {
        foreach ($props as $name => $value) {
            if ($name === 'border') {
                $old = $set['border'] ?? [null, null];
                [$kind, $color] = self::parseBorder($value);
                $set['border'] = [$kind ?? $old[0], $color ?? $old[1]];
            } else {
                $set[$name] = $value;
            }
        }
        return $set;
    }

    /** @return array{string|false|null, mixed} the kind of line and the colour, null where not given */
    private static function parseBorder(mixed $value): array
    {
        if ($value === false || $value === 'none') {
            return [false, null];
        }
        if ($value === true) {
            return ['single', null];
        }
        $kind = $color = null;
        foreach (preg_split('/\s+/', trim((string) $value)) as $token) {
            if (isset(Viewport::BORDERS[$token])) {
                $kind = $token;
            } elseif ($token !== '') {
                $color = $token;
            }
        }
        return [$kind, $color];
    }

    /** @param array<string, mixed> $set */
    private function resolve(array $set, ?ComputedStyle $parent, bool $hoverAware): ComputedStyle
    {
        $base = $parent?->text ?? Style::plain();
        $fg = \array_key_exists('color', $set) ? $this->color($set['color']) : $base->fg;
        $bg = \array_key_exists('background', $set) ? $this->color($set['background']) : $base->bg;
        $attrs = $base->attrs;
        foreach (self::ATTRS as $name => $bit) {
            if (\array_key_exists($name, $set)) {
                $attrs = $this->value($set[$name]) ? $attrs | $bit : $attrs & ~$bit;
            }
        }
        $text = Style::of($fg, $bg, $attrs);
        [$kind, $color] = $set['border'] ?? [false, null];
        $kind ??= $color !== null ? 'single' : false;
        $borderStyle = Style::of($color === null ? $fg : $this->color($color), $bg);
        return new ComputedStyle(
            $text,
            $kind,
            $borderStyle,
            self::padding($this->value($set['padding'] ?? 0)),
            (string) $this->value($set['align'] ?? 'left'),
            isset($set['width']) ? $this->value($set['width']) : null,
            isset($set['height']) ? $this->value($set['height']) : null,
            $hoverAware,
        );
    }

    /** A value with variables resolved. */
    private function value(mixed $value, int $depth = 0): mixed
    {
        if (\is_string($value) && str_starts_with($value, '$')) {
            $name = substr($value, 1);
            if (!\array_key_exists($name, $this->vars) || $depth > 10) {
                throw new \InvalidArgumentException("Unknown style variable '$value'");
            }
            return $this->value($this->vars[$name], $depth + 1);
        }
        return $value;
    }

    /** A colour value as a `Color` int. */
    private function color(mixed $value): int
    {
        $value = $this->value($value);
        if (\is_int($value)) {
            return $value;
        }
        $name = strtolower(trim((string) $value));
        if ($name === 'default' || $name === '') {
            return Color::DEFAULT;
        }
        if (isset(self::COLORS[$name])) {
            return self::COLORS[$name];
        }
        if (str_starts_with($name, '#')) {
            return Color::hex($name);
        }
        if (ctype_digit($name)) {
            return (int) $name;
        }
        throw new \InvalidArgumentException("Unknown colour '$value'");
    }

    /** @return array{int,int,int,int} */
    private static function padding(mixed $value): array
    {
        $parts = \is_array($value) ? array_values($value) : array_map('intval', preg_split('/\s+/', trim((string) $value)));
        $parts = array_map(fn($v) => max(0, (int) $v), $parts);
        return match (\count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            4 => [$parts[0], $parts[1], $parts[2], $parts[3]],
            default => throw new \InvalidArgumentException('Padding is 1, 2 or 4 numbers'),
        };
    }
}
