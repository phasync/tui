<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Layout;
use phasync\Tui\Surface;

/**
 * @internal The layout of a root component with overlays open: its own layout (or none, when it
 * draws itself), then every overlay's window at the place last decided for it, the later ones
 * above the earlier ones.
 */
final class OverlayLayout implements Layout
{
    /**
     * @param \Closure(): list<\phasync\Tui\Overlay> $overlays the open overlays, in the order they opened
     */
    public function __construct(
        public readonly ?Layout $inner,
        private readonly \Closure $overlays,
    ) {
    }

    public function arrange(Surface $host): void
    {
        $this->inner?->arrange($host);
        $order = [];
        foreach (($this->overlays)() as $overlay) {
            $rect = $overlay->rect;
            if ($rect === null) {
                continue;
            }
            // inside the host also before the next placement (a resize arrives first)
            $w = min($rect->width, $host->cols());
            $h = min($rect->height, $host->rows());
            $x = max(0, min($rect->x, $host->cols() - $w));
            $y = max(0, min($rect->y, $host->rows() - $h));
            $surface = $overlay->content->surface;
            $view = $host->host($surface, $x, $y, $w, $h);
            $style = $overlay->content->style();
            if ($style->border !== false) {
                $view->setBorder($style->border, $style->borderStyle, $style->borderStyle);
            } elseif ($view->hasBorder()) {
                $view->setBorder(false);
            }
            $view->move($x, $y)->resize($w, $h)->setFit(true);
            $order[] = $surface;
        }
        // the overlays above everything, in their order
        $views = $host->viewports();
        $topmost = array_map(fn($v) => $v->source, \array_slice($views, \count($views) - \count($order)));
        if ($topmost !== $order) {
            foreach ($order as $surface) {
                $host->raise($surface);
            }
        }
    }
}
