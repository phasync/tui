<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Text;

/**
 * Something is going on: a spinning sign and a label, animated by itself while it is shown (no
 * coroutine of yours needed).
 *
 *     $this->state->busy ? new Spinner('saving') : new Text('saved')
 *
 * `$label` is a prop and takes markup (`'saving <dim>report.pdf</dim>'`, see `Markup`). `$frames` are the signs it cycles through, one every `$interval` seconds
 * (default: a braille dot spinner, 80 ms). One row.
 *
 * **Style**: `Spinner` (the label), the part `Spinner::sign` (the default theme: the accent
 * colour).
 */
class Spinner extends Component
{
    public const DOTS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    public const LINE = ['-', '\\', '|', '/'];

    private int $frame = 0;

    /**
     * @param string       $label    after the sign
     * @param list<string> $frames   the signs, in order (`Spinner::DOTS`, `Spinner::LINE`, or yours)
     * @param float        $interval seconds per sign
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $label = '',
        public array $frames = self::DOTS,
        public float $interval = 0.08,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        if ($frames === []) {
            throw new \InvalidArgumentException('A Spinner needs at least one frame');
        }
        $this->with($key, $id, $class, $style);
    }

    /** The sign shown now (its position in `$frames`). */
    public function frame(): int
    {
        return $this->frame;
    }

    protected function run(): void
    {
        while ($this->sleep($this->interval)) {
            $this->frame = ($this->frame + 1) % \count($this->frames);
        }
    }

    public function measure(int $cols, int $rows): ?array
    {
        $sign = max(array_map(Text::width(...), $this->frames));
        return [min($cols, $sign + ($this->label === '' ? 0 : 1 + Text::width(Markup::plain($this->label)))), 1];
    }

    protected function render(): null
    {
        $sign = $this->frames[$this->frame % \count($this->frames)];
        $line = Ansi::style($this->style('sign')->text) . $sign . Ansi::style($this->style()->text) . ($this->label === '' ? '' : ' ' . $this->markup($this->label));
        $this->surface->write("\e[?25l\e[2J\e[H" . Text::truncate($line, $this->surface->cols()));
        return null;
    }
}
