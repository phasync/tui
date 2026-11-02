<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * A horizontal line across the width, optionally with a title: `── Settings ─────────`.
 *
 *     new Rule('Settings')
 *     new Rule(char: '═')
 *
 * `$title` takes markup; `$align` is `left`, `center` or `right`. One row.
 *
 * **Style**: `Rule` (the line), the part `Rule::title`.
 */
class Rule extends Component
{
    /**
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $title = '',
        public string $align = 'left',
        public string $char = '─',
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        if (Text::width($char) !== 1) {
            throw new \InvalidArgumentException('Rule $char must be one character, one column wide');
        }
        $this->with($key, $id, $class, $style);
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $line = Ansi::style($this->style()->text);
        if ($this->title === '') {
            $line .= str_repeat($this->char, $cols);
        } else {
            $title = Text::truncate($this->markup($this->title, 'title'), max(0, $cols - 4));
            $room = max(0, $cols - 2 - min(Text::width(Markup::plain($this->title)), max(0, $cols - 4)));
            $left = match ($this->align) {
                'center' => intdiv($room, 2),
                'right'  => max(0, $room - 2),
                default  => min(2, $room),
            };
            $line .= str_repeat($this->char, $left) . ' ' . Ansi::style($this->style('title')->text) . $title
                . Ansi::style($this->style()->text) . ' ' . str_repeat($this->char, $room - $left);
        }
        $this->surface->write("\e[?25l\e[2J\e[H" . $line);
        return null;
    }
}
