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
    private int $x;
    private int $y;
    private int $width;
    private int $height;
    private bool $fit = false;
    private bool $border = false;
    private Style $borderStyle;
    private ?Style $focusStyle = null;
    private string $title = '';
    private bool $following = true;
    /** Pinned top row in source coordinates (when not following). */
    private int $pinnedTop = 0;
    private int $left = 0;
    private int $seenScrolled;
    /** @var array{int,int,int,int}|null selection anchor col,row and end col,row in source coordinates */
    private ?array $selection = null;

    // what was painted last, to find what needs repainting
    private ?int $paintedVersion = null;
    private ?string $paintedKey = null;

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
            $old = $this->rect();
            $this->x = $x;
            $this->y = $y;
            $this->owner->moved($this, $old);
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
            $old = $this->rect();
            $this->width = $width;
            $this->height = $height;
            $this->fitSource();
            $this->owner->moved($this, $old);
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
     * false. A fitted source is resized to the new inner area.
     */
    public function setBorder(bool $border, ?Style $style = null, ?Style $focusStyle = null): static
    {
        $this->border = $border;
        $this->borderStyle = $style ?? $this->borderStyle;
        $this->focusStyle = $focusStyle ?? $this->focusStyle;
        $this->fitSource();
        $this->owner->changed($this);
        return $this;
    }

    /** True if the viewport draws a border. */
    public function hasBorder(): bool
    {
        return $this->border;
    }

    /** The title shown in the top border (only visible with a border). */
    public function setTitle(string $title): static
    {
        if ($title !== $this->title) {
            $this->title = $title;
            $this->owner->changed($this);
        }
        return $this;
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
            $this->owner->changed($this);
        }
        return $this;
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
        $this->following = $row >= $max;
        $this->pinnedTop = $row;
        $this->owner->changed($this);
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
        $this->left = max(0, min($col, max(0, $this->source->cols() - $this->innerWidth())));
        $this->owner->changed($this);
        return $this;
    }

    /** The source row shown at the top of the inner area (negative when in the scrollback). */
    public function top(): int
    {
        if ($this->following) {
            return $this->bottomTop();
        }
        $this->syncScrolled();
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
        $this->owner->changed($this);
        return $this;
    }

    /** Remove the selection. */
    public function clearSelection(): static
    {
        if ($this->selection !== null) {
            $this->selection = null;
            $this->owner->changed($this);
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

    /** @return array{int,int,int,int}|null the selection ordered start-before-end */
    private function selectionRange(): ?array
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
    // Painting (by the host)

    /** @internal @return array{int,int,int,int} x, y, width, height */
    public function rect(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }

    /** @internal Paint everything again at the next compose. */
    public function invalidate(): void
    {
        $this->paintedKey = null;
    }

    /**
     * @internal The host rows that must be repainted because the source changed, the view
     * scrolled or the geometry, border, title, focus or selection changed since the last paint.
     *
     * @return list<int>
     */
    public function damagedRows(): array
    {
        $key = $this->paintKey();
        if ($key !== $this->paintedKey || $this->paintedVersion === null) {
            return $this->height > 0 ? range($this->y, $this->y + $this->height - 1) : [];
        }
        $b = $this->border ? 1 : 0;
        $top = $this->top();
        $rows = [];
        foreach ($this->source->damagedSince($this->paintedVersion) as $sourceRow) {
            $r = $sourceRow - $top;
            if ($r >= 0 && $r < $this->innerHeight()) {
                $rows[] = $this->y + $b + $r;
            }
        }
        return $rows;
    }

    /** Everything that, when changed, means a full repaint. */
    private function paintKey(): string
    {
        $style = $this->isFocused() && $this->focusStyle !== null ? $this->focusStyle : $this->borderStyle;
        return implode(',', [$this->x, $this->y, $this->width, $this->height, $this->border ? 1 : 0,
            spl_object_id($style), $this->title, $this->top(), $this->left, $this->source->cols(),
            $this->source->rows(), json_encode($this->selection)]);
    }

    /**
     * @internal The escape sequences that draw the given host rows of this viewport (border,
     * title and source cells), clipped to a host of $cols x $rows. The host writes them to itself.
     *
     * @param array<int, mixed> $wanted host rows to draw, as keys
     */
    public function paint(array $wanted, int $cols, int $rows): string
    {
        $this->syncScrolled();
        $out = '';
        $x0 = $this->x;
        $y0 = $this->y;
        $h = $this->height;
        $clipL = max(0, $x0);
        $clipR = min($cols, $x0 + $this->width);  // exclusive
        if ($clipL < $clipR) {
            $b = $this->border ? 1 : 0;
            $style = $this->isFocused() && $this->focusStyle !== null ? $this->focusStyle : $this->borderStyle;
            $innerW = $this->innerWidth();
            $top = $this->top();
            $left = $this->left;
            $selection = $this->selectionRange();
            $minRow = -$this->source->scrollbackSize();
            $srcRows = $this->source->rows();
            $plain = Style::plain();
            for ($r = 0; $r < $h; $r++) {
                $ty = $y0 + $r;
                if ($ty < 0 || $ty >= $rows || !isset($wanted[$ty])) {
                    continue;
                }
                if ($b && ($r === 0 || $r === $h - 1)) {
                    $out .= $this->borderRow($r === 0, $style, $clipL, $clipR, $ty);
                    continue;
                }
                $chars = [];
                $styles = [];
                if ($b) {
                    $chars[] = '│';
                    $styles[] = $style;
                }
                $srcRow = $top + $r - $b;
                if ($srcRow >= $minRow && $srcRow < $srcRows) {
                    [$rc, $rs] = $this->source->rowData($srcRow);
                } else {
                    $rc = $rs = [];
                }
                for ($i = 0; $i < $innerW; $i++) {
                    $col = $left + $i;
                    $ch = $rc[$col] ?? ' ';
                    $st = $rs[$col] ?? $plain;
                    if ($ch === '' && $i === 0) {
                        $ch = ' ';    // second half of a wide character cut at the left edge
                    } elseif ($ch !== '' && $i === $innerW - 1 && ($rc[$col + 1] ?? null) === '') {
                        $ch = ' ';    // wide character cut at the right edge
                    }
                    if ($selection !== null && self::inSelection($selection, $col, $srcRow)) {
                        $st = $st->has(Style::INVERSE) ? $st->without(Style::INVERSE) : $st->with(Style::INVERSE);
                    }
                    $chars[] = $ch;
                    $styles[] = $st;
                }
                if ($b) {
                    $chars[] = '│';
                    $styles[] = $style;
                }
                $out .= self::rowAnsi($chars, $styles, $x0, $clipL, $clipR, $ty);
            }
        }
        $this->paintedVersion = $this->source->version();
        $this->paintedKey = $this->paintKey();
        return $out;
    }

    /** @param array{int,int,int,int} $sel */
    private static function inSelection(array $sel, int $col, int $row): bool
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

    private function borderRow(bool $isTop, Style $style, int $clipL, int $clipR, int $ty): string
    {
        $w = $this->width;
        $chars = array_fill(0, $w, '─');
        $chars[0] = $isTop ? '┌' : '└';
        $chars[$w - 1] = $isTop ? '┐' : '┘';
        if ($isTop && $this->title !== '' && $w > 4) {
            $i = 1;
            foreach (Text::clusters(' ' . $this->title . ' ') as [$g, $gw]) {
                if ($i + $gw > $w - 1) {
                    break;
                }
                $chars[$i] = $g;
                if ($gw === 2) {
                    $chars[$i + 1] = '';
                }
                $i += $gw;
            }
        }
        return self::rowAnsi($chars, array_fill(0, $w, $style), $this->x, $clipL, $clipR, $ty);
    }

    /**
     * Escape sequences that draw cells (a row starting at host column $x0) clipped to
     * [$clipL, $clipR).
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     */
    private static function rowAnsi(array $chars, array $styles, int $x0, int $clipL, int $clipR, int $ty): string
    {
        $from = $clipL - $x0;
        $to = $clipR - $x0; // exclusive
        if ($from >= $to) {
            return '';
        }
        $out = Ansi::moveTo($clipL, $ty);
        $style = null;
        for ($i = $from; $i < $to; $i++) {
            $ch = $chars[$i];
            if ($ch === '') {
                if ($i !== $from) {
                    continue;
                }
                $ch = ' ';
            } elseif ($i === $to - 1 && ($chars[$i + 1] ?? null) === '') {
                $ch = ' ';
            }
            if ($styles[$i] !== $style) {
                $style = $styles[$i];
                $out .= Ansi::style($style);
            }
            $out .= $ch;
        }
        return $out;
    }
}
