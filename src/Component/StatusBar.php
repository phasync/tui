<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * A one-row bar with text on the left and on the right, for the program's state and its keys.
 *
 *     $status = $this->child('status', fn() => new StatusBar('split-shells', 'Ctrl-B Tab: switch'));
 *     $status->set('2 panes', date('H:i'));
 *
 * The right text is aligned to the right edge; when both do not fit, the left one is shortened.
 * Drawn in inverse video unless a style is given. Uses the first row of its area.
 */
final class StatusBar extends Component
{
    private Style $style;

    public function __construct(private string $left = '', private string $right = '', ?Style $style = null)
    {
        $this->style = $style ?? Style::of(attrs: Style::INVERSE);
    }

    /** Change the texts. */
    public function set(string $left, string $right = ''): void
    {
        $this->left = $left;
        $this->right = $right;
        $this->requestRender();
    }

    /** The left text. */
    public function left(): string
    {
        return $this->left;
    }

    /** The right text. */
    public function right(): string
    {
        return $this->right;
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $right = Text::truncate($this->right, $cols);
        $left = Text::truncate($this->left, max(0, $cols - Text::width($right) - ($right === '' ? 0 : 1)));
        $gap = max(0, $cols - Text::width($left) - Text::width($right));
        $this->surface->write("\e[?25l\e[0m\e[2J" . Ansi::moveTo(0, 0) . Ansi::style($this->style) . $left . str_repeat(' ', $gap) . $right . "\e[0m");
        return null;
    }
}
