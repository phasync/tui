<?php

namespace phasync\Tui;

use phasync\Tui\Surface\Host;

/**
 * Where a hosted Surface shows on its host: a rectangle of the host that shows a rectangle of the
 * hosted surface (the *source*), with an optional border and title drawn by the host.
 *
 *     $root = new Surface(80, 24);
 *     $log = new Surface(200, 50);                       // may be larger than the window, both ways
 *     $view = $root->host($log, x: 2, y: 1, width: 40, height: 10);
 *     $view->setBorder(true)->setTitle('log');
 *     $view->scrollBy(-10);                              // look back into the scrollback
 *
 * You get a viewport from `Surface::host()` (or from a layout, which hosts its surfaces for you)
 * and never construct one. Geometry (`x`, `y`, `width`, `height`) is the outer rectangle on the
 * host, border included; the source shows in the inner rectangle. With `setFit(true)` (layouts
 * turn it on) the source is kept the size of the inner rectangle, so a program in it always fills
 * the window and is told about every resize.
 *
 * Scrolling picks which source rows show: by default the view *follows* the bottom of the source
 * (new output stays in view); `scrollBy()`/`scrollTo()` pin it to a position, also back into the
 * scrollback (negative rows), and it stays on the same text while more lines scroll in. `pan()`
 * scrolls sideways when the source is wider. The host scrolls a viewport with the mouse wheel and
 * selects text in it with a mouse drag when the program in it has not asked for the mouse.
 */
final class Viewport
{
    /**
     * The kinds of border lines, for `setBorder()`: the corners top-left, top-right, bottom-left,
     * bottom-right, then the horizontal and the vertical line.
     */
    public const BORDERS = [
        'single' => ['┌', '┐', '└', '┘', '─', '│'],
        'round' => ['╭', '╮', '╰', '╯', '─', '│'],
        'double' => ['╔', '╗', '╚', '╝', '═', '║'],
        'heavy' => ['┏', '┓', '┗', '┛', '━', '┃'],
        'ascii' => ['+', '+', '+', '+', '-', '|'],
    ];

    private int $x;
    private int $y;
    private int $width;
    private int $height;
    private bool $fit = false;
    private bool $border = false;
    /** The line kind of the border: one of the keys of `Viewport::BORDERS`. */
    private string $borderKind = 'single';
    private Style $borderStyle;
    private ?Style $focusStyle = null;
    private string $title = '';
    private string $titleAlign = 'left';
    /** @var array{string, list<string>, list<Style>}|null the title's cells, for the key they were made for */
    private ?array $titleCells = null;
    private bool $following = true;
    /** Keys and pastes routed through it bring the bottom of the source into view (see setFollowOnInput()). */
    private bool $followOnInput = true;
    /** Pinned top row in source coordinates (when not following). */
    private int $pinnedTop = 0;
    private int $left = 0;
    private int $seenScrolled;
    /** @var array{int,int,int,int}|null selection anchor col,row and end col,row in source coordinates */
    private ?array $selection = null;

    /** The shadow it casts on the windows below, right and under it: their cells dimmed, on this background (`Color::DEFAULT`: dimmed only); null for none. */
    private ?int $shadow = null;

    /**
     * @internal Viewports are made by `Surface::host()`.
     */
    public function __construct(
        /** The surface shown in this viewport. */
        public readonly Surface $source,
        private readonly Host $owner,
        int $x,
        int $y,
        int $width,
        int $height,
    ) {
        $this->x = $x;
        $this->y = $y;
        $this->width = max(0, $width);
        $this->height = max(0, $height);
        $this->borderStyle = Style::plain();
        $this->seenScrolled = $source->linesScrolled();
    }

    /** The surface this viewport is on. */
    public function host(): Surface
    {
        return $this->owner->surface;
    }

    /** True while the host's focus is on this viewport's surface. */
    public function isFocused(): bool
    {
        return $this->owner->focus === $this;
    }

    /** Left column of the outer rectangle on the host. */
    public function x(): int
    {
        return $this->x;
    }

    /** Top row of the outer rectangle on the host. */
    public function y(): int
    {
        return $this->y;
    }

    /** Outer width, border included. */
    public function width(): int
    {
        return $this->width;
    }

    /** Outer height, border included. */
    public function height(): int
    {
        return $this->height;
    }

    /** Width of the area that shows the source (the outer width minus the border). */
    public function innerWidth(): int
    {
        return max(0, $this->width - ($this->border ? 2 : 0));
    }

    /** Height of the area that shows the source (the outer height minus the border). */
    public function innerHeight(): int
    {
        return max(0, $this->height - ($this->border ? 2 : 0));
    }

    /** Place the outer rectangle with its top-left corner at column $x, row $y of the host. */
    public function move(int $x, int $y): static
    {
        if ($x !== $this->x || $y !== $this->y) {
            $this->x = $x;
            $this->y = $y;
            $this->owner->changed();
        }
        return $this;
    }

    /**
     * Set the outer size. The source keeps its size unless the viewport fits it (`setFit()`);
     * a source larger than the inner area is clipped, a smaller one leaves blank space.
     */
    public function resize(int $width, int $height): static
    {
        $width = max(0, $width);
        $height = max(0, $height);
        if ($width !== $this->width || $height !== $this->height) {
            $this->width = $width;
            $this->height = $height;
            $this->fitSource();
            $this->owner->changed();
        }
        return $this;
    }

    /**
     * Keep the source the size of the inner area (true), so a program in it fills the window and
     * follows its resizes, or leave the source's size alone (false, the default for viewports
     * made with `Surface::host()`; layouts turn it on for the surfaces they arrange).
     */
    public function setFit(bool $fit): static
    {
        $this->fit = $fit;
        $this->fitSource();
        return $this;
    }

    /** True if the source is kept the size of the inner area (see `setFit()`). */
    public function fits(): bool
    {
        return $this->fit;
    }

    private function fitSource(): void
    {
        if ($this->fit && $this->innerWidth() > 0 && $this->innerHeight() > 0) {
            $this->source->resize($this->innerWidth(), $this->innerHeight());
        }
    }

    /**
     * Draw a one-cell border around the inner area in $style, and in $focusStyle while the
     * viewport has the host's focus (so the user sees which window gets the keys); remove it with
     * false. $border is the kind of line, `'single'`, `'round'`, `'double'`, `'heavy'` or `'ascii'`
     * (see `Viewport::BORDERS`), or true for the kind set before (single lines at first). A fitted
     * source is resized to the new inner area.
     *
     * @throws \InvalidArgumentException for an unknown kind of line
     */
    public function setBorder(bool|string $border, ?Style $style = null, ?Style $focusStyle = null): static
    {
        if (\is_string($border)) {
            if (!isset(self::BORDERS[$border])) {
                throw new \InvalidArgumentException("Unknown border '$border'; one of " . implode(', ', array_keys(self::BORDERS)));
            }
        }
        $kind = \is_string($border) ? $border : $this->borderKind;
        $style ??= $this->borderStyle;
        $focusStyle ??= $this->focusStyle;
        if ($kind === $this->borderKind && ($border !== false) === $this->border && $style === $this->borderStyle && $focusStyle === $this->focusStyle) {
            return $this;   // (a layout sets it on every arrange: nothing to repaint)
        }
        $this->borderKind = $kind;
        $this->border = $border !== false;
        $this->borderStyle = $style;
        $this->focusStyle = $focusStyle;
        $this->fitSource();
        $this->owner->changed();
        return $this;
    }

    /**
     * Cast a shadow on the windows below this one, one cell to the right of it and one row
     * under it: their cells there are drawn dimmed, on the background $color (`Color::DEFAULT`:
     * dimmed only, in their own colours); null for none. Dialogs have one in the default themes
     * (the `shadow` style). It falls on windows, not on what the host draws itself.
     */
    public function setShadow(?int $color): static
    {
        if ($color !== $this->shadow) {
            $this->shadow = $color;
            $this->owner->changed();
        }
        return $this;
    }

    /** The shadow's background (see `setShadow()`), null for none. */
    public function shadow(): ?int
    {
        return $this->shadow;
    }

    /**
     * @internal The host rectangles its shadow falls on, with its colour: the column right of it
     * (from its second row down to the row under it) and the row under it (from its second
     * column on).
     *
     * @return list<array{int,int,int,int,int}> x, y, width, height, colour
     */
    public function shadowRects(): array
    {
        if ($this->shadow === null || $this->width <= 0 || $this->height <= 0) {
            return [];
        }
        return [[$this->x + $this->width, $this->y + 1, 1, $this->height, $this->shadow], [$this->x + 1, $this->y + $this->height, $this->width, 1, $this->shadow]];
    }

    /** The kind of line the border is drawn with (see `setBorder()`), also while there is none. */
    public function borderKind(): string
    {
        return $this->borderKind;
    }

    /** True if the viewport draws a border. */
    public function hasBorder(): bool
    {
        return $this->border;
    }

    /**
     * The title shown in the top border (only visible with a border), at the left, in the
     * middle or at the right of it ($align `'left'`, `'center'`, `'right'`; null keeps the one
     * set before). It is drawn in the border's style; SGR escape sequences in it (from
     * `Ansi::styled()` or markup, see `Markup::toAnsi()`) style parts of it.
     */
    public function setTitle(string $title, ?string $align = null): static
    {
        $align ??= $this->titleAlign;
        if ($title !== $this->title || $align !== $this->titleAlign) {
            $this->title = $title;
            $this->titleAlign = \in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
            $this->owner->changed();
        }
        return $this;
    }

    /** Where the title goes in the top border: `'left'`, `'center'` or `'right'`. */
    public function titleAlign(): string
    {
        return $this->titleAlign;
    }

    /** The title set with `setTitle()`. */
    public function title(): string
    {
        return $this->title;
    }

    // ------------------------------------------------------------------------------------------
    // Scrolling

    /** Follow the bottom of the source again, so new output is in view. */
    public function follow(): static
    {
        if (!$this->following) {
            $this->following = true;
            $this->owner->changed();
        }
        return $this;
    }

    /**
     * Whether a key or a paste routed through this viewport scrolls it back to the bottom of the
     * source (true, the default: typing goes to the bottom of the output, as in a terminal), or
     * leaves the scroll position alone (false: for a window whose owner keeps it where it wants,
     * as a `ScrollView` keeps its focused field in view).
     */
    public function setFollowOnInput(bool $follow): static
    {
        $this->followOnInput = $follow;
        return $this;
    }

    /** True if typing scrolls the view to the bottom of the source (see `setFollowOnInput()`). */
    public function followsOnInput(): bool
    {
        return $this->followOnInput;
    }

    /** True while the view follows the bottom of the source. */
    public function isFollowing(): bool
    {
        return $this->following;
    }

    /**
     * Pin the view so source row $row is at the top; negative rows are scrollback lines. The
     * position is clamped to what exists. Scrolling to the bottom follows again.
     */
    public function scrollTo(int $row): static
    {
        $this->syncScrolled();
        $max = $this->bottomTop();
        $row = max(-$this->source->scrollbackSize(), min($max, $row));
        $before = [$this->top(), $this->following];
        $this->following = $row >= $max;
        $this->pinnedTop = $row;
        if ($before !== [$row, $this->following]) {
            $this->owner->changed();     // (scrolled to where it is: nothing to repaint)
        }
        return $this;
    }

    /** Scroll by $rows: negative moves up into older lines, positive down towards the bottom. */
    public function scrollBy(int $rows): static
    {
        return $this->scrollTo($this->top() + $rows);
    }

    /** Scroll sideways: column $col of the source shows at the left edge. */
    public function pan(int $col): static
    {
        $left = max(0, min($col, max(0, $this->source->cols() - $this->innerWidth())));
        if ($left !== $this->left) {
            $this->left = $left;
            $this->owner->changed();
        }
        return $this;
    }

    /** The source row shown at the top of the inner area (negative when in the scrollback). */
    public function top(): int
    {
        if ($this->following) {
            return $this->bottomTop();
        }
        $this->syncScrolled();
        if ($this->pinnedTop >= $this->bottomTop()) {
            // the window grew or the source shrank: the pinned position is the bottom now
            $this->following = true;
            return $this->bottomTop();
        }
        return $this->pinnedTop;
    }

    /** The source column shown at the left edge. */
    public function left(): int
    {
        return $this->left;
    }

    /** The top row that shows the bottom of the source. */
    private function bottomTop(): int
    {
        return max(0, $this->source->rows() - $this->innerHeight());
    }

    /** Keep a pinned view on the same text when lines scroll into the scrollback. */
    private function syncScrolled(): void
    {
        $scrolled = $this->source->linesScrolled();
        if ($scrolled !== $this->seenScrolled) {
            $d = $scrolled - $this->seenScrolled;
            if (!$this->following) {
                $this->pinnedTop = max(-$this->source->scrollbackSize(), $this->pinnedTop - $d);
            }
            if ($this->selection !== null) {
                $this->selection[1] -= $d;
                $this->selection[3] -= $d;
            }
            $this->seenScrolled = $scrolled;
        }
    }

    // ------------------------------------------------------------------------------------------
    // Coordinates and selection

    /** True if column $x, row $y of the host is inside the outer rectangle. */
    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->width && $y >= $this->y && $y < $this->y + $this->height;
    }

    /**
     * The source position [col, row] shown at host column $x, row $y, or null if that point is
     * not in the inner area. Rows can be negative (scrollback). With $clamp a point outside is
     * moved to the nearest inner cell instead (for a mouse dragged out of the window).
     *
     * @return array{int,int}|null
     */
    public function toSource(int $x, int $y, bool $clamp = false): ?array
    {
        $b = $this->border ? 1 : 0;
        $ix = $x - $this->x - $b;
        $iy = $y - $this->y - $b;
        $w = $this->innerWidth();
        $h = $this->innerHeight();
        if ($w === 0 || $h === 0) {
            return null;
        }
        if ($clamp) {
            $ix = max(0, min($w - 1, $ix));
            $iy = max(0, min($h - 1, $iy));
        } elseif ($ix < 0 || $iy < 0 || $ix >= $w || $iy >= $h) {
            return null;
        }
        return [$this->left + $ix, $this->top() + $iy];
    }

    /**
     * The host position [x, y] where source column $col, row $row shows, or null if it is not in
     * view.
     *
     * @return array{int,int}|null
     */
    public function toHost(int $col, int $row): ?array
    {
        $ix = $col - $this->left;
        $iy = $row - $this->top();
        if ($ix < 0 || $iy < 0 || $ix >= $this->innerWidth() || $iy >= $this->innerHeight()) {
            return null;
        }
        $b = $this->border ? 1 : 0;
        return [$this->x + $b + $ix, $this->y + $b + $iy];
    }

    /**
     * Select text from source position ($fromCol, $fromRow) to ($toCol, $toRow) inclusive, in
     * reading order as a terminal selects (rows may be negative). The selection is highlighted
     * (inverse video) and `selectedText()` returns its text. The host does this for mouse drags.
     */
    public function select(int $fromCol, int $fromRow, int $toCol, int $toRow): static
    {
        $this->syncScrolled();
        $this->selection = [$fromCol, $fromRow, $toCol, $toRow];
        $this->owner->changed();
        return $this;
    }

    /** Remove the selection. */
    public function clearSelection(): static
    {
        if ($this->selection !== null) {
            $this->selection = null;
            $this->owner->changed();
        }
        return $this;
    }

    /** True while text is selected. */
    public function hasSelection(): bool
    {
        return $this->selection !== null;
    }

    /**
     * The selected text: rows joined with "\n" (except where a row wrapped onto the next),
     * trailing blanks removed. Empty without a selection.
     */
    public function selectedText(): string
    {
        $range = $this->selectionRange();
        if ($range === null) {
            return '';
        }
        [$c1, $r1, $c2, $r2] = $range;
        $min = -$this->source->scrollbackSize();
        $out = '';
        for ($row = max($min, $r1); $row <= min($r2, $this->source->rows() - 1); $row++) {
            [$chars] = $this->source->rowData($row);
            $from = $row === $r1 ? $c1 : 0;
            $to = $row === $r2 ? $c2 : \count($chars) - 1;
            $out .= rtrim(implode('', \array_slice($chars, $from, $to - $from + 1)), ' ');
            if ($row !== $r2 && !$this->source->isWrapped($row)) {
                $out .= "\n";
            }
        }
        return $out;
    }

    /** @internal @return array{int,int,int,int}|null the selection ordered start-before-end */
    public function selectionRange(): ?array
    {
        if ($this->selection === null) {
            return null;
        }
        $this->syncScrolled();
        [$c1, $r1, $c2, $r2] = $this->selection;
        if ($r2 < $r1 || ($r2 === $r1 && $c2 < $c1)) {
            [$c1, $r1, $c2, $r2] = [$c2, $r2, $c1, $r1];
        }
        return [$c1, $r1, $c2, $r2];
    }

    // ------------------------------------------------------------------------------------------
    // Compositing (by the host)

    /** @internal @return array{int,int,int,int} x, y, width, height */
    public function rect(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }

    /**
     * @internal What the host drew for this window in its last frame: [key, rows of graphemes,
     * rows of styles] (see Host::blit()).
     *
     * @var array{string, list<list<string>>, list<list<Style>>}|null
     */
    public ?array $drawn = null;

    /** @internal Everything about the window's look that its cells depend on, as a string to compare. */
    public function lookKey(): string
    {
        return ($this->border ? $this->borderKind : '') . "\0" . spl_object_id($this->borderStyleNow()) . "\0" . $this->title
            . "\0" . $this->titleAlign . "\0" . $this->top() . "\0" . $this->left
            . ($this->selection === null ? '' : "\0" . implode(',', $this->selectionRange()));
    }

    /** @internal The style the border is drawn in now: the focus style while focused, if there is one. */
    public function borderStyleNow(): Style
    {
        return $this->isFocused() && $this->focusStyle !== null ? $this->focusStyle : $this->borderStyle;
    }

    /** @internal @param array{int,int,int,int} $sel */
    public static function inSelection(array $sel, int $col, int $row): bool
    {
        [$c1, $r1, $c2, $r2] = $sel;
        if ($row < $r1 || $row > $r2) {
            return false;
        }
        if ($row === $r1 && $col < $c1) {
            return false;
        }
        return !($row === $r2 && $col > $c2);
    }

    /** @internal @return array{list<string>, list<Style>} the cells of the top or bottom border row */
    public function borderRow(bool $isTop, Style $style): array
    {
        $w = $this->width;
        [$tl, $tr, $bl, $br, $h] = self::BORDERS[$this->borderKind];
        $chars = array_fill(0, $w, $h);
        if ($w > 1) {
            $chars[$w - 1] = $isTop ? $tr : $br;
        }
        $chars[0] = $isTop ? $tl : $bl;
        $styles = array_fill(0, $w, $style);
        if ($isTop && $this->title !== '' && $w > 4) {
            [$tc, $ts] = $this->titleCells($style, $w - 2);
            $n = \count($tc);
            $i = 1 + match ($this->titleAlign) {
                'center' => intdiv($w - 2 - $n, 2),
                'right' => $w - 2 - $n,
                default => 0,
            };
            array_splice($chars, $i, $n, $tc);
            array_splice($styles, $i, $n, $ts);
        }
        return [$chars, $styles];
    }

    /**
     * The cells of ' title ' in $style, at most $room wide: plain text as it is, a title with
     * escape sequences through a surface of its own (written, as everything is).
     *
     * @return array{list<string>, list<Style>}
     */
    private function titleCells(Style $style, int $room): array
    {
        $key = $this->title . "\0" . spl_object_id($style) . "\0" . $room;
        if ($this->titleCells !== null && $this->titleCells[0] === $key) {
            return [$this->titleCells[1], $this->titleCells[2]];
        }
        $chars = $styles = [];
        if (!str_contains($this->title, "\e")) {
            $i = 0;
            foreach (Text::clusters(' ' . $this->title . ' ') as [$g, $gw]) {
                if ($i + $gw > $room) {
                    break;
                }
                $chars[] = $g;
                $styles[] = $style;
                if ($gw === 2) {
                    $chars[] = '';
                    $styles[] = $style;
                }
                $i += $gw;
            }
        } else {
            $title = Text::truncate(' ' . $this->title . ' ', $room, '');
            $width = min($room, Text::width($title));
            $s = new Surface(max(1, $width), 1, 0);
            $s->write("\e[?7l" . Ansi::style($style) . str_replace(["\r", "\n"], ' ', $title));
            [$chars, $styles] = $s->rowData(0);
            $chars = \array_slice($chars, 0, $width);
            $styles = \array_slice($styles, 0, $width);
        }
        $this->titleCells = [$key, $chars, $styles];
        return [$chars, $styles];
    }
}
