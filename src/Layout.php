<?php

namespace phasync\Tui;

/**
 * Arranges the windows of a host surface: which surfaces it hosts, where, and how large.
 *
 *     $root->setLayout(Split::horizontal(separators: true)->add($left)->add($right, '30%'));
 *
 * `Split` puts surfaces side by side or on top of each other; `Stack` layers them (a modal
 * dialog over the rest). Layouts nest by giving a hosted surface a layout of its own. Sizes are
 * written the same everywhere: an int is a number of cells, `'25%'` a share of the host, `'2fr'`
 * a weight in the space that is left (as in CSS grids).
 *
 * Implement it for an arrangement of your own: `arrange()` hosts each surface it places with
 * `$host->host($surface)` and sets the viewport's position and size.
 */
interface Layout
{
    /**
     * Place the windows of $host for its current size. The host calls it when the layout is set
     * and whenever the host is resized; surfaces it does not host during the call are unhosted.
     */
    public function arrange(Surface $host): void;
}
