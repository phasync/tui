<?php

namespace phasync\Tui\Tests\Support;

use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Style;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * The reference compositor for tests: what a host shows, made the way the library did it until
 * 0.0.3-beta7, by writing escape sequences. A fresh Surface of the host's size gets the host's
 * own cells, then each window in z-order (its source composed the same way first): border,
 * title, the source's cells in view (a selection in inverse), a shadow from the windows above, written cell by cell
 * through ANSI and parsed by the emulator. The blitting compositor must give the same cells.
 *
 * A host's own cells are not readable from it (its cells are what it shows, windows included),
 * so the test writes what a host's producer writes to a twin surface as well (`$twins`).
 */
final class AnsiComposite
{
    /**
     * @param \WeakMap<Surface, Surface> $twins for each host, a surface that got the same writes and hosts nothing
     * @param Style $border      the style the test gives every border
     * @param Style $focusBorder the style of a focused window's border
     */
    public function __construct(
        private readonly \WeakMap $twins,
        private readonly Style $border,
        private readonly Style $focusBorder,
    ) {
    }

    /** What $surface shows: its own cells with its windows over them, as a fresh Surface. */
    public function of(Surface $surface): Surface
    {
        $own = $this->twins[$surface] ?? $surface;
        if ($surface->viewports() === [] && $own === $surface) {
            return $surface;
        }
        $f = new Surface($surface->cols(), $surface->rows(), 0);
        $f->write("\e[?7l");
        for ($y = 0; $y < $surface->rows(); $y++) {
            [$chars, $styles] = $own->rowData($y);
            $this->write($f, 0, $y, $chars, $styles, null);
        }
        $views = $surface->viewports();
        foreach ($views as $i => $v) {
            $shades = [];
            for ($j = $i + 1; $j < \count($views); $j++) {
                array_push($shades, ...$views[$j]->shadowRects());
            }
            $this->paint($f, $v, $shades);
        }
        return $f;
    }

    /** @param list<array{int,int,int,int,int}> $shades */
    private function paint(Surface $f, Viewport $v, array $shades): void
    {
        $source = $v->source;
        $composite = $this->of($source);
        $own = $this->twins[$source] ?? $source;
        $w = $v->width();
        $h = $v->height();
        $b = $v->hasBorder() ? 1 : 0;
        $style = $v->isFocused() ? $this->focusBorder : $this->border;
        $innerW = $v->innerWidth();
        $plain = Style::plain();
        for ($r = 0; $r < $h; $r++) {
            $ty = $v->y() + $r;
            if ($ty < 0 || $ty >= $f->rows()) {
                continue;
            }
            if ($b && ($r === 0 || $r === $h - 1)) {
                [$tl, $tr, $bl, $br, $line] = Viewport::BORDERS[$v->borderKind()];
                $chars = array_fill(0, $w, $line);
                if ($w > 1) {
                    $chars[$w - 1] = $r === 0 ? $tr : $br;
                }
                $chars[0] = $r === 0 ? $tl : $bl;
                $styles = array_fill(0, $w, $style);
                if ($r === 0 && $v->title() !== '' && $w > 4) {
                    $t = str_split(substr(' ' . $v->title() . ' ', 0, $w - 2));
                    $n = \count($t);
                    $i = 1 + match ($v->titleAlign()) {
                        'center' => intdiv($w - 2 - $n, 2),
                        'right' => $w - 2 - $n,
                        default => 0,
                    };
                    array_splice($chars, $i, $n, $t);
                }
            } else {
                $row = $v->top() + $r - $b;
                $rc = $rs = [];
                if ($row >= 0 && $row < $source->rows()) {
                    [$rc, $rs] = $composite->rowData($row);
                } elseif ($row < 0 && $row >= -$own->scrollbackSize()) {
                    [$rc, $rs] = $own->rowData($row);
                }
                $chars = \array_slice($rc, $v->left(), $innerW);
                $styles = \array_slice($rs, $v->left(), $innerW);
                for ($i = \count($chars); $i < $innerW; $i++) {
                    $chars[] = ' ';
                    $styles[] = $plain;
                }
                if ($innerW > 0) {
                    if ($chars[0] === '') {
                        $chars[0] = ' ';
                    }
                    if ($chars[$innerW - 1] !== '' && ($rc[$v->left() + $innerW] ?? null) === '') {
                        $chars[$innerW - 1] = ' ';
                    }
                }
                $selection = $v->selectionRange();
                for ($i = 0; $selection !== null && $i < $innerW; $i++) {
                    [$c1, $r1, $c2, $r2] = $selection;
                    $col = $v->left() + $i;
                    if ($row >= $r1 && $row <= $r2 && !($row === $r1 && $col < $c1) && !($row === $r2 && $col > $c2)) {
                        $styles[$i] = $styles[$i]->has(Style::INVERSE) ? $styles[$i]->without(Style::INVERSE) : $styles[$i]->with(Style::INVERSE);
                    }
                }
                if ($b) {
                    $side = Viewport::BORDERS[$v->borderKind()][5];
                    array_unshift($chars, $side);
                    array_unshift($styles, $style);
                    if ($w > 1) {
                        $chars[] = $side;
                        $styles[] = $style;
                    }
                }
            }
            foreach ($shades as [$sx, $sy, $sw, $sh, $color]) {
                if ($ty >= $sy && $ty < $sy + $sh) {
                    for ($x = max($v->x(), $sx); $x < min($v->x() + $w, $sx + $sw); $x++) {
                        $s = $styles[$x - $v->x()]->with(Style::DIM);
                        $styles[$x - $v->x()] = $color === Color::DEFAULT ? $s : $s->withBg($color);
                    }
                }
            }
            $this->write($f, $v->x(), $ty, $chars, $styles, $w);
        }
    }

    /**
     * Write cells at ($x0, $y) of $f, those inside it; a wide character whose other half is
     * outside is written as a blank.
     *
     * @param list<string> $chars
     * @param list<Style>  $styles
     */
    private function write(Surface $f, int $x0, int $y, array $chars, array $styles, ?int $width): void
    {
        $out = '';
        $n = $width ?? \count($chars);
        for ($i = 0; $i < $n; $i++) {
            $x = $x0 + $i;
            if ($x < 0 || $x >= $f->cols()) {
                continue;
            }
            $ch = $chars[$i];
            if ($ch === '') {
                if ($i > 0 && $x - 1 >= 0) {
                    continue;       // (written with its first half)
                }
                $ch = ' ';
            } elseif (($chars[$i + 1] ?? null) === '' && ($i + 1 >= $n || $x + 1 >= $f->cols())) {
                $ch = ' ';
            }
            $st = $styles[$i];
            $out .= Ansi::moveTo($x, $y) . Ansi::style($st) . Ansi::link($st->link, $st->linkId) . $ch;
        }
        $f->write($out . "\e[0m" . Ansi::link());
    }
}
