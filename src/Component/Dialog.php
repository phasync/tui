<?php

namespace phasync\Tui\Component;

use fiber\Deferred;
use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Box;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Row;
use phasync\Tui\Element\Text;
use phasync\Tui\Rect;

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
 * **A `Form` as the content** (or in it) is validated before an answer closes the dialog:
 * Enter in a field submits the form and, when it is valid, answers with the first button's
 * answer; any other button than the cancel one submits it first and answers only when it is
 * valid (the messages show in the form); the cancel button and Escape do not validate.
 *
 * **Subclass it** for dialogs of your own (see `Confirm`): `accept($answer)` decides whether a
 * button's answer closes the dialog (a form dialog checks its fields there and stays open with
 * its error messages; with a `Form` inside, after the form was valid), `close($answer)` ends
 * it from code.
 *
 * **Style**: `Dialog` (the default theme: a round border in the accent colour, padding `0 1`),
 * the part `Dialog::title`; its buttons are styled as `Button`.
 */
class Dialog extends Component
{
    private ?Deferred $answer = null;
    /** @var \WeakMap<Form, true>|null the forms in the content whose `submit` event is listened to */
    private ?\WeakMap $forms = null;
    /** True while a button submits the forms: their `submit` event is not an answer then. */
    private bool $submitting = false;

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
     *
     * With a `Form` in the content, it is asked after the form was submitted and found valid:
     * read the values with `$form->values()` here (or take them in the form's `onSubmit`, which
     * ran just before); calling `$form->submit()` again in it does nothing and returns true.
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
        // (measuring the tree makes the fields and buttons, so one can have the focus before the
        // first render; also when a size given with with() makes measure() skip the tree)
        $this->measureElement($this->tree(), max(1, $this->surface->cols()), max(1, $this->surface->rows()));
        $this->forms();
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
        $moved = $this->focusNext();
        $this->markDirty();     // (the content's ScrollView follows the focus)
        return $moved;
    }

    /** Shift-Tab: to the field or button before. */
    public function previousField(): bool
    {
        $moved = $this->focusPrevious();
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

    /** Answer with button $i's answer: the forms in the content first (unless one just submitted), then `accept()`. */
    private function choose(int $i, bool $submitted = false): void
    {
        $answers = array_values($this->buttons);
        if ($answers === []) {
            return;
        }
        $answer = \array_key_exists($i, $answers) ? $answers[$i] : $answers[0];     // (an answer may be null)
        if (!$submitted && $answer !== $this->cancel) {
            $this->submitting = true;
            try {
                foreach ($this->forms() as $form) {
                    if (!$form->submit()) {
                        return;     // its messages show; the field in error has the focus
                    }
                }
            } finally {
                $this->submitting = false;
            }
        }
        // the forms were submitted and are valid: a submit() in accept() does nothing (see Form::submit())
        $forms = $this->forms();
        foreach ($forms as $form) {
            $form->hold(true);
        }
        try {
            $accepted = $this->accept($answer);
        } finally {
            foreach ($forms as $form) {
                $form->hold(false);
            }
        }
        if ($accepted) {
            $this->close($answer);
        }
    }

    /**
     * The forms in the content, in order; a form submitted by Enter in one of its fields (its
     * `submit` event) answers with the first button.
     *
     * @return list<Form>
     */
    private function forms(): array
    {
        $this->forms ??= new \WeakMap();
        $found = [];
        $walk = function (Component $c) use (&$walk, &$found): void {
            foreach ($c->children() as $child) {
                if ($child instanceof Form) {
                    $found[] = $child;
                }
                $walk($child);
            }
        };
        $walk($this->scroll());
        foreach ($found as $form) {
            if (!isset($this->forms[$form])) {
                $this->forms[$form] = true;
                $form->on('submit', function (): void {
                    if (!$this->submitting && $this->isMounted()) {
                        $this->choose(0, true);
                    }
                });
            }
        }
        return $found;
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

    /**
     * @internal The row of the buttons on the screen (`Surface::area()` coordinates), null
     * without buttons or before they show: what an overlay opened inside the dialog (a
     * dropdown's list) leaves uncovered when it can.
     */
    public function buttonsArea(): ?Rect
    {
        $area = null;
        for ($i = 0; $i < \count($this->buttons); $i++) {
            $b = $this->child("button$i")?->surface->area();
            if ($b === null || $b->isEmpty()) {
                continue;
            }
            $area = $area === null ? $b : new Rect(min($area->x, $b->x), min($area->y, $b->y),
                max($area->x + $area->width, $b->x + $b->width) - min($area->x, $b->x),
                max($area->y + $area->height, $b->y + $b->height) - min($area->y, $b->y));
        }
        return $area;
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
        $tree = $this->tree();
        $this->forms();     // (the content may have changed)
        return $tree;
    }

    private function tree(): Element
    {
        $rows = [];
        if ($this->title !== '') {
            $rows[] = new Text($this->title, wrap: false, style: $this->style('title')->properties());
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

}
