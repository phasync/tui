<?php

namespace phasync\Tui\Component;

use phasync\Canvas\Backend\Gd;
use phasync\Canvas\Backend\Imagick;
use phasync\Tui\Ansi;
use phasync\Tui\Color;
use phasync\Tui\Component;
use phasync\Tui\Internal\Dots;
use phasync\Tui\Mouse;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * A drawing surface with the browser's 2D canvas API: lines, shapes, fills, gradients, images
 * and text, drawn by a closure, shown in real pixels where the terminal shows images and in
 * Braille dots (or blocks) everywhere else, without the program changing.
 *
 *     new Canvas(draw: function (CanvasContext $c) use ($s) {
 *         $c->strokeStyle = '#38bdf8';
 *         $c->lineWidth = 4;
 *         $c->beginPath();
 *         foreach ($s->samples as $i => $v) {
 *             $c->lineTo($i * $c->width() / 100, $c->height() * (1 - $v));
 *         }
 *         $c->stroke();
 *         $c->fillText('load', 1, 1);
 *     }, class: 'h-10')
 *
 * The drawing API is phasync/canvas's `Context` (`beginPath`, `moveTo`, `lineTo`, `arc`,
 * `bezierCurveTo`, `rect`, `fill`, `stroke`, `fillRect`, `createLinearGradient`, `drawImage`,
 * `save`/`restore`, `translate`/`scale`/`rotate`, `globalAlpha` and the rest; see its README),
 * given as a `CanvasContext`: in cells as units, with text in real characters
 * (see `CanvasContext`). The closure is called whenever the canvas renders: when its size
 * changes, when a `State` it read changes (so a graph of `$s->samples` is live), and when a new
 * closure comes with the parent's render. A drawing that comes out the same as the last one
 * writes nothing.
 *
 * **Markers** (`marker:`) choose what a cell shows:
 *
 * - `braille`: 2x4 dots per cell (U+2800-U+28FF), the finest on every terminal; a cell's dots
 *   share one colour (the one most of them have);
 * - `quarter` (2x2), `half` (1x2: a cell shows two colours, top and bottom) and `block` (1x1):
 *   block characters, coarser, with true colour per part;
 * - `pixels`: the drawing as an image in the real terminal's pixels (Kitty graphics or iTerm2
 *   inline images, see `TerminalDevice::images()`); on a terminal without images it is drawn
 *   as `quarter` instead;
 * - `auto` (the default): `pixels` where the terminal shows images, else `braille`.
 *
 * It draws on a raster of the canvas's cells times the cell size in pixels (`Surface::cellSize()`,
 * 8x16 unless the terminal says otherwise), the same for every marker, which the glyph markers
 * scale down to their dots: so a drawing covers the same cells on every marker.
 *
 * **Pointer**: with `onMouse:`, the mouse over the canvas is followed (motion without a button
 * too, mode 1003) and each `Mouse` carries its position in the canvas's units in `canvasX` and
 * `canvasY` (the middle of the cell: `precise` false). `onMouse` is called as for any element
 * (`fn(Mouse $m, Canvas $canvas)`; a press, drag or release is taken unless it returns false,
 * the wheel only when it returns true).
 *
 * **Size**: it fills what the layout gives it (`width:`/`height:` and the `w-`/`h-` classes
 * size it). **Style**: `Canvas`; its colour (the theme's accent by default, `text-` classes) is
 * the starting `fillStyle` and `strokeStyle`, its background (`bg-` classes) the cells' where
 * nothing is drawn. Needs `ext-gd` or `ext-imagick` (phasync/canvas draws with them).
 */
final class Canvas extends Component
{
    /** What the last render wrote, to write nothing when a drawing comes out the same. */
    private string $drawn = '';
    private bool $listening = false;

    /**
     * @param (\Closure(CanvasContext, Canvas): mixed)|null $draw draws the picture (a prop: a new closure with every render is fine)
     * @param string $marker `auto`, `braille`, `quarter`, `half`, `block` or `pixels`
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public ?\Closure $draw = null,
        public string $marker = 'auto',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
        ?\Closure $onMouse = null,
    ) {
        if (!\in_array($marker, ['auto', 'braille', 'quarter', 'half', 'block', 'pixels'], true)) {
            throw new \InvalidArgumentException("Unknown Canvas marker '$marker': use auto, braille, quarter, half, block or pixels");
        }
        $this->with($key, $id, $class, $style, $width, $height, onMouse: $onMouse);
    }

    /**
     * The marker it draws with now (`auto` resolved): `pixels` when asked for (or by `auto`) and
     * the terminal shows images, `quarter` for `pixels` on one that does not, `braille` for
     * `auto` there; the other markers as asked.
     */
    public function markerInUse(): string
    {
        $images = ($this->device()?->images() ?? 'none') !== 'none';
        return match ($this->marker) {
            'auto' => $images ? 'pixels' : 'braille',
            'pixels' => $images ? 'pixels' : 'quarter',
            default => $this->marker,
        };
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, $rows];
    }

    protected function wantsPointer(): bool
    {
        return $this->attribute('onMouse') !== null;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        $handler = $this->attribute('onMouse');
        if ($handler === null) {
            return false;
        }
        $result = $this->call($handler, $mouse->inCanvas($mouse->x + 0.5, $mouse->y + 0.5, false), $this);
        return $mouse->isWheel() ? $result === true : $result !== false;
    }

    protected function render(): null
    {
        if (!$this->listening && ($device = $this->device()) !== null) {
            // (the terminal tells whether it shows images a moment after start: draw again then)
            $this->listening = true;
            $device->whenProbed(fn() => $this->isMounted() && $this->requestRender());
        }
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        if ($cols < 1 || $rows < 1) {
            return null;
        }
        $marker = $this->markerInUse();
        [$cw, $ch] = $this->surface->cellSize();
        $resolution = $marker === 'pixels' ? [$cw, $ch] : Dots::RESOLUTION[$marker];
        $base = $this->style()->text;
        $backend = \phasync\Canvas\Canvas::$backendClass ?? (\extension_loaded('gd') ? Gd::class : Imagick::class);
        $raster = new $backend($cols * $cw, $rows * $ch);
        $context = new CanvasContext($raster, $cols, $rows, [$cw, $ch], $resolution, $marker, $base->fg);
        if ($this->draw !== null) {
            ($this->draw)($context, $this);
        }
        $blank = Style::of(Color::DEFAULT, $base->bg);
        $out = Ansi::style($blank);
        if ($marker === 'pixels') {
            $out .= "\e[2J\e[H" . Ansi::image($raster->toPng(), $cols, $rows);
        } else {
            [$rx, $ry] = $resolution;
            foreach (Dots::cells($marker, $raster->toDots($cols * $rx, $rows * $ry)) as $y => $cells) {
                $out .= Ansi::moveTo(0, $y);
                $pen = $blank;
                foreach ($cells as [$glyph, $fg, $bg]) {
                    $style = Style::of($fg, $bg === Color::DEFAULT ? $base->bg : $bg);
                    if ($style !== $pen) {
                        $out .= Ansi::style($style);
                        $pen = $style;
                    }
                    $out .= $glyph;
                }
            }
        }
        foreach ($context->texts as [$col, $row, $text, $color]) {
            if ($row < 0 || $row >= $rows || $col >= $cols || $text === '') {
                continue;
            }
            if ($col < 0) {
                $text = Text::truncate(mb_substr($text, -$col), $cols, '');
                $col = 0;
            }
            $out .= Ansi::moveTo($col, $row) . Ansi::style(Style::of($color, $base->bg)) . Text::truncate($text, $cols - $col, '');
        }
        $out .= "\e[0m";
        if ($out !== $this->drawn) {
            $this->surface->write("\e[?25l\e[?7l" . $out);
            $this->drawn = $out;
        }
        return null;
    }
}
