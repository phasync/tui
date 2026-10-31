# Hosting: surfaces showing surfaces

The library has one structure, all the way up: **a Surface hosts other Surfaces**, each shown
through a `Viewport`. The screen of a program is just a root Surface hosting windows; a window
can host windows of its own, to any depth. A pane of a multiplexer running another multiplexer
is the same structure one level down, and so is a component tree. The real terminal is only a
device attached to the root (see [terminal](terminal.md)).

```php
$root = new Surface(80, 24);
$log = new Surface(200, 50);                       // may be larger than its window
$view = $root->host($log, x: 2, y: 1, width: 40, height: 10);
$view->setBorder(true)->setTitle('log');
$log->write("hello\r\n");
echo $root->text();                                // the log shows in its window, framed
```

## Compositing is blitting

What a host shows (its **frame**) is its own cells with every window it hosts copied over
them, in hosting order (later ones on top), and the windows those surfaces host copied in turn,
to any depth: cells are copied, not written again as escape sequences. Each window is clipped
by its own inner area, by its source's edges and by every window it is inside of; a border and
a title are drawn around it, a selection shows in inverse over all the window shows (the
windows inside it too), and a shadow (`setShadow()`) shades the windows below the one that
casts it. The root's frame is what the terminal device shows; a host lower down has a frame of
its own too, made when it is read.

The host's own cells are never touched by compositing: what its producer writes under a window
stays there, and shows again where the window moves away or is unhosted. Neither are its
cursor, pen, modes or character sets (nothing is written to it).

Compositing is lazy. A change marks the host (and every host above it) as needing to compose,
and they emit `change`; the frame is made when the host's cells, damage or version are read,
when `compose()` is called, or when a terminal device draws a frame. While a hosted program is
in the middle of a synchronized update (mode 2026), its window keeps showing the cells it had
when the update began, for at most half a second, so its redraws never show half done.

**What a frame costs is what changed.** A frame is made again only on the rows something
changed on: the rows of the host's own cells that changed, and the rows of every window that
shows something else than in the last frame, found to any depth (a window whose source
changed is looked into: a cell changed ten levels down is the one row it is on). A window that
moved, changed size, look, title, scroll position or shadow, or that was hosted, unhosted or
raised, changed on all its rows, where it was and where it is. A window that shows the same as
in the last frame is copied from its last drawing. A frame with a few changes costs those rows
whatever the size and depth of the tree; at 160x50, ten levels deep, a frame costs about
0.25 ms for a cell changed at the bottom and about 1 ms with every surface changed.

**One diff, at the end.** Rows of a frame that differ from the previous frame get a new
version (`damagedSince()`), and the terminal device writes only the cells of the root's frame
that look different from what it showed: coalesced into runs of the same style per row, a
blank written without a foreground colour (it shows none). A pane printing continuously costs
its changed cells per frame, whatever the byte volume (a spinner writing 40 kB between two
frames costs a few dozen bytes on the terminal); an idle pane costs nothing; two frames that
look the same cost nothing, however they were made.

Cells can be copied between surfaces by a program as well: `copyArea()` gives an `Area` of a
surface's cells as it shows them (windows included), and `drawArea()` puts one on another
surface as its own cells (see [surface](surface.md#reading)).

## Hosting methods on Surface

| Method | What it does |
|---|---|
| `host(Surface $child, ?int $x = null, ?int $y = null, ?int $width = null, ?int $height = null): Viewport` | Show `$child` and return its viewport. Without geometry it shows at the top-left at its own size. Hosting it again returns the same viewport (moved and resized if geometry is given). Of its windows, the host focuses the one focused most recently (also by a `focus()` made before it was shown), or the first one when none was ever focused (see Focus). Throws if `$child` is this surface or hosts it. |
| `unhost(Surface $child)` | Stop showing it; what was beneath it (the host's own cells, windows below) shows again. |
| `raise(Surface $child)` | Bring its window to the top. |
| `viewport(?Surface $child = null): ?Viewport` | The viewport of a hosted surface, or null. Without an argument: the viewport this surface shows through on its host. |
| `viewports(): list<Viewport>` | All windows, bottom first. |
| `parent(): ?Surface` | The surface hosting this one; null for a root. |
| `root(): Surface` | The top of the tree (this surface when it is not hosted). |
| `area(): Rect` | Where this surface shows on the root: the rectangle its visible part covers, clipped by every window on the way up (empty when out of view). The `area` event (`on('area', fn(Rect $a) => ...)`) tells when it changes. |
| `setLayout(?Layout $layout)`, `layout()` | Let a layout place the windows, now and on every resize (see [layout](layout.md)). |
| `compose()` | Make the frame now, on the rows something changed on (normally automatic: reading the surface does it). |
| `needsCompose(): bool` | True while something the frame shows changed and the frame has not caught up, and while a window holds back a synchronized update (its frame is made again when the hold ends or times out). |
| `activeCursor(): Cursor` | The cursor a terminal showing this surface should display: the focused window's, down the focus path, mapped to this surface (hidden when outside its window); the own `cursor()` when this surface keeps the focus. |

The focused window's title is written up to its host (so the root's title is the title of what
the user is looking at), and bells and clipboard requests (OSC 52) are written up from every
window, to reach the real terminal.

## Input goes down the tree

`dispatch(Event $event): bool` delivers a `Key`, `Mouse`, `Paste` or `Focus`:

1. The surface's input listeners (`on('key')`, `on('mouse')`, `on('paste')`, `on('focus')`) see
   it first; one returning `true` takes it. This is how a program reacts to keys anywhere: a
   prefix key on the root, a key a widget handles itself.
2. If the surface hosts others, keys, pastes and focus reports go to the focused one; a mouse
   event goes to the topmost window under the pointer, in that surface's coordinates (from a
   press to its release, the window that got the press keeps getting the drags, clamped to it).
   Typing or pasting into a window clears its selection and scrolls it back to the end.
3. Where it ends, the surface encodes it as xterm would for the modes its program set
   (application cursor keys, kitty keyboard flags, modifyOtherKeys, mouse mode and encoding,
   bracketed paste, focus reporting) and writes the bytes to its `input`.

A host uses the mouse events a hosted program did not ask for: the **wheel scrolls** the window
(three lines a click, into the scrollback), and a **left drag selects text**, highlighted, which
is copied to the clipboard on release (an OSC 52 written up to the terminal). `dispatch()`
returns false only for a mouse event nothing wanted.

## The tree

The surfaces form a tree: each knows its host (`parent()`), the viewport it shows through
(`viewport()`), its `root()` and where it shows on the root (`area()`, a `Rect` with `x`, `y`,
`width`, `height`, `isEmpty()`, `contains()`, `intersect()`). A surface shown on several hosts
at once (a mirror) belongs to the host that showed it last. A terminal device is attached to a
root; nothing else is special about the root.

## Focus

Focus is a property of the tree. Each host has one focused hosted surface, or keeps the focus
itself. The focus path runs from the root down through each host's focused surface; keys go to
its end. Any kind of host can be in between: plain surfaces, nested layouts, components.

**The focus belongs to the surface, not to the place it is shown.** Every `focus()` is a request
numbered in order, and the surface focused last has the focus wherever it is: after any change
to the tree (hosting, unhosting, a layout set or re-arranged, a component's render that swaps a
plain layout for a `Stack` with a dialog and back, which moves the windows into other nested
surfaces) each host focuses the window whose part of the tree was focused most recently. So:

- a focused surface that moves to another host, or one level deeper into a new nested layout,
  keeps the focus;
- a `focus()` made before a re-arrangement (in a component's `render()`, before the layout it
  returns is set) wins over it;
- when the focused surface is no longer shown (a dialog closes), the focus goes back to the one
  focused before it that is still shown, and to the first window when none was;
- a change takes effect at once for the next key; only the drawing waits for the next frame.

Surfaces that leave the focus path and those that join it each get one `Focus` event; a surface
that stays on it (one that only moved) gets none.

| Method | What it does |
|---|---|
| `focus()` | Give the focus to this surface: every host from the root down focuses the way to it. When it hosts windows, keys go on to the one of them it focuses. Called before the surface is shown (a window a layout is about to place) it takes effect when it is hosted; when several wait, the latest request wins. The surfaces that leaves the focus path and those that join it each get one `Focus` event (a program with focus reporting reads `ESC [O` / `ESC [I`). |
| `takeFocus()` | Focus this surface and keep the keys here: its own windows lose the focus, so its own producer gets them (a program with a pop-up over it), until one of them is focused again. |
| `hasFocus(): bool` | True if this surface is on the focus path from its root. |
| `focused(): ?Surface` | The focused hosted surface, or null. |
| `focusNext(bool $backwards = false): bool` | Move the focus to the next window (one that hosts nothing), depth first through the whole tree below, wrapping around; false when there is no other. Bind it to a key: Tab in a form, a prefix key where the windows are shells that need Tab. |
| `focusToward(string $direction): bool` | Move the focus to the window next to the focused one: `'left'`, `'right'`, `'up'` or `'down'`, by where the windows show on the screen. Of the windows entirely on that side, those alongside the focused one come first, then the nearest, then the best centred. False when there is none. Bind it to Alt-arrows, or a prefix key and the arrows as in tmux. |
| `setFocusable(bool $focusable)`, `isFocusable()` | Whether the surface takes the focus by itself (default true): a host whose windows were never focused gives the keys to the first one that does, and `focusNext()`/`focusToward()` skip those that do not (and everything below them). For labels and status lines; `focus()` still focuses one when asked. A host whose windows all refuse keeps the keys itself. |
| `setFocusOnClick(bool $on)` | Whether a mouse press on a window focuses it (off by default), here and in every surface below that is not set otherwise: set it on the root for click-to-focus everywhere. |

## Viewport

A viewport is where a hosted surface shows on its host: an outer rectangle (border included)
showing a rectangle of the source. You get one from `host()` or from a layout; you never
construct one.

| Method | What it does |
|---|---|
| `source` | The hosted surface (a readonly property). |
| `host(): Surface` | The surface it is on. |
| `isFocused(): bool` | True while its surface has the host's focus. |
| `x()`, `y()`, `width()`, `height()` | The outer rectangle on the host. |
| `innerWidth()`, `innerHeight()` | The area that shows the source (outer minus the border). |
| `move(int $x, int $y)` | Place the outer rectangle. |
| `resize(int $width, int $height)` | Set the outer size; the source keeps its size unless the viewport fits it. |
| `setFit(bool $fit)`, `fits()` | Keep the source the size of the inner area, so a program in it fills the window and is told about every resize. Layouts turn it on. |
| `setBorder(bool\|string $border, ?Style $style = null, ?Style $focusStyle = null)`, `hasBorder()`, `borderKind()` | A one-cell border, in `$focusStyle` while focused; `$border` is the kind of line, `'single'`, `'round'`, `'double'`, `'heavy'` or `'ascii'` (`Viewport::BORDERS`), true for the kind set before, false for none. |
| `setTitle(string $title)`, `title()` | The title in the top border. |
| `setShadow(?int $color)`, `shadow()` | A shadow one column right of the window and one row under it, cast on the windows below it: their cells there are drawn dimmed on the background `$color` (`Color::DEFAULT`: dimmed only); null for none. What the host draws itself is not shaded. A `Dialog` has one in every theme (the `shadow` style). |
| `follow()`, `isFollowing()` | Follow the bottom of the source (the default), so new output stays in view. |
| `scrollTo(int $row)` | Pin the view with source row `$row` at the top; negative rows are scrollback. Clamped; scrolling to the bottom follows again. The view stays on the same text while more lines scroll in. |
| `scrollBy(int $rows)` | Negative looks back, positive forward. |
| `pan(int $col)`, `left()` | Scroll sideways, for a source wider than the window. |
| `top()` | The source row at the top of the inner area. |
| `contains(int $x, int $y)` | True if a host position is inside the outer rectangle. |
| `toSource(int $x, int $y, bool $clamp = false): ?array` | The source `[col, row]` shown at a host position; null outside the inner area (or the nearest inner cell with `$clamp`). |
| `toHost(int $col, int $row): ?array` | Where a source position shows on the host, or null. |
| `select(int $fromCol, int $fromRow, int $toCol, int $toRow)` | Select source text in reading order (rows may be negative); it is highlighted. |
| `clearSelection()`, `hasSelection()` | |
| `selectedText(): string` | The selection, rows joined with `"\n"` except where a row wrapped, trailing blanks removed. |
