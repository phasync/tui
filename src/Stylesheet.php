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
 * Elements also take **utility classes** in `class:`, with the Tailwind vocabulary, so most
 * screens need no stylesheet of their own: `new Box($log, 'log', class: 'border-double
 * border-sky-500 p-1 grow')`, `new Text('Saved', class: 'text-green-400 bold')`. They are
 * listed below; each is a stylesheet property, so a theme sets the same things.
 *
 * **Selectors** (several separated by commas): an element type (`Text`, `Box`, `Editor`, your
 * component's class name; a subclass matches its parents' names too) or `*`, then any number of
 * `#id`, `.class` and states `:focused`, `:disabled`, `:hover`, `:selected`, `:sm`, `:md`,
 * `:lg`; then optionally `::part` and the part's states, for the pieces a widget draws
 * (`Select::row:current`, `Editor::selection`, `Dialog::button:focused`; each widget lists its
 * parts and their states). `:focused` is true for an element on the focus path (a `Box` whose
 * content has the keys is focused), `:disabled` for one made with `disabled: true`, `:hover`
 * for a component while the mouse is over it, and on a part (`Tabs::tab:hover`) for the piece
 * under the mouse, as the widget tells it; `:selected` and other states of a component's own
 * come from `Component::setStyleState()`. `:sm`, `:md` and `:lg` are the width of the screen
 * (the root surface): under 60 columns, 60 to 99, 100 and more. A `:hover` rule for a
 * component or one of its parts makes the component follow the mouse: it asks for mouse motion
 * reports and takes its mouse events, as `Component::wantsPointer()` does. There are no
 * combinators: what a parent sets for the text inherits instead (below).
 *
 * **Properties.**
 * - `color`, `background`: a name of the 16 (`red`, `bright-blue`, `gray`), `default`, a
 *   Tailwind colour (`sky-400`), `'#rrggbb'`, a palette index 0-255, or a variable
 *   (`'$accent'`); see `Color::parse()`. A terminal with fewer colours shows the nearest.
 * - `bold`, `dim`, `italic`, `underline`, `inverse`, `strike`: true or false.
 * - `border`: `'none'` (or false), true (a border, of the kind set elsewhere, else single
 *   lines), a kind of line (`single`, `round`, `double`, `heavy`, `ascii`) and/or a colour:
 *   `'round $accent'`, `'red'`; what is left out stays as a less specific rule set it.
 *   `title-align`: where a border's title goes, `left` (the default), `center` or `right`.
 * - `padding` (cells inside the border: `1`, `'0 1'` for top/bottom and left/right, or
 *   `'0 1 0 1'` top, right, bottom, left; or one side: `padding-top`, `padding-right`,
 *   `padding-bottom`, `padding-left`); `margin` (cells around the element, inside its parent's
 *   room, the same forms: `margin`, `margin-top`, ...).
 * - `align` (`left`, `center`, `right`: where text goes in its box, and where a layer of
 *   `Layers` goes); `wrap` (true: text wraps at spaces; false: each line is cut with "…").
 * - `width`, `height` (as `Column`/`Row` sizes take them: cells, `'25%'`, `'1fr'`, `'auto'`);
 *   `max-width`, `max-height` (cells: the most an element sized by its content, `'auto'`, may
 *   take, and an overlay: a completion list of up to 8 rows, `['max-height' => 8]`);
 *   `flex-grow` (a number: the element takes that share, as `'Nfr'`, of the room its parent
 *   `Column` or `Row` has left, along it); `flex-shrink` (0: it keeps the size its content
 *   needs, also when its siblings must be squeezed); `display` (`'none'`: not shown, as if left
 *   out of the tree; `'block'`: shown).
 * - For a `Column` or `Row` (a container): `gap` (blank cells between its children, over its
 *   `gap:` argument), `flex-direction` (`row` or `column`: lay the children out side by side
 *   or on top of each other, whichever the element is), `align-items` (`stretch`, the default:
 *   every child as wide as a column or as high as a row; `start`, `center`, `end`: as large as
 *   its content, placed there) and `justify-content` (`start`, `center`, `end`, `between`:
 *   where the children go along it when they leave room).
 *
 * Colours and text attributes inherit: a `Box` with `color: green` makes its `Text` green
 * unless a rule for the `Text` says otherwise. The others apply to the element itself.
 *
 * **Utility classes**, each setting the properties above (`{n}` is a number of cells):
 *
 * | Class | Sets |
 * |---|---|
 * | `p-{n}`, `px-{n}`, `py-{n}`, `pt-`, `pr-`, `pb-`, `pl-{n}` | padding (all, left and right, top and bottom, one side) |
 * | `m-{n}`, `mx-`, `my-`, `mt-`, `mr-`, `mb-`, `ml-{n}` | margin |
 * | `gap-{n}` | gap |
 * | `border`, `border-single`, `border-round`, `border-double`, `border-heavy`, `border-ascii`, `border-none` | border (on; its kind of line; off) |
 * | `border-{color}` | the border's colour |
 * | `title-left`, `title-center`, `title-right` | title-align |
 * | `text-{color}`, `bg-{color}` | color, background |
 * | `bold`, `dim`, `italic`, `underline`, `strike`, `inverse` | the attribute |
 * | `text-left`, `text-center`, `text-right` | align |
 * | `truncate`, `nowrap`, `wrap` | wrap (false, false, true) |
 * | `w-{n}`, `h-{n}`, `w-1/2` (any fraction), `w-full`, `w-auto` (and `h-`) | width, height |
 * | `max-w-{n}`, `max-h-{n}` | max-width, max-height |
 * | `grow`, `grow-{n}`, `shrink-0`, `shrink` | flex-grow (1, n), flex-shrink (0, 1) |
 * | `items-start`, `items-center`, `items-end`, `items-stretch` | align-items |
 * | `justify-start`, `justify-center`, `justify-end`, `justify-between` | justify-content |
 * | `hidden`, `block` | display (none, block) |
 * | `flex-row`, `flex-col` | flex-direction (row, column) |
 *
 * A `{color}` is a Tailwind family and shade (`sky-400`, `slate-800`), one of the 16 names
 * (`red`, `bright-blue`), a palette index (`208`) or a hex value in brackets (`[#ff5500]`).
 * A class takes **variants** as prefixes: `focus:`, `hover:`, `disabled:`, `selected:` apply
 * it only in that state (`focus:bg-sky-800`), and `sm:`, `md:`, `lg:` only at that width of
 * the screen (under 60 columns, 60 to 99, 100 and more), so `'flex-col lg:flex-row'` puts the
 * children side by side on a wide terminal and on top of each other otherwise; variants
 * combine (`lg:focus:bold`). A class that is not a utility is the program's own (for its rules,
 * `.title`) and is otherwise ignored; `explain()` tells what an element's classes did.
 *
 * **Cascade**: the rules by specificity (ids, then classes/states/parts, then types; a later
 * rule wins a tie), then the utility classes in the order written (a later one wins), then
 * the element's inline `style:` over everything. **Variables** (`$name` in a value) come from
 * `vars`; the default theme defines `accent`, `muted`, `panel`, `error`, `success` and
 * `warning` in the 16 terminal colours, so it follows the terminal's palette and reads on dark
 * and light backgrounds alike.
 */
final class Stylesheet
{
    private const ATTRS = ['bold' => Style::BOLD, 'dim' => Style::DIM, 'italic' => Style::ITALIC,
        'underline' => Style::UNDERLINE, 'inverse' => Style::INVERSE, 'strike' => Style::STRIKE];
    private const PROPERTIES = ['color', 'background', 'bold', 'dim', 'italic', 'underline', 'inverse', 'strike',
        'border', 'title-align', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'align', 'wrap',
        'width', 'height', 'max-width', 'max-height', 'flex-grow', 'flex-shrink', 'display',
        'gap', 'flex-direction', 'align-items', 'justify-content'];
    private const SIDES = ['top', 'right', 'bottom', 'left'];
    /** The variants of utility classes, and the state each one asks for. */
    private const VARIANTS = ['focus' => 'focused', 'hover' => 'hover', 'disabled' => 'disabled', 'selected' => 'selected',
        'sm' => 'sm', 'md' => 'md', 'lg' => 'lg'];

    /** @var list<array{array<string, mixed>, list<array{list<string>, ?string, list<string>, list<string>, ?string, list<string>}>, array{int,int,int}, int, string}> rules: properties, selectors, specificity, order, the selector as written */
    private array $rules = [];
    /** @var array<string, mixed> */
    private array $vars;
    private static ?Stylesheet $default = null;
    /** How many answers of compute() are kept before the memo starts again. */
    private const MEMO = 20000;
    /** @var array<string, array{ComputedStyle, ?ComputedStyle}> compute()'s answers by question, with the parent they inherit from */
    private array $memo = [];
    /** @var array<string, array{list<string>, array<string, mixed>}|false> utility classes as [states, properties], false for none */
    private static array $utilities = [];

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
            'Tree::node:current' => ['inverse' => true],
            'Tree::node:selected' => ['color' => '$accent', 'bold' => true],
            'Tree::node:failed' => ['color' => '$error'],
            'Tree::marker' => ['color' => '$accent'],
            'Tree::guide' => ['color' => '$muted'],
            'Select:disabled' => ['dim' => true],
            'Select::filter' => ['color' => '$accent'],
            'Dialog' => ['border' => 'round $accent', 'padding' => '0 1', 'align' => 'center'],
            'Dialog::title' => ['bold' => true],
            'Button' => ['color' => '$accent'],
            'Button:focused' => ['inverse' => true, 'bold' => true],
            'Button:pressed' => ['inverse' => true, 'bold' => false],
            'Button:disabled' => ['dim' => true, 'color' => '$muted'],
            'Checkbox:focused' => ['bold' => true],
            'Checkbox::box:checked' => ['color' => '$accent'],
            'Checkbox:disabled' => ['dim' => true],
            'ProgressBar::bar' => ['color' => '$accent'],
            'Spinner::sign' => ['color' => '$accent'],
            'Form::error' => ['color' => '$error'],
            '*:invalid' => ['color' => '$error'],
            '*::scrollbar' => ['color' => '$muted'],
            '*::scrollbar:thumb' => ['color' => '$accent'],
            'Tabs::tab' => ['color' => '$muted'],
            'Tabs::tab:current' => ['color' => '$accent', 'bold' => true, 'underline' => true],
            'Tabs::tab:current:focused' => ['inverse' => true, 'underline' => false],
            'Tabs::tab:hover' => ['color' => '$accent'],
            'Tabs::hint' => ['dim' => true],
            'Tabs::more' => ['color' => '$accent'],
            'Markdown::h1' => ['bold' => true, 'underline' => true, 'color' => '$accent'],
            'Markdown::h2' => ['bold' => true, 'color' => '$accent'],
            'Markdown::h3' => ['bold' => true],
            'Markdown::h4' => ['bold' => true, 'italic' => true],
            'Markdown::h5' => ['italic' => true],
            'Markdown::h6' => ['italic' => true, 'dim' => true],
            'Markdown::strong' => ['bold' => true],
            'Markdown::emphasis' => ['italic' => true],
            'Markdown::strike' => ['strike' => true],
            'Markdown::code' => ['color' => '$warning'],
            'Markdown::codeblock' => ['background' => '$panel'],
            'Markdown::keyword' => ['color' => 'magenta'],
            'Markdown::string' => ['color' => '$success'],
            'Markdown::comment' => ['color' => '$muted', 'italic' => true],
            'Markdown::number' => ['color' => '$warning'],
            'Markdown::variable' => ['color' => '$accent'],
            'Markdown::key' => ['color' => '$accent'],
            'Markdown::link' => ['underline' => true, 'color' => '$accent'],
            'Markdown::url' => ['dim' => true, 'color' => '$muted'],
            'Markdown::quote' => ['color' => '$muted'],
            'Markdown::bullet' => ['color' => '$accent'],
            'Markdown::rule' => ['color' => '$muted'],
            'Markdown::th' => ['bold' => true],
            'Markdown::border' => ['color' => '$muted'],
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
        $copy->memo = [];
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
     * The width class of a screen $cols wide, as the states `sm`, `md` and `lg` (and the
     * variants `sm:`, `md:`, `lg:`) name it: under 60 columns, 60 to 99, 100 and more.
     */
    public static function widthClass(int $cols): string
    {
        return $cols < 60 ? 'sm' : ($cols < 100 ? 'md' : 'lg');
    }

    /**
     * The style of $element in $states, inheriting colours and attributes from $parent; or of
     * its $part in $partStates. Widgets use `Component::style()`, which fills in the element's
     * states; this is for code that draws elements of its own.
     *
     * **Parts that apply together** (a `strong` inside an `h2`, a `link` inside a `quote`, the
     * marker of a tree's row inside the row): give $part as a list, outermost first
     * (`['quote', 'link']`, `['node', 'marker']`). Each part inherits from the one around it as
     * an element inherits from its parent, and the first from the element itself, so the
     * attributes add up and the innermost colour that is set wins; $partStates apply to every
     * part of the list (the marker of the current row is in the state `current`, as the row
     * is). So a marker without a colour of its own keeps the highlighted row one bar, and one
     * with a colour shows it on the row's background. This is the one merge the library uses;
     * a widget that draws several parts on a row asks for each this way.
     *
     * @param string|list<string>|null $part
     * @param list<string> $states     the element's states: `focused`, `disabled`, `hover`, its
     *                                 own, and the width class (`sm`, `md`, `lg`)
     * @param list<string> $partStates the part's states (each widget lists them)
     */
    public function compute(Element $element, array $states = [], ?ComputedStyle $parent = null, string|array|null $part = null, array $partStates = []): ComputedStyle
    {
        if ($element->attribute('disabled') && !\in_array('disabled', $states, true)) {
            $states[] = 'disabled';
        }
        // the same question gives the same answer: computed once per selector match, not per
        // element per frame (the entry holds the parent, so its object id stays its own)
        $inline = $element->attribute('style');
        $key = $element::class . '#' . $element->attribute('id') . '.' . implode('.', $element->attribute('class'))
            . ':' . implode(':', $states) . '::' . (\is_array($part) ? implode('>', $part) : $part)
            . ':' . implode(':', $partStates) . '|' . ($inline === [] ? '' : serialize($inline))
            . '|' . ($parent === null ? '' : spl_object_id($parent));
        if (isset($this->memo[$key])) {
            return $this->memo[$key][0];
        }
        if (\count($this->memo) >= self::MEMO) {
            $this->memo = [];
        }
        $style = $this->computeNow($element, $states, $parent, $part, $partStates);
        $this->memo[$key] = [$style, $parent];
        return $style;
    }

    /**
     * What the stylesheet does with $element in $states, as text for a programmer: the rules
     * that match it (with their specificity), each of its classes (the properties a utility
     * class set, whether its variant is in force, or that it is not a utility and only selects
     * rules), its inline style, and the style that results. For finding out why something
     * looks as it does:
     *
     *     echo $sheet->explain(new Text('x', class: 'text-sky-400 md:bold title'), ['md']);
     *
     * @param list<string> $states as for `compute()`; the width class too (`'md'`) for `sm:`/`md:`/`lg:`
     */
    public function explain(Element $element, array $states = []): string
    {
        if ($element->attribute('disabled') && !\in_array('disabled', $states, true)) {
            $states[] = 'disabled';
        }
        $id = $element->attribute('id');
        $classes = $element->attribute('class');
        $out = [($element->types()[0] ?? $element::class) . ($id === null ? '' : " #$id")
            . ($classes === [] ? '' : ' .' . implode(' .', $classes)) . ($states === [] ? '' : ' (states: ' . implode(', ', $states) . ')')];
        foreach ($this->matched($element, $states, null, []) as [$spec, , $props, $selector]) {
            $out[] = "  rule '$selector' (" . implode(',', $spec) . '): ' . self::describe($props);
        }
        foreach ($classes as $class) {
            $u = self::utility($class);
            if ($u === false) {
                $out[] = "  class '$class': not a utility (selects rules)";
                continue;
            }
            [$need, $props] = $u;
            $missing = array_diff($need, $states);
            $out[] = "  class '$class': " . self::describe($props) . ($need === [] ? '' : ($missing === [] ? ' (in force)' : ' (not in force: needs ' . implode(', ', $missing) . ')'));
        }
        if ($element->attribute('style') !== []) {
            $out[] = '  style: ' . self::describe($element->attribute('style'));
        }
        $s = $this->compute($element, $states);
        $out[] = '  = ' . ($s->text->__toString() === '' ? 'plain' : (string) $s->text)
            . ($s->border === false ? '' : ", border $s->border") . ($s->padding === [0, 0, 0, 0] ? '' : ', padding ' . implode(' ', $s->padding))
            . ($s->margin === [0, 0, 0, 0] ? '' : ', margin ' . implode(' ', $s->margin))
            . ($s->width === null ? '' : ", width $s->width") . ($s->height === null ? '' : ", height $s->height")
            . ($s->grow === null ? '' : ", flex-grow $s->grow") . ($s->hidden ? ', display none' : '')
            . ($s->direction === null ? '' : ", flex-direction $s->direction");
        return implode("\n", $out);
    }

    /** @param array<string, mixed> $props */
    private static function describe(array $props): string
    {
        $parts = [];
        foreach ($props as $name => $value) {
            $parts[] = $name . ': ' . (\is_bool($value) ? ($value ? 'true' : 'false') : (\is_array($value) ? implode(' ', $value) : (string) $value));
        }
        return implode(', ', $parts);
    }

    /**
     * @param string|list<string>|null $part
     * @param list<string> $states
     * @param list<string> $partStates
     */
    private function computeNow(Element $element, array $states, ?ComputedStyle $parent, string|array|null $part, array $partStates): ComputedStyle
    {
        if (\is_array($part)) {
            $style = $this->compute($element, $states, $parent);
            foreach ($part as $name) {
                [$set, $hover] = $this->matching($element, $states, $name, $partStates);
                $style = $this->resolve($set, $style, $hover || $style->hoverAware);
            }
            return $style;
        }
        [$set, $hoverAware] = $this->matching($element, $states, $part, $partStates);
        if ($part === null) {
            // the utility classes in the order written, then the inline style
            foreach ($element->attribute('class') as $class) {
                $u = self::utility($class);
                if ($u === false) {
                    continue;
                }
                $missing = array_diff($u[0], $states);
                if ($missing === []) {
                    $set = self::merge($set, $u[1]);
                } elseif ($missing === ['hover']) {
                    $hoverAware = true;
                }
            }
            $set = self::merge($set, self::check($element->attribute('style')));
        } else {
            // a part inherits from its element
            $parent = $this->compute($element, $states, $parent);
            $hoverAware = $hoverAware || $parent->hoverAware;
        }
        return $this->resolve($set, $parent, $hoverAware);
    }

    /**
     * The properties the rules give $element (or its $part), merged by specificity, and whether
     * a `:hover` rule was considered.
     *
     * @param list<string> $states
     * @param list<string> $partStates
     * @return array{array<string, mixed>, bool}
     */
    private function matching(Element $element, array $states, ?string $part, array $partStates): array
    {
        $hoverAware = false;
        $set = [];
        foreach ($this->matched($element, $states, $part, $partStates, $hoverAware) as [, , $props]) {
            $set = self::merge($set, $props);
        }
        return [$set, $hoverAware];
    }

    /**
     * The rules that match, in the order they apply, as [specificity, order, properties,
     * selector as written]; $hoverAware is set when a rule missed only `:hover`.
     *
     * @param list<string> $states
     * @param list<string> $partStates
     * @return list<array{array{int,int,int}, int, array<string, mixed>, string}>
     */
    private function matched(Element $element, array $states, ?string $part, array $partStates, bool &$hoverAware = false): array
    {
        $types = $element->types();
        $id = $element->attribute('id');
        $classes = $element->attribute('class');
        $matched = [];
        foreach ($this->rules as [$props, $selectors, , $order, $written]) {
            foreach ($selectors as $sel) {
                $m = self::matches($sel, $types, $id, $classes, $states, $part, $partStates);
                $hoverAware = $hoverAware || $m === 'hover' || ($m === true && \in_array('hover', [...$sel[3], ...$sel[5]], true));
                if ($m === true) {
                    $matched[] = [$sel[6], $order, $props, $written];
                    break;
                }
            }
        }
        usort($matched, fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $matched;
    }

    /** @param array<string, array<string, mixed>> $rules */
    private function add(array $rules): void
    {
        foreach ($rules as $selector => $props) {
            $selectors = [];
            foreach (explode(',', (string) $selector) as $one) {
                $selectors[] = self::parseSelector(trim($one));
            }
            $this->rules[] = [self::check($props), $selectors, [0, 0, 0], \count($this->rules), (string) $selector];
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
     * True when the selector matches; 'hover' when only the hover state is missing (of the
     * element or of the part).
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
        // (a rule for the element or one of its parts under the mouse: the widget follows the mouse)
        return $missing === ['hover'] ? 'hover' : false;
    }

    /**
     * A utility class as [the states its variants need, the properties it sets], or false when
     * $class is not one (see the class description).
     *
     * @return array{list<string>, array<string, mixed>}|false
     */
    private static function utility(string $class): array|false
    {
        if (isset(self::$utilities[$class])) {
            return self::$utilities[$class];
        }
        if (\count(self::$utilities) > 4096) {
            self::$utilities = [];
        }
        $states = [];
        $base = $class;
        // variants: the prefixes up to the last ':' outside brackets
        while (preg_match('/^([a-z]+):(.+)$/', $base, $m) && isset(self::VARIANTS[$m[1]])) {
            $states[] = self::VARIANTS[$m[1]];
            $base = $m[2];
        }
        $props = self::utilityProperties($base);
        return self::$utilities[$class] = $props === null ? false : [$states, $props];
    }

    /** @return array<string, mixed>|null the properties of a utility class without variants, null when it is none */
    private static function utilityProperties(string $u): ?array
    {
        static $plain = [
            'bold' => ['bold' => true], 'dim' => ['dim' => true], 'italic' => ['italic' => true],
            'underline' => ['underline' => true], 'strike' => ['strike' => true], 'inverse' => ['inverse' => true],
            'text-left' => ['align' => 'left'], 'text-center' => ['align' => 'center'], 'text-right' => ['align' => 'right'],
            'truncate' => ['wrap' => false], 'nowrap' => ['wrap' => false], 'wrap' => ['wrap' => true],
            'border' => ['border' => true], 'border-none' => ['border' => 'none'],
            'title-left' => ['title-align' => 'left'], 'title-center' => ['title-align' => 'center'], 'title-right' => ['title-align' => 'right'],
            'grow' => ['flex-grow' => 1], 'shrink' => ['flex-shrink' => 1], 'shrink-0' => ['flex-shrink' => 0],
            'hidden' => ['display' => 'none'], 'block' => ['display' => 'block'],
            'flex-row' => ['flex-direction' => 'row'], 'flex-col' => ['flex-direction' => 'column'],
            'w-full' => ['width' => '100%'], 'h-full' => ['height' => '100%'], 'w-auto' => ['width' => 'auto'], 'h-auto' => ['height' => 'auto'],
        ];
        if (isset($plain[$u])) {
            return $plain[$u];
        }
        if (preg_match('/^(p|m)([xytrbl]?)-(\d+)$/', $u, $m)) {
            $name = $m[1] === 'p' ? 'padding' : 'margin';
            $n = (int) $m[3];
            return match ($m[2]) {
                '' => [$name => $n],
                'x' => ["$name-left" => $n, "$name-right" => $n],
                'y' => ["$name-top" => $n, "$name-bottom" => $n],
                't' => ["$name-top" => $n], 'r' => ["$name-right" => $n], 'b' => ["$name-bottom" => $n], 'l' => ["$name-left" => $n],
            };
        }
        if (preg_match('/^gap-(\d+)$/', $u, $m)) {
            return ['gap' => (int) $m[1]];
        }
        if (preg_match('/^(max-)?([wh])-(\d+)(?:\/(\d+))?$/', $u, $m)) {
            $name = ($m[1] === '' ? '' : 'max-') . ($m[2] === 'w' ? 'width' : 'height');
            if (isset($m[4])) {
                if ($m[1] !== '' || (int) $m[4] === 0) {
                    return null;
                }
                $percent = round(100 * (int) $m[3] / (int) $m[4], 2);
                return [$name => ($percent == (int) $percent ? (int) $percent : $percent) . '%'];
            }
            return [$name => (int) $m[3]];
        }
        if (preg_match('/^grow-(\d+(?:\.\d+)?)$/', $u, $m)) {
            return ['flex-grow' => (float) $m[1]];
        }
        if (preg_match('/^items-(start|center|end|stretch)$/', $u, $m)) {
            return ['align-items' => $m[1]];
        }
        if (preg_match('/^justify-(start|center|end|between)$/', $u, $m)) {
            return ['justify-content' => $m[1]];
        }
        if (preg_match('/^border-(single|round|double|heavy|ascii)$/', $u, $m)) {
            return ['border' => $m[1]];
        }
        if (preg_match('/^(text|bg|border)-(.+)$/', $u, $m)) {
            $color = $m[2];
            try {
                Color::parse($color);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $color = str_starts_with($color, '[') ? substr($color, 1, -1) : $color;
            return match ($m[1]) {
                'text' => ['color' => $color],
                'bg' => ['background' => $color],
                'border' => ['border' => ['color' => $color]],
            };
        }
        return null;
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
     * $props over $set; a border is merged by its parts (kind, colour), padding and margin by
     * their sides.
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
                if ($kind === true) {
                    $kind = \is_string($old[0]) ? $old[0] : 'single';
                }
                $set['border'] = [$kind ?? $old[0], $color ?? $old[1]];
            } elseif ($name === 'padding' || $name === 'margin') {
                // (each side keeps the value, read when resolved: it may be a variable)
                foreach (self::SIDES as $i => $side) {
                    $set["$name-$side"] = ['side' => $i, 'of' => $value];
                }
            } else {
                $set[$name] = $value;
            }
        }
        return $set;
    }

    /** @return array{string|bool|null, mixed} the kind of line (true: on, of the kind set before) and the colour, null where not given */
    private static function parseBorder(mixed $value): array
    {
        if ($value === false || $value === 'none') {
            return [false, null];
        }
        if ($value === true) {
            return [true, null];
        }
        if (\is_array($value)) {
            return [null, $value['color'] ?? null];
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
        $sides = function (string $name) use ($set): array {
            $out = [];
            foreach (self::SIDES as $side) {
                $v = $set["$name-$side"] ?? 0;
                $out[] = \is_array($v) && isset($v['side'])
                    ? self::padding($this->value($v['of']))[$v['side']] : max(0, (int) $this->value($v));
            }
            return $out;
        };
        $grow = isset($set['flex-grow']) ? max(0.0, (float) $this->value($set['flex-grow'])) : null;
        $direction = isset($set['flex-direction']) ? (string) $this->value($set['flex-direction']) : null;
        return new ComputedStyle(
            $text,
            $kind,
            $borderStyle,
            $sides('padding'),
            (string) $this->value($set['align'] ?? 'left'),
            isset($set['width']) ? $this->value($set['width']) : null,
            isset($set['height']) ? $this->value($set['height']) : null,
            $hoverAware,
            isset($set['max-width']) ? max(0, (int) $this->value($set['max-width'])) : null,
            isset($set['max-height']) ? max(0, (int) $this->value($set['max-height'])) : null,
            $sides('margin'),
            isset($set['gap']) ? max(0, (int) $this->value($set['gap'])) : null,
            (string) $this->value($set['title-align'] ?? 'left'),
            isset($set['wrap']) ? (bool) $this->value($set['wrap']) : null,
            $grow,
            isset($set['flex-shrink']) ? max(0.0, (float) $this->value($set['flex-shrink'])) : null,
            (string) $this->value($set['align-items'] ?? 'stretch'),
            (string) $this->value($set['justify-content'] ?? 'start'),
            ($this->value($set['display'] ?? 'block')) === 'none',
            $direction === null ? null : ($direction === 'row' ? 'row' : 'column'),
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

    /** A colour value as a `Color` int (see `Color::parse()`). */
    private function color(mixed $value): int
    {
        $value = $this->value($value);
        return \is_int($value) ? $value : Color::parse((string) $value);
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
