# Layout: arranging a host's windows

A `Layout` decides where the hosted surfaces of a host go and how large they are. Set one with
`$host->setLayout($layout)`: it arranges the windows at once and again whenever the host is
resized, and it owns the host's windows (surfaces it does not place are unhosted). Every surface
a layout places is kept the size of its window (`Viewport::setFit()`), so a program in a pty is
resized, and told (SIGWINCH), whenever the layout changes.

Sizes are written the same way everywhere:

- an `int` is a number of cells: `30`
- `'25%'` is a share of the host
- `'2fr'` is a weight in the space that is left after cells and percentages (as in CSS grids);
  the parts always add up exactly

Layouts nest: add a layout where a surface would go.

```php
$root->setLayout(Split::horizontal(separators: true)
    ->add($files, 30)
    ->add(Split::vertical()->add($editor)->add($shell, '30%')));
```

The outer layout shows the inner one in a surface of its own, which it keeps across arranges,
even when a component's `render()` builds the layouts anew each time; so the windows inside keep
their state, and focus (`focus()`, `focusNext()`, `focusToward()`, click-to-focus set on the
root) goes through it like through any host. `$inner->host()` is that surface (to give the group
a border: `$inner->host()->viewport()->setBorder(true)`). Giving a hosted surface a layout of its
own (`$right->setLayout(...)`) works too, when you want to hold that surface yourself.

A window gets a border and a title right where it is added: `->add($left, 30, title: 'files')`
frames it with the title in the top border, `border: true` frames it without a title (the size
is the outer size, border included). Or set them on the viewport yourself:
`$root->host($left)->setBorder(true)->setTitle('files')` (`host()` returns the existing viewport
of a hosted surface, so this works before or after the layout is set); an item added without
`border`/`title` leaves what you set on its viewport alone.

## Split

Windows side by side (`horizontal()`, left to right) or on top of each other (`vertical()`, top
to bottom), optionally with a one-cell separator line between them.

| Method | What it does |
|---|---|
| `Split::horizontal(bool $separators = false, ?Style $style = null)` | Side by side. |
| `Split::vertical(bool $separators = false, ?Style $style = null)` | Stacked. |
| `new Split(string $direction = Split::HORIZONTAL, bool $separators = false, ?Style $style = null)` | The same, with the direction as a constant. |
| `add(Surface\|Layout $item, int\|string $size = '1fr', ?bool $border = null, ?string $title = null)` | The next window: a surface, or a layout to nest. `$title` frames it with a border and that title, `border: true` with a border only. Adding an item already in the split changes its size and frame. Chainable. |
| `remove(Surface\|Layout $item)` | Take it out; the others share its space. |
| `items(): list<Surface\|Layout>` | In order. |
| `host(): ?Surface` | The surface it arranges (for a nested split, the one made for it); null until arranged. |

A split that is in use re-arranges at once when windows are added or removed.

## Stack

Windows layered on top of each other: the first added at the bottom, each later one covering
it. For overlays, pop-ups and modal dialogs.

```php
$stack = (new Stack())->add($main);                 // fills the host
$root->setLayout($stack);
$stack->add($dialog, width: 40, height: 8, title: 'Save changes?');   // centered on top, framed
$dialog->focus();                                   // keys go to the dialog
// ...
$stack->remove($dialog);                            // what it covered shows again, and the
                                                    // window focused before gets the keys back
```

| Method | What it does |
|---|---|
| `add(Surface\|Layout $item, int\|string $width = '100%', int\|string $height = '100%', string $align = 'center', ?int $x = null, ?int $y = null, ?bool $border = null, ?string $title = null)` | Put a window on top, a surface or a layout to nest (a dialog of several windows), or change how an item already in the stack is placed. Sizes in cells or percentages, border included. `$align`: `center`, `top`, `bottom`, `left`, `right`, `top-left`, `top-right`, `bottom-left`, `bottom-right`; `$x`/`$y` override it. `$title` frames the window with a border and that title (a dialog's usual look), `border: true` with a border only. |
| `remove(Surface\|Layout $item)` | Take it off the stack. |
| `items(): list<Surface\|Layout>` | Bottom first; this is the z-order. |
| `host(): ?Surface` | The surface it arranges; null until arranged. |

## Your own layout

Implement `Layout::arrange(Surface $host): void`: host each surface you place with
`$host->host($surface)`, then set its viewport's position and size (`move()`, `resize()`,
`setFit(true)`). To nest layouts in yours, get the surface for the n-th nested layout with
`$host->nestedSurface($n, $layout)` (the same one on every arrange) and host that. Draw decorations (lines between windows) by writing escape sequences to the
host after placing the windows.

## Placement

Where a floating window goes, next to an anchor: the decision `Component::overlay()` makes
for a dropdown, a completion list or a dialog, as a pure function a widget can use itself.

```php
$rect = Placement::for(Rect $anchor, Rect $root, array $desired, string|Rect $place = 'below'): Rect;
$rect = Placement::for($field->surface->area(), new Rect(0, 0, $cols, $rows), [30, 8], 'below');
```

All rectangles are in the same coordinates (those of `Surface::area()`); `$desired` is
`[cols, rows]`. The result is always inside `$root`, in this order:

| `$place` | Where |
|---|---|
| `'below'`, `'above'` | On that side of the anchor if it fits there; else on the other side if it fits there; else on the side with more room (the preferred one on a tie), **shrunk to that room**; when neither side has any room (the anchor fills the root), over the anchor. Horizontally from the anchor's left edge, shifted left to stay inside, narrowed only when wider than the root. |
| `'center'` | In the middle of the root, shrunk to it when larger. |
| a `Rect` | That rectangle, shrunk to the root's size and shifted inside it. |

Throws `InvalidArgumentException` for another `$place`.
