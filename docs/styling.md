# Styling: utility classes, the stylesheet, markup, colours

Three ways to give the screen its look, which compose:

- **Utility classes** in `class:`, with the Tailwind vocabulary: `new Box($log, 'log', class:
  'border-double border-sky-500 p-1 grow')`. Most screens need nothing else.
- **A stylesheet** of rules, for a theme or for what a program styles in many places:
  `'Text.title' => ['bold' => true, 'color' => '$accent']`.
- **Markup in text**, as symfony/console spells it: `new Text('<b>3</b> files, <fg=red>1 failed</>')`.

Every utility class is a stylesheet property, so a theme sets the same things a class does, and
markup resolves through the same stylesheet; the terminal device draws whatever colours result in
the colours the terminal has.

```php
use phasync\Tui\Tui;
use phasync\Tui\Element\{Box, Column, Row, Text};

Tui::run(fn() => new Column([
    new Row([
        new Text('<b>deploy</b> v1.4', class: 'grow'),
        new Text('<fg=green>●</> online', class: 'text-right'),
    ], class: 'px-1 bg-slate-800'),
    new Row([
        new Box(new Text('3 jobs running'), 'jobs', class: 'grow title-center'),
        new Box(new Text('<dim>no errors</dim>'), 'errors', class: 'grow border-emerald-500'),
    ], class: 'gap-1 flex-col lg:flex-row'),
], class: 'p-1'));
```

## Utility classes

`class:` takes utility classes next to the program's own classes (`class: 'title bold
text-sky-400'`). A class that is not a utility is the program's own: it selects the
stylesheet's rules (`.title`) and is otherwise ignored, never an error.

`{n}` is a number of cells; `{color}` is a colour as below.

| Class | Property it sets | What it does |
|---|---|---|
| `p-{n}` | `padding` | blank cells inside the border (or the edge), on all sides |
| `px-{n}`, `py-{n}` | `padding-left`/`-right`, `padding-top`/`-bottom` | left and right; top and bottom |
| `pt-{n}`, `pr-{n}`, `pb-{n}`, `pl-{n}` | `padding-top` ... `padding-left` | one side |
| `m-{n}`, `mx-`, `my-`, `mt-`, `mr-`, `mb-`, `ml-{n}` | `margin`, `margin-top` ... | blank cells around the element, inside the room its parent gives it |
| `gap-{n}` | `gap` | blank cells between the children of a `Column` or `Row` |
| `border` | `border: true` | a border, of the kind the stylesheet sets for it (a `Box`: round), else single lines |
| `border-single`, `border-round`, `border-double`, `border-heavy`, `border-ascii` | `border` | a border of that line |
| `border-none` | `border: none` | no border |
| `border-{color}` | `border` (its colour) | the border's colour (a border of single lines if it has none) |
| `title-left`, `title-center`, `title-right` | `title-align` | where a border's title goes |
| `text-{color}` | `color` | the text colour |
| `bg-{color}` | `background` | the background (the whole rectangle of the element) |
| `bold`, `dim`, `italic`, `underline`, `strike`, `inverse` | the attribute | |
| `text-left`, `text-center`, `text-right` | `align` | where text goes in its box |
| `truncate`, `nowrap` | `wrap: false` | each line is cut with "…" instead of wrapping |
| `wrap` | `wrap: true` | lines wrap between words (the default) |
| `w-{n}`, `h-{n}` | `width`, `height` | that many cells (in a `Row` the width counts, in a `Column` the height) |
| `w-1/2`, `w-2/3`, any fraction (and `h-`) | `width: '50%'` | a share of the parent |
| `w-full`, `h-full` | `width: '100%'` | all of it |
| `w-auto`, `h-auto` | `width: 'auto'` | as large as its content (the default) |
| `max-w-{n}`, `max-h-{n}` | `max-width`, `max-height` | the most an `'auto'` size takes |
| `grow`, `grow-{n}` | `flex-grow: 1`, `n` | takes a share (`1fr`, `nfr`) of the room its `Column` or `Row` has left, at least what it needs |
| `shrink-0` | `flex-shrink: 0` | keeps what its content needs when the siblings must be squeezed |
| `shrink` | `flex-shrink: 1` | may be squeezed (the default) |
| `items-stretch`, `items-start`, `items-center`, `items-end` | `align-items` | on a container: the children as wide as a `Column` (as high as a `Row`), or as large as their content at the start, middle or end |
| `justify-start`, `justify-center`, `justify-end`, `justify-between` | `justify-content` | on a container: where the children go along it when they leave room; `between` spreads them out |
| `hidden` | `display: none` | not shown, as if it were not in the tree (a component in it keeps running) |
| `block` | `display: block` | shown (to undo `hidden` in a variant: `hidden lg:block`) |
| `flex-row`, `flex-col` | `flex-direction` | on a `Column` or `Row`: its children side by side, or on top of each other |

**Colours.** A `{color}` is a Tailwind family and shade (`sky-400`, `slate-800`, `emerald-500`:
the families slate, gray, zinc, neutral, stone, red, orange, amber, yellow, lime, green,
emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink, rose; the shades 50, 100,
200, ... 900, 950), one of the 16 terminal colours (`red`, `bright-blue`, `gray`, `white`), a
palette index (`208`), or a hex value in brackets (`[#ff5500]`). The Tailwind colours are
truecolour; the 16 names follow the user's terminal theme.

**Variants** are prefixes that apply a class only sometimes:

| Variant | Applies when |
|---|---|
| `focus:` | the element is on the focus path (`:focused`): `focus:bg-sky-800` |
| `hover:` | the mouse is over it (components; it then follows the mouse) |
| `disabled:` | it was made with `disabled: true` |
| `selected:` | it is in the state `selected` (a component's `setStyleState('selected')`) |
| `sm:` | the screen (the root surface) is under 60 columns wide |
| `md:` | the screen is 60 to 99 columns wide |
| `lg:` | the screen is 100 columns or wider |

Variants combine (`lg:focus:bold`). The width variants name one range each, so a layout that
puts boxes side by side on a wide terminal and stacks them otherwise is `class: 'flex-col
lg:flex-row'`; at a resize across a breakpoint the screen is laid out again by itself.

**Cascade.** The stylesheet's rules come first (by specificity), then the utility classes in the
order they are written (a later one wins), then the element's inline `style:`.
`Stylesheet::explain($element, $states)` tells a programmer what resolved and why:

```php
echo Stylesheet::default()->explain(new Text('x', class: 'title bold md:italic focus:bg-sky-800'), ['md']);
// Text .title .bold .md:italic .focus:bg-sky-800 (states: md)
//   rule 'Text.title' (0,1,1): bold: true
//   class 'title': not a utility (selects rules)
//   class 'bold': bold: true
//   class 'md:italic': italic: true (in force)
//   class 'focus:bg-sky-800': background: sky-800 (not in force: needs focused)
//   = bold italic
```

## The stylesheet

A `Stylesheet` is a list of rules, a selector and properties, set on the root component (it
applies to the subtree; the nearest one wins) and built on the default theme:

```php
$app->setStylesheet(Stylesheet::default()->with([
    'Text.title' => ['bold' => true, 'color' => '$accent'],
    'Box:focused' => ['border' => 'double $accent'],
    '#name' => ['width' => 30],
    'Select::row:current' => ['background' => '$accent', 'color' => 'black'],
    'Row:sm' => ['flex-direction' => 'column'],        // the width classes are states too
], vars: ['accent' => 'magenta']));
```

**Selectors**: a type (the element's or component's class name; parents' names match too) or
`*`, then `#id`, `.class`, states (`:focused`, `:disabled`, `:hover`, `:selected` and a
component's own, `:sm`, `:md`, `:lg`), and widget parts `::part` with their own states. There
are no combinators: colours and attributes inherit down the tree instead.

**Properties**:

| Property | Values |
|---|---|
| `color`, `background` | a colour (as above, without brackets: `'#ff5500'`), `default`, or a variable `'$accent'` |
| `bold`, `dim`, `italic`, `underline`, `inverse`, `strike` | true or false |
| `border` | `'none'`, true, a kind of line (`single`, `round`, `double`, `heavy`, `ascii`) and/or a colour: `'round $accent'` |
| `title-align` | `left`, `center`, `right` |
| `padding`, `margin` | `1`, `'0 1'` (top/bottom, left/right), `'0 1 0 1'` (top, right, bottom, left); or one side: `padding-top`, `margin-left`, ... |
| `align` | `left`, `center`, `right`: where text goes; where a layer of `Layers` goes |
| `wrap` | true (wrap between words) or false (cut each line with "…") |
| `width`, `height` | cells, `'25%'`, `'1fr'`, `'auto'` |
| `max-width`, `max-height` | cells |
| `flex-grow`, `flex-shrink` | a number |
| `display` | `none` or `block` |
| `gap`, `flex-direction`, `align-items`, `justify-content` | on a `Column` or `Row` (see the table of classes) |

Colours and text attributes inherit (a `Box` with `color: green` makes its `Text` green unless
a rule for the `Text` says otherwise); the others apply to the element itself. The default
theme uses the 16 terminal colours and the variables `accent`, `muted`, `panel`, `error`,
`success`, `warning`, so it follows the terminal's palette on dark and light backgrounds.

A component that draws itself asks for its look with `$this->style()` and `$this->style('part',
'state')` (see [components.md](components.md#styles)).

## Markup in text

`Text`, `Span`, `Box` titles, `Button` and `Checkbox` labels, `Table` and `Select` cells, `Help`
(the descriptions of bindings), `Log` lines, `ProgressBar` labels and the text of `Markdown`
take the markup PHP programmers know from symfony/console:

| Tag | Does |
|---|---|
| `<b>` `<bold>`, `<i>` `<italic>`, `<u>` `<underline>`, `<s>` `<strike>`, `<dim>` | the attribute |
| `<fg=red>`, `<fg=#ff5500>`, `<fg=sky-400>` | the text colour (any colour `Color::parse()` reads) |
| `<bg=blue>` | the background |
| `<options=bold,underscore>` | attributes: bold, underscore, underline, italic, dim, reverse, strike |
| `<href=https://example.com>` | a hyperlink (OSC 8, where the terminal shows them) |
| `<fg=black;bg=yellow;options=bold>` | several at once, separated by `;` |
| `<span class="text-sky-400 bold">` | utility classes, and your stylesheet's `Span.class` rules |
| `<info>`, `<comment>`, `<question>`, `<error>` | symfony/console's own: green, yellow, black on cyan, white on red |

A tag ends with `</>` or with its own name (`</b>`, `</fg>`, `</span>`), which also ends the
tags opened inside it. Tags nest: an inner tag inherits from the outer ones, so attributes add up
and the innermost colour wins (`<fg=red>a<b>b</b></>`: `b` is red and bold).

- `\<` is a literal `<`; `Markup::escape($name)` makes a string from outside (a file name, a
  user's input) safe to put into markup, and `Text::raw($html)` shows a text with no markup at
  all.
- **Malformed markup is shown as written**, never an error: an unknown tag (`<foo>`), a colour
  that is none (`<fg=nocolour>`), a tag never closed and a closing tag with nothing open stay
  text. So `1 < 2` needs no escaping.
- **Width is the visible text's.** Markup becomes escape sequences before anything measures the
  text, so wrapping, truncating ("…") and `'auto'` sizes see no tags; a marked-up title in a
  narrow `Box` is cut by its visible text. Each string is parsed once and kept.
- In `Markdown`, inline tags style the text outside code; in a code span or block they stay
  literal, and `&lt;` and `\<` are a plain `<`.
- In a `Log`, markup is read per line (a tag may open in one `append()` and close in the next on
  the same line); `lines()` keeps the text as it was given.
- A widget of yours shows a label with markup through `$this->markup($label, 'part', ...$states)`
  (the label's escape sequences in that part's style), and code of its own through
  `Markup::toAnsi($text, $style)`; `Markup::plain($text)` is the visible text.

## Colours and the terminal

Cells keep the colours the program chose; the terminal device draws them in the colours the
terminal has, in one place: on a 256-colour terminal a truecolour cell (a Tailwind colour, a hex
value) becomes the nearest colour of the 256-colour cube and grays, on a 16-colour terminal the
nearest of the 16 (`Color::nearest()`, a weighted distance of red, green and blue). `Tty` knows
the depth from `COLORTERM` and `TERM` (`Tty::colorsFromEnvironment()`); `$device->colors()`
tells it and `$device->setColors(256)` changes it for a terminal that says it wrong;
`new FakeTerminal(80, 24, colors: 16)` tests what a 16-colour terminal shows.
