<?php

namespace phasync\Tui\Internal;

use phasync\Tui\Layout;
use phasync\Tui\Surface;
use phasync\Tui\Viewport;

/**
 * @internal The layout of a `ScrollView`: its content surface, as wide as the view and as high
 * as the content needs, in one window that shows part of it; the content's own layout on it.
 */
final class ScrollLayout implements Layout
{
    /**
     * @param \Closure(int $cols): int     $height how high the content is at a width
     * @param \Closure(Viewport): void     $follow sets the scroll position after the layout
     */
    public function __construct(
        private readonly Surface $content,
        private readonly Layout $inner,
        private readonly \Closure $height,
        private readonly \Closure $follow,
    ) {
    }

    public function arrange(Surface $host): void
    {
        $cols = $host->cols();
        $rows = $host->rows();
        $h = max($rows, ($this->height)($cols));
        if ($this->content->cols() !== $cols || $this->content->rows() !== $h) {
            $this->content->resize($cols, $h);
        }
        $view = $host->host($this->content, 0, 0, $cols, $rows);
        $view->setFit(false)->setFollowOnInput(false);     // (where it shows is the ScrollView's)
        $view->move(0, 0)->resize($cols, $rows);
        if ($view->hasBorder()) {
            $view->setBorder(false);
        }
        $this->content->setLayout($this->inner);
        ($this->follow)($view);
    }
}
