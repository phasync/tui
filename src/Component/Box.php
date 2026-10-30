<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Layout;
use phasync\Tui\Stack;
use phasync\Tui\Style;

/**
 * A border with a title around another component.
 *
 *     $box = $this->child('files', fn() => new Box(new Log(), 'files', focusStyle: Style::of(Color::CYAN)));
 *     $box->content()->append("...\n");
 *
 * The border is drawn by the box's surface around the window that shows the content (see
 * `Viewport::setBorder()`), in $focusStyle while the content has the focus. The content gets
 * the area inside the border, and the focus when the box gets it.
 */
final class Box extends Component
{
    public function __construct(
        private readonly Component $content,
        private string $title = '',
        private readonly ?Style $style = null,
        private readonly ?Style $focusStyle = null,
    ) {
    }

    /** The component inside the border. */
    public function content(): Component
    {
        return $this->content;
    }

    /** Change the title in the top border. */
    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->requestRender();
    }

    /** The title. */
    public function title(): string
    {
        return $this->title;
    }

    protected function render(): Layout
    {
        $content = $this->child('content', fn() => $this->content);
        $this->surface->host($content->surface)->setBorder(true, $this->style, $this->focusStyle)->setTitle($this->title);
        return (new Stack())->add($content->surface);
    }
}
