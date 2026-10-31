<?php

namespace phasync\Tui\Component;

use fiber\Deferred;
use phasync\Tui\Component;
use phasync\Tui\ComputedStyle;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;

/**
 * A modal dialog with a message (or any element tree: a form) and buttons, whose answer is an
 * awaitable: open it with `Component::dialog()` from the component it belongs to.
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
 * `$buttons` maps each label to the answer it gives, in order; they are `Button`s. The focus
 * starts on the first field of the content, or on the first button when the content has none.
 * Tab and Shift-Tab go through the content's fields first and the buttons after, Left/Right
 * move between the buttons. Enter presses the focused button (in a field: the first button),
 * Escape cancels with the `$cancel` answer (null by default), which is also the answer when the
 * dialog ends without one (the component that opened it was disposed, the program quit).
 *
 * **Size**: as large as its content needs (a message wraps at 60 columns), never larger than
 * the screen: a dialog that does not fit is shrunk and its content scrolls (it is in a
 * `ScrollView`; Page Up/Page Down and the wheel scroll it). `with(width: 50, height: 12)`
 * fixes the size.
 *
 * **Subclass it** for dialogs of your own (see `Confirm`): `accept($answer)` decides whether a
 * button's answer closes the dialog (a form dialog checks its fields there and stays open with
 * its error messages), `close($answer)` ends it from code.
 *
 * **Style**: `Dialog` (the default theme: a round border in the accent colour, padding `0 1`),
 * the part `Dialog::title`; its buttons are styled as `Button`.
 */
class Dialog extends Component
{
    private ?Deferred $answer = null;

    /**
     * @param string               $title   shown at the top, in the `Dialog::title` style
     * @param string|Element       $content the message (wrapped), or an element tree
     * @param array<string, mixed> $buttons the answer of each button, by label, in order
     * @param mixed                $cancel  the answer for Escape, and when it ends unanswered
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

    /**
     * Decide whether $answer, the answer of a pressed button, closes the dialog: return false
     * to keep it open (a form whose fields are not valid yet; show why in the dialog). Not asked
     * for the cancel answer (Escape) nor by `close()`. The default accepts every answer.
     */
    protected function accept(mixed $answer): bool
    {
        return true;
    }

    /** Close the dialog with $answer (without asking `accept()`): the awaitable resolves, the dialog ends, the focus goes back. */
    public function close(mixed $answer = null): void
    {
        if (!$this->result()->isSettled()) {
            $this->result()->resolve($answer);
        }
        $this->emit('close', $this);
        $overlay = $this->inOverlay();
        $overlay !== null ? $overlay->close($answer) : $this->dispose();
    }

    /** Ended without an answer (its opener went, the program quit): the awaitable gets the `$cancel` answer. */
    protected function unmount(): void
    {
        if (!$this->result()->isSettled()) {
            $this->result()->resolve($this->cancel);
        }
    }

    protected function mount(): void
    {
        // (measuring makes the fields and buttons, so one can have the focus before the first render)
        $this->measure($this->surface->cols(), $this->surface->rows());
        foreach ($this->fields($this->scroll()) as $field) {
            $field->focus();
            return;
        }
        $this->button(0)?->focus();
    }

    /** A dialog passes the focus on to its fields and buttons. */
    protected function canFocus(): bool
    {
        return false;
    }

    protected function bindings(): array
    {
        return [
            'tab' => 'nextField', 'shift+tab' => 'previousField',
            'left' => 'previousButton', 'right' => 'nextButton',
            'enter' => ['press', 'choose'], 'escape' => ['cancel', 'cancel'],
            'pageup' => 'scrollUp', 'pagedown' => 'scrollDown',
        ];
    }

    /** Tab: to the next field or button (the content's first, then the buttons, around). */
    public function nextField(): bool
    {
        $moved = $this->surface->focusNext();
        $this->markDirty();     // (the content's ScrollView follows the focus)
        return $moved;
    }

    /** Shift-Tab: to the field or button before. */
    public function previousField(): bool
    {
        $moved = $this->surface->focusNext(true);
        $this->markDirty();
        return $moved;
    }

    /** Focus the button before the focused one; false when the focus is not on a button, or on the first. */
    public function previousButton(): bool
    {
        $i = $this->focusedButton();
        return $i !== null && $i > 0 && $this->focusButton($i - 1);
    }

    /** Focus the button after the focused one; false when the focus is not on a button, or on the last. */
    public function nextButton(): bool
    {
        $i = $this->focusedButton();
        return $i !== null && $i < \count($this->buttons) - 1 && $this->focusButton($i + 1);
    }

    /** Press the focused button (from a field: the first): close with its answer if `accept()` takes it. */
    public function press(): void
    {
        $this->choose($this->focusedButton() ?? 0);
    }

    /** Close with the `$cancel` answer. */
    public function cancel(): void
    {
        $this->close($this->cancel);
    }

    /** Scroll the content up a page; false when it is at the top. */
    public function scrollUp(): bool
    {
        return $this->scroll()->pageUp();
    }

    /** Scroll the content down a page; false when it is at the bottom. */
    public function scrollDown(): bool
    {
        return $this->scroll()->pageDown();
    }

    private function choose(int $i): void
    {
        $answers = array_values($this->buttons);
        if ($answers === []) {
            return;
        }
        $answer = $answers[$i] ?? $answers[0];
        if ($this->accept($answer)) {
            $this->close($answer);
        }
    }

    private function focusButton(int $i): bool
    {
        $button = $this->button($i);
        if ($button === null) {
            return false;
        }
        $button->focus();
        return true;
    }

    private function focusedButton(): ?int
    {
        for ($i = 0; $i < \count($this->buttons); $i++) {
            if ($this->button($i)?->hasFocus()) {
                return $i;
            }
        }
        return null;
    }

    private function button(int $i): ?Button
    {
        $labels = array_keys($this->buttons);
        if (!isset($labels[$i])) {
            return null;
        }
        return $this->child("button$i", fn() => new Button((string) $labels[$i], fn() => $this->choose($i)));
    }

    private function scroll(): ScrollView
    {
        $content = \is_string($this->content) ? new Text($this->content) : $this->content;
        $scroll = $this->child('scroll', fn() => new ScrollView($content));
        if (\is_string($this->content) ? !($scroll->content instanceof Text && $scroll->content->plain() === $this->content) : $scroll->content !== $content) {
            $scroll->content = $content;
            $scroll->markDirty();
        }
        return $scroll;
    }

    /** @return iterable<Component> the components in $in that take keys, in order */
    private function fields(Component $in): iterable
    {
        foreach ($in->children() as $child) {
            if ($child->canFocus()) {
                yield $child;
            }
            yield from $this->fields($child);
        }
    }

    /** As large as the title, the content (a message wrapped at 60 columns) and the buttons need; or the size given with `with()`. */
    public function measure(int $cols, int $rows): ?array
    {
        [, $r, , $l] = $this->style()->padding;
        $width = $this->attribute('width');
        $height = $this->attribute('height');
        if (\is_int($width) && \is_int($height)) {
            return [min($cols, $width), min($rows, $height)];
        }
        $room = \is_int($width) ? min($cols, $width) : (\is_string($this->content) ? min($cols, 60 + $l + $r) : $cols);
        [$w, $h] = $this->measureElement($this->tree(), $room, $rows) ?? [$room, $rows];
        return [\is_int($width) ? $room : min($cols, $w), \is_int($height) ? min($rows, $height) : $h];
    }

    protected function render(): Element
    {
        return $this->tree();
    }

    private function tree(): Element
    {
        $rows = [];
        if ($this->title !== '') {
            $rows[] = new Text($this->title, wrap: false, style: self::inline($this->style('title')));
            $rows[] = new Text('', height: 1);
        }
        $rows[] = $this->scroll();
        if ($this->buttons !== []) {
            $rows[] = new Text('', height: 1);
            $buttons = [];
            for ($i = 0; $i < \count($this->buttons); $i++) {
                $buttons[] = $this->button($i);
            }
            $rows[] = new Row($buttons, gap: 2, height: 1);
        }
        return new Box(new Column($rows), style: ['border' => 'none', 'padding' => implode(' ', $this->style()->padding)]);
    }

    /** A computed style as inline properties, for the title's text. */
    private static function inline(ComputedStyle $c): array
    {
        $s = $c->text;
        $out = ['color' => $s->fg, 'background' => $s->bg];
        foreach (['bold' => 1, 'dim' => 2, 'italic' => 4, 'underline' => 8, 'inverse' => 32, 'strike' => 128] as $name => $bit) {
            $out[$name] = ($s->attrs & $bit) !== 0;
        }
        return $out;
    }
}
