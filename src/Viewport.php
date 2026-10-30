<?php

namespace phasync\Tui;

/**
 * A window onto a Surface: shows a rectangle of a source surface at a position on a target
 * surface (the screen, or any other surface), with optional border and title.
 *
 *     $log = new Surface(200, 50);              // may be larger than the window, both ways
 *     $view = new Viewport($log, x: 2, y: 1, width: 40, height: 10);
 *     $view->setBorder(true)->setTitle('log');
 *     $view->paint($screenSurface);             // draws with escape sequences, like any producer
 *
 * Geometry (`x`, `y`, `width`, `height`) is the outer rectangle on the target, border included;
 * the source shows in the inner rectangle. Scrolling picks which source rows show: by default the
 * view *follows* the bottom of the source (new output stays in view); `scrollBy()`/`scrollTo()`
 * pin it to a position, also back into the scrollback (negative rows), and it stays on the same
 * text while more lines scroll in. `pan()` scrolls sideways when the source is wider.
 *
 * A Screen paints its viewports for you; call `paint()` yourself to draw onto your own surface.
 */
final class Viewport
{
    private int $x;
    private int $y;
    private int $width;
    private int $height;
    private bool $border = false;
    private Style $borderStyle;
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
     * @param Surface  $source the surface to show
     * @param int      $x      left column on the target
     * @param int      $y      top row on the target
     * @param int|null $width  outer width, default the source's width (plus the border)
     * @param int|null $height outer height, default the source's height (plus the border)
     */
    public function __construct(
        /** The surface shown in this viewport. */
        public readonly Surface $source,
        int $x = 0,
        int $y = 0,
        ?int $width = null,
        ?int $height = null,
    ) {
        $this->x = $x;
        $this->y = $y;
        $this->width = max(0, $width ?? $source->cols());
        $this->height = max(0, $height ?? $source->rows());
        $this->borderStyle = Style::plain();
        $this->seenScrolled = $source->linesScrolled();
    }

    /** Left column of the outer rectangle on the target. */
    public function x(): int
    {
        return $this->x;
    }

    /** Top row of the outer rectangle on the target. */
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

    /** Place the outer rectangle with its top-left corner at column $x, row $y of the target. */
    public function move(int $x, int $y): static
    {
        $this->x = $x;
        $this->y = $y;
        return $this;
    }

    /** Set the outer size. The source keeps its size; see `Component` for windows that resize their surface. */
    public function resize(int $width, int $height): static
    {
        $this->width = max(0, $width);
        $this->height = max(0, $height);
        return $this;
    }

    /**
     * Draw a one-cell border around the inner area, in $style (for example a different color for
     * the focused window), or remove it with false.
     */
    public function setBorder(bool $border, ?Style $style = null): static
    {
        $this->border = $border;
        if ($style !== null) {
            $this->borderStyle = $style;
        }
        return $this;
    }

    public function hasBorder(): bool
    {
        return $this->border;
    }

    /** The title shown in the top border (only visible with a border). */
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function title(): string
    {
        return $this->title;
    }

    // ------------------------------------------------------------------------------------------
    // Scrolling

    /** Follow the bottom of the source again, so new output is in view. */
    public function follow(): static
    {
        $this->following = true;
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
            if (!$this->following) {
                $this->pinnedTop = max(-$this->source->scrollbackSize(), $this->pinnedTop - ($scrolled - $this->seenScrolled));
            }
            if ($this->selection !== null) {
                $d = $scrolled - $this->seenScrolled;
                $this->selection[1] -= $d;
                $this->selection[3] -= $d;
            }
            $this->seenScrolled = $scrolled;
        }
    }

    // ------------------------------------------------------------------------------------------
    // Coordinates and selection

    /** True if column $x, row $y of the target is inside the outer rectangle. */
    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->width && $y >= $this->y && $y < $this->y + $this->height;
    }

    /**
     * The source position [col, row] shown at target column $x, row $y, or null if that point
     * is not in the inner area. Rows can be negative (scrollback).
     *
     * @return array{int,int}|null
     */
    public function toSource(int $x, int $y): ?array
    {
        $b = $this->border ? 1 : 0;
        $ix = $x - $this->x - $b;
        $iy = $y - $this->y - $b;
        if ($ix < 0 || $iy < 0 || $ix >= $this->innerWidth() || $iy >= $this->innerHeight()) {
            return null;
        }
        return [$this->left + $ix, $this->top() + $iy];
    }

    /**
     * Select text from source position ($fromCol, $fromRow) to ($toCol, $toRow) inclusive, in
     * reading order as a terminal selects (rows may be negative). The selection is highlighted
     * when painted; `selectedText()` returns its text.
     */
    public function select(int $fromCol, int $fromRow, int $toCol, int $toRow): static
    {
        $this->syncScrolled();
        $this->selection = [$fromCol, $fromRow, $toCol, $toRow];
        $this->paintedKey = null;
        return $this;
    }

    /** Remove the selection. */
    public function clearSelection(): static
    {
        if ($this->selection !== null) {
            $this->selection = null;
            $this->paintedKey = null;
        }
        return $this;
    }

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
            $text = rtrim(implode('', \array_slice($chars, $from, $to - $from + 1)), ' ');
            $out .= $text;
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
    // Painting

    /**
     * The target rows that must be repainted because the source changed, the view scrolled or
     * the geometry, border, title or selection changed since the last `paint()`.
     *
     * @return list<int>
     */
    public function damagedRows(): array
    {
        $key = $this->paintKey();
        if ($key !== $this->paintedKey || $this->paintedVersion === null) {
            return range($this->y, $this->y + $this->height - 1);
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
        return implode(',', [$this->x, $this->y, $this->width, $this->height, $this->border ? 1 : 0,
            spl_object_id($this->borderStyle), $this->title, $this->top(), $this->left, $this->source->cols(),
            $this->source->rows(), json_encode($this->selection)]);
    }

    /**
     * Draw the viewport onto $target with escape sequences: the border and title, and the source
     * cells of the inner area. With $rows, only those target rows are drawn. Everything outside
     * the target is clipped. The target's cursor and pen are left changed (the compositor does
     * not care; restore them yourself if you draw onto a surface a program also writes to).
     *
     * @param list<int>|null $rows target rows to draw, all when null
     */
    public function paint(Surface $target, ?array $rows = null): void
    {
        $this->syncScrolled();
        $out = '';
        $x0 = $this->x;
        $y0 = $this->y;
        $w = $this->width;
        $h = $this->height;
        $tcols = $target->cols();
        $trows = $target->rows();
        $clipL = max(0, $x0);
        $clipR = min($tcols, $x0 + $w);  // exclusive
        if ($clipL >= $clipR) {
            $this->markPainted();
            return;
        }
        $wanted = $rows === null ? null : array_flip($rows);
        $b = $this->border ? 1 : 0;
        $innerW = $this->innerWidth();
        $top = $this->top();
        $left = $this->left;
        $selection = $this->selectionRange();
        $minRow = -$this->source->scrollbackSize();
        $srcRows = $this->source->rows();

        for ($r = 0; $r < $h; $r++) {
            $ty = $y0 + $r;
            if ($ty < 0 || $ty >= $trows || ($wanted !== null && !isset($wanted[$ty]))) {
                continue;
            }
            if ($b && ($r === 0 || $r === $h - 1)) {
                $out .= $this->borderRow($r === 0, $clipL, $clipR, $ty);
                continue;
            }
            // one row: optional left border, inner cells, optional right border
            $chars = [];
            $styles = [];
            if ($b) {
                $chars[] = '│';
                $styles[] = $this->borderStyle;
            }
            $srcRow = $top + $r - $b;
            if ($srcRow >= $minRow && $srcRow < $srcRows) {
                [$rc, $rs] = $this->source->rowData($srcRow);
            } else {
                $rc = $rs = [];
            }
            $plain = Style::plain();
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
                $styles[] = $this->borderStyle;
            }
            $out .= $this->rowAnsi($chars, $styles, $x0, $clipL, $clipR, $ty);
        }
        if ($out !== '') {
            $target->write("\e(B" . $out . "\e[0m");
        }
        $this->markPainted();
    }

    private function markPainted(): void
    {
        $this->paintedVersion = $this->source->version();
        $this->paintedKey = $this->paintKey();
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

    private function borderRow(bool $isTop, int $clipL, int $clipR, int $ty): string
    {
        $w = $this->width;
        $chars = array_fill(0, $w, '─');
        $chars[0] = $isTop ? '┌' : '└';
        $chars[$w - 1] = $isTop ? '┐' : '┘';
        if ($isTop && $this->title !== '' && $w > 4) {
            $title = ' ' . $this->title . ' ';
            $i = 1;
            foreach (Text::clusters($title) as [$g, $gw]) {
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
        return $this->rowAnsi($chars, array_fill(0, $w, $this->borderStyle), $this->x, $clipL, $clipR, $ty);
    }

    /**
     * Escape sequences that draw cells (a row starting at target column $x0) clipped to
     * [$clipL, $clipR).
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     */
    private function rowAnsi(array $chars, array $styles, int $x0, int $clipL, int $clipR, int $ty): string
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
                if ($i === $from) {
                    $ch = ' ';
                } else {
                    continue;
                }
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
