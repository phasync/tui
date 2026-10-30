<?php

namespace phasync\Tui\Component;

use fiber\Deferred;
use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Span;
use phasync\Tui\Element\Text;
use phasync\Tui\Mouse;
use phasync\Tui\Text as TextUtil;

/**
 * A modal dialog with a message (or any element tree) and buttons, whose answer is an
 * awaitable: open it with `Component::dialog()` from the component it covers.
 *
 *     $this->go(function () {
 *         $choice = fiber\await($this->dialog(new Dialog(
 *             title: 'Unsaved changes',
 *             content: 'Save the file before closing?',
 *             buttons: ['Save' => 'save', 'Discard' => 'discard', 'Cancel' => null],
 *         )));
 *         ...
 *     });
 *
 * `$buttons` maps each label to the answer it gives, in order; the first has the focus.
 * Left/Right and Tab/Shift-Tab move between them, Enter (or a click) presses one, Escape
 * cancels with the `$cancel` answer (null by default), which is also the answer when the
 * dialog ends without one (its component was disposed, the program quit). The dialog is as large as its content
 * needs (a message wraps at 60 columns or the screen's width); give it a size with
 * `with(width: 50, height: 12)` for element content. Subclass it for dialogs of your own
 * (see `Confirm`); `close($answer)` ends it from code.
 *
 * **Style**: `Dialog` itself (the default theme: a round border in the accent colour, padding
 * `0 1`), the parts `Dialog::title` and `Dialog::button` with state `focused`.
 */
class Dialog extends Component
{
    private ?Deferred $answer = null;
    private int $button = 0;
    /** @var list<array{int,int,int}> the buttons drawn last: index, first and last column on the button row */
    private array $hits = [];

    /**
     * @param string               $title   shown at the top, in the `Dialog::title` style
     * @param string|Element       $content the message (wrapped), or an element tree
     * @param array<string, mixed> $buttons the answer of each button, by label, in order
     * @param mixed                $cancel  the answer for Escape
     */
    public function __construct(
        public string $title = '',
        public string|Element $content = '',
        public array $buttons = ['OK' => true],
        public mixed $cancel = null,
    ) {
    }

    /** The awaitable that resolves with the answer when the dialog closes. */
    public function result(): object
    {
        return $this->answer ??= new Deferred();
    }

    /** Close the dialog with $answer: the awaitable resolves, the dialog ends, the focus goes back. */
    public function close(mixed $answer = null): void
    {
        if (!$this->result()->isSettled()) {
            $this->result()->resolve($answer);
        }
        $this->emit('close', $this);
        $this->dispose();
    }

    /** Ended without an answer (its parent went, the program quit): the awaitable gets the `$cancel` answer. */
    protected function unmount(): void
    {
        if (!$this->result()->isSettled()) {
            $this->result()->resolve($this->cancel);
        }
    }

    protected function bindings(): array
    {
        return [
            'left' => 'previousButton', 'shift+tab' => 'previousButton',
            'right' => 'nextButton', 'tab' => 'nextButton',
            'enter' => ['press', 'choose'], 'escape' => ['cancel', 'cancel'],
        ];
    }

    /** Focus the button before. */
    public function previousButton(): void
    {
        $this->button = max(0, $this->button - 1);
    }

    /** Focus the button after. */
    public function nextButton(): void
    {
        $this->button = min(max(0, \count($this->buttons) - 1), $this->button + 1);
    }

    /** Press the focused button: close with its answer. */
    public function press(): void
    {
        $answers = array_values($this->buttons);
        $this->close($answers[$this->button] ?? null);
    }

    /** Close with the `$cancel` answer. */
    public function cancel(): void
    {
        $this->close($this->cancel);
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            [$t] = $this->style()->padding;
            foreach ($this->hits as [$i, $from, $to, $row]) {
                if ($mouse->y === $row + $t && $mouse->x >= $from && $mouse->x <= $to) {
                    $this->button = $i;
                    $this->press();
                }
            }
        }
        return true;    // (modal: everything in the dialog is the dialog's)
    }

    /** The message's lines at $width columns. */
    private function lines(int $width): array
    {
        return \is_string($this->content) ? TextUtil::wrap($this->content, max(1, $width), true) : [];
    }

    private function buttonsWidth(): int
    {
        $w = 0;
        foreach (array_keys($this->buttons) as $label) {
            $w += TextUtil::width((string) $label) + 4 + 2;
        }
        return max(0, $w - 2);
    }

    public function measure(int $cols, int $rows): ?array
    {
        [$t, $r, $b, $l] = $this->style()->padding;
        $width = $this->attribute('width');
        $height = $this->attribute('height');
        if (!\is_string($this->content) && ($width === null || $height === null)) {
            [$width, $height] = [$width ?? min($cols, 60), $height ?? min($rows, 12)];
        }
        if (\is_int($width) && \is_int($height)) {
            return [min($cols, $width), min($rows, $height)];
        }
        $room = min($cols, 60) - $l - $r;
        $lines = $this->lines($room);
        $inner = max(TextUtil::width($this->title), $this->buttonsWidth(), ...array_map(TextUtil::width(...), $lines));
        $tall = ($this->title === '' ? 0 : 2) + \count($lines) + ($this->buttons === [] ? 0 : 2);
        return [min($cols, $inner + $l + $r), min($rows, $tall + $t + $b)];
    }

    protected function render(): Element
    {
        $rows = [];
        if ($this->title !== '') {
            $rows[] = new Text([new Span($this->title, style: self::props($this->style('title')))]);
            $rows[] = new Text('');
        }
        $rows[] = \is_string($this->content) ? new Text($this->content) : $this->content;
        if ($this->buttons !== []) {
            $rows[] = new Text('');
            $runs = [];
            $this->hits = [];
            $x = $this->style()->padding[3];
            $row = \count($rows);
            foreach (array_keys($this->buttons) as $i => $label) {
                $text = '[ ' . $label . ' ]';
                $runs[] = $i === 0 ? '' : '  ';
                $x += $i === 0 ? 0 : 2;
                $runs[] = new Span($text, style: self::props($this->style('button', ...($i === $this->button ? ['focused'] : []))));
                $this->hits[] = [$i, $x, $x + TextUtil::width($text) - 1, $row];
                $x += TextUtil::width($text);
            }
            $rows[] = new Text($runs, wrap: false);
        }
        // the rows of the button line: after the title and the content's lines
        $width = max(1, $this->surface->cols() - $this->style()->padding[1] - $this->style()->padding[3]);
        $buttonRow = ($this->title === '' ? 0 : 2) + \count($this->lines($width)) + 1;
        foreach ($this->hits as $k => $hit) {
            $this->hits[$k][3] = $buttonRow;
        }
        return new Box(new Column($rows), style: ['border' => 'none', 'padding' => implode(' ', $this->style()->padding)]);
    }

    /** A computed style as inline properties, for a span. */
    private static function props(\phasync\Tui\ComputedStyle $c): array
    {
        $s = $c->text;
        $out = ['color' => $s->fg, 'background' => $s->bg];
        foreach (['bold' => 1, 'dim' => 2, 'italic' => 4, 'underline' => 8, 'inverse' => 32, 'strike' => 128] as $name => $bit) {
            $out[$name] = ($s->attrs & $bit) !== 0;
        }
        return $out;
    }
}
