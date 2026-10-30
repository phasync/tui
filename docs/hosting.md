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

## Compositing is writing

When a hosted surface changes, its host repaints the changed cells of its window into its own
cells **by writing escape sequences to itself**, through the same `write()` every producer
uses. Damage then propagates: the host has changed where it repainted, so its own host repaints,
up to the root. The host's own producer keeps its state: the compositor saves and restores its
cursor, pen and character sets around the drawing (DECSC/DECRC) and steps around origin and
insert mode.

Compositing is lazy. A change marks the host (and every host above it) as needing to compose,
and they emit `change`; the work happens when the host's cells, damage or version are read,
when `compose()` is called, or when a terminal device draws a frame. While a hosted program is
in the middle of a synchronized update (mode 2026), its window keeps showing the previous frame,
for at most half a second, so its redraws never show half done.

**Always a diff, never a full re-render.** Every level works from damage. A Surface records
which rows changed; a window remembers the cells it last put on its host and writes only those
that differ, coalesced into runs of the same style per row, and never the cells a window above
covers (so a change below never repaints a window above). The terminal device in turn writes
only the root's cells that differ from its previous frame. A window is repainted in full only
when it is newly shown, moved, resized or raised, or when what covered it moved away. A pane
printing continuously costs its changed cells per frame, whatever the byte volume (a spinner
writing 40 kB between two frames costs a few dozen bytes on the terminal); an idle pane costs
nothing.

Windows are painted in hosting order (later ones on top). When a window moves or is unhosted,
the host blanks the area it left, and what was beneath is repainted. What the host's own
producer writes under a window is painted over by the window.

## Hosting methods on Surface

| Method | What it does |
|---|---|
| `host(Surface $child, ?int $x = null, ?int $y = null, ?int $width = null, ?int $height = null): Viewport` | Show `$child` and return its viewport. Without geometry it shows at the top-left at its own size. Hosting it again returns the same viewport (moved and resized if geometry is given). The first surface hosted gets the focus. Throws if `$child` is this surface or hosts it. |
| `unhost(Surface $child)` | Stop showing it; its area is blanked. |
| `raise(Surface $child)` | Bring its window to the top. |
| `viewport(Surface $child): ?Viewport` | The viewport of a hosted surface, or null. |
| `viewports(): list<Viewport>` | All windows, bottom first. |
| `setLayout(?Layout $layout)`, `layout()` | Let a layout place the windows, now and on every resize (see [layout](layout.md)). |
| `compose()` | Repaint what changed in hosted surfaces now (normally automatic). |
| `needsCompose(): bool` | True while a hosted surface changed and the cells have not caught up. |
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

## Focus

Each host has one focused hosted surface, or keeps the focus itself. The focus path runs from
the root down through each host's focused surface; keys go to its end.

| Method | What it does |
|---|---|
| `focus(?Surface $child)` | Focus a hosted surface, or this surface itself with null. Both sides get a `Focus` event (a program with focus reporting reads `ESC [O` / `ESC [I`). |
| `focused(): ?Surface` | The focused hosted surface, or null. |
| `focusNext(bool $backwards = false): bool` | Move the focus to the next window (one that hosts nothing), depth first through the whole tree below, wrapping around; false when there is no other. Bind it to a key: Tab in a form, a prefix key where the windows are shells that need Tab. |
| `setFocusOnClick(bool $on)` | Whether a mouse press on a window focuses it (off by default). |

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
| `setBorder(bool $border, ?Style $style = null, ?Style $focusStyle = null)`, `hasBorder()` | A one-cell border, in `$focusStyle` while focused. |
| `setTitle(string $title)`, `title()` | The title in the top border. |
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
