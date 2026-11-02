<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * A hatched stand-in for a widget that is not written yet, with its label and size in the middle:
 * for sketching a layout.
 *
 *     new Column([new Placeholder('sidebar', class: 'w-20'), new Placeholder('content', height: '1fr')])
 *
 * It fills the room it is given with `╱` and writes ` label 20x8 ` (the label, then its width
 * and height in cells) in the middle, cut when the room is small. `$label` (markup allowed)
 * is a prop. Not interactive.
 *
 * **Style**: `Placeholder` (dimmed by the default theme), the part `Placeholder::label`.
 */
class Placeholder extends Component
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $label = 'Placeholder',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        int|string|null $width = null,
        int|string|null $height = null,
    ) {
        $this->with($key, $id, $class, $style, $width, $height);
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, $rows];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $rows = $this->surface->rows();
        $text = ' ' . $this->markup($this->label, 'label') . ' ' . $cols . 'x' . $rows . ' ';
        $text = Text::truncate($text, $cols, '');
        $pad = intdiv($cols - Text::width($text), 2);
        $middle = intdiv($rows - 1, 2);
        $hatch = Ansi::style($this->style()->text);
        $out = '';
        for ($y = 0; $y < $rows; $y++) {
            $out .= $hatch . ($y === $middle
                ? str_repeat('╱', $pad) . Ansi::style($this->style('label')->text) . $text . $hatch . str_repeat('╱', $cols - $pad - Text::width($text))
                : str_repeat('╱', $cols)) . ($y < $rows - 1 ? "\r\n" : '');
        }
        $this->surface->write("\e[?25l\e[?7l\e[2J\e[H" . $out);
        return null;
    }
}
