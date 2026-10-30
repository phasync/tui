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

Layouts nest by giving a hosted surface a layout of its own:

```php
$left = new Surface();
$right = new Surface();
$top = new Surface();
$bottom = new Surface();
$root->setLayout(Split::horizontal(separators: true)->add($left, 30)->add($right));
$right->setLayout(Split::vertical()->add($top)->add($bottom, '30%'));
```

Borders and titles are set on the viewports, not in the layout:
`$root->host($left)->setBorder(true)->setTitle('files')` (`host()` returns the existing viewport
of a hosted surface, so this works before or after the layout is set).

## Split

Windows side by side (`horizontal()`, left to right) or on top of each other (`vertical()`, top
to bottom), optionally with a one-cell separator line between them.

| Method | What it does |
|---|---|
| `Split::horizontal(bool $separators = false, ?Style $style = null)` | Side by side. |
| `Split::vertical(bool $separators = false, ?Style $style = null)` | Stacked. |
| `new Split(string $direction = Split::HORIZONTAL, bool $separators = false, ?Style $style = null)` | The same, with the direction as a constant. |
| `add(Surface $surface, int\|string $size = '1fr')` | The next window; adding a surface already in the split changes its size. Chainable. |
| `remove(Surface $surface)` | Take it out; the others share its space. |
| `surfaces(): list<Surface>` | In order. |

A split that is in use re-arranges at once when windows are added or removed.

## Stack

Windows layered on top of each other: the first added at the bottom, each later one covering
it. For overlays, pop-ups and modal dialogs.

```php
$stack = (new Stack())->add($main);                 // fills the host
$root->setLayout($stack);
$stack->add($dialog, width: 40, height: 8);         // centered on top
$root->host($dialog)->setBorder(true)->setTitle('Save changes?');
$root->focus($dialog);                              // keys go to the dialog
// ...
$stack->remove($dialog);                            // what it covered shows again
```

| Method | What it does |
|---|---|
| `add(Surface $surface, int\|string $width = '100%', int\|string $height = '100%', string $align = 'center', ?int $x = null, ?int $y = null)` | Put a window on top (or change how a surface already in the stack is placed). Sizes in cells or percentages. `$align`: `center`, `top`, `bottom`, `left`, `right`, `top-left`, `top-right`, `bottom-left`, `bottom-right`; `$x`/`$y` override it. |
| `remove(Surface $surface)` | Take it off the stack. |
| `surfaces(): list<Surface>` | Bottom first; this is the z-order. |

## Your own layout

Implement `Layout::arrange(Surface $host): void`: host each surface you place with
`$host->host($surface)`, then set its viewport's position and size (`move()`, `resize()`,
`setFit(true)`). Draw decorations (lines between windows) by writing escape sequences to the
host after placing the windows.
