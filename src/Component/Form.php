<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text;

/**
 * Fields to fill in and submit: the fields are components in an element tree (`Editor`,
 * `Checkbox`, `Select`, or any `Field` of yours), named by their `id`.
 *
 *     new Form(
 *         new Column([
 *             new Text('Name'),
 *             (new Editor(maxLines: 1))->with(id: 'name'),
 *             (new Checkbox('Subscribe'))->with(id: 'subscribe'),
 *             new Row([new Button('Save'), new Button('Cancel', onPress: fn() => $this->cancel())], gap: 2, height: 1),
 *         ]),
 *         validate: fn(array $v) => $v['name'] === '' ? ['name' => 'Give a name'] : [],
 *         onSubmit: fn(array $v) => $this->save($v),
 *     )
 *
 * **Keys**: Tab and Shift-Tab go from field to field in the order of the tree (buttons
 * included), around; inside a component that moves the focus with Tab itself (a `Dialog`),
 * from the last field on to what follows the form there (the dialog's buttons). Enter in a
 * one-line field, or on a `Button` without `onPress`, submits; Alt-Enter submits from anywhere
 * (a multi-line `Editor` takes Enter for its lines).
 *
 * **Submitting**: `values()` (by field id) goes to `$validate`, which returns the errors by
 * field id (none: valid). With errors, each field in error is in the state `:invalid` for the
 * stylesheet (the default theme: the error colour), the messages are shown under the content,
 * the first field in error gets the focus, and `$onSubmit` is not called. Without errors,
 * `$onSubmit($values, $form)` is called and the form emits the event `submit` (passing
 * itself). `$content` is a prop: a form placed with `new` on every render keeps what was
 * typed (its fields are matched by place or `key:`).
 *
 * **In a `Dialog`** (`new Dialog('Add', new Form(...), ['Add' => 'add', 'Cancel' => null])`):
 * Enter in a field submits the form and, when it is valid, answers the dialog with its first
 * button's answer; a button whose answer is not the dialog's cancel answer submits the form
 * first and answers only when it is valid; the cancel button and Escape close the dialog
 * without validating. The dialog's `accept()` is asked after the form was valid (a `submit()`
 * there does nothing). **Where to read the values**: in `$onSubmit` (it runs before the dialog
 * closes), in the dialog's `accept()` with `values()`, or after the dialog closed with
 * `values()`, which then gives those of the last submit (the fields are gone):
 *
 *     $form = new Form($fields, validate: ...);
 *     if (fiber\await($this->dialog(new Dialog('Add', $form, ['Add' => 'add', 'Cancel' => null]))) === 'add') {
 *         $this->add($form->values());
 *     }
 *
 * **Style**: `Form`, the part `Form::error` for the messages.
 */
class Form extends Component
{
    /** @var array<string, string> the errors of the last submit, by field id */
    private array $errors = [];
    /** @var array<string, mixed>|null the values of the last submit */
    private ?array $last = null;
    /** A Dialog's accept() runs: submit() does nothing. */
    private bool $held = false;

    /**
     * @param Element $content the fields (and anything else) in an element tree
     * @param (\Closure(array<string, mixed> $values, Form $form): void)|null $onSubmit a submit without errors
     * @param (\Closure(array<string, mixed> $values, Form $form): array<string, string>)|null $validate the errors by field id
     */
    public function __construct(
        public Element $content,
        private readonly ?\Closure $onSubmit = null,
        private readonly ?\Closure $validate = null,
    ) {
    }

    protected function bindings(): array
    {
        return ['tab' => 'nextField', 'shift+tab' => 'previousField', 'enter' => 'enter', 'alt+enter' => 'enter'];
    }

    /** Enter (and Alt-Enter): submit (see `submit()`); the key is taken also when the values are not valid. */
    public function enter(): void
    {
        $this->submit();
    }

    /** A form passes the focus on to its fields. */
    protected function canFocus(): bool
    {
        return false;
    }

    /**
     * Tab: the next field (around; inside a component that binds Tab itself, false at the last
     * field, so that component's Tab goes on from there).
     */
    public function nextField(): bool
    {
        return $this->focusNext(!$this->insideTabOrder());
    }

    /** Shift-Tab: the field before (as `nextField()`, the other way). */
    public function previousField(): bool
    {
        return $this->focusPrevious(!$this->insideTabOrder());
    }

    /** True if a component around the form moves the focus with Tab too: the form is part of its order. */
    private function insideTabOrder(): bool
    {
        for ($c = $this->parent(); $c !== null; $c = $c->parent()) {
            if (isset($c->bindingsInForce()['tab'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fields with an `id`, by id, in the order of the tree.
     *
     * @return array<string, Field&Component>
     */
    public function fields(): array
    {
        $out = [];
        $walk = function (Component $c) use (&$walk, &$out): void {
            foreach ($c->children() as $child) {
                if ($child instanceof Field && \is_string($id = $child->attribute('id'))) {
                    $out[$id] ??= $child;
                }
                $walk($child);
            }
        };
        $walk($this);
        return $out;
    }

    /**
     * Each field's `value()`, by id. Once the form has ended (the dialog it was in closed, so
     * its fields are gone), the values of its last `submit()`: what a coroutine that awaited
     * the dialog reads.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        if (!$this->isMounted() && $this->last !== null) {
            return $this->last;
        }
        return array_map(fn(Field $f) => $f->value(), $this->fields());
    }

    /** @internal Hold submit() (a Dialog's accept() runs). */
    public function hold(bool $on): void
    {
        $this->held = $on;
    }

    /** @return array<string, string> the errors of the last submit, by field id */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Validate, then call `$onSubmit` (see the class description). True when it was valid.
     * While a `Dialog` the form is in asks its `accept()` (the form was submitted just before,
     * and valid), it does nothing and returns true.
     */
    public function submit(): bool
    {
        if ($this->held) {
            return $this->errors === [];
        }
        $values = $this->values();
        $this->last = $values;
        $errors = $this->validate === null ? [] : ($this->call($this->validate, $values, $this) ?? []);
        $this->errors = array_map('strval', $errors);
        $first = null;
        foreach ($this->fields() as $id => $field) {
            $field->setStyleState('invalid', isset($this->errors[$id]));
            if (isset($this->errors[$id])) {
                $first ??= $field;
            }
        }
        $this->markDirty();
        if ($first !== null) {
            $first->focus();
            return false;
        }
        $this->call($this->onSubmit, $values, $this);
        $this->emit('submit', $this);
        return true;
    }

    protected function mount(): void
    {
        // (measuring makes the fields, so the first can have the focus before the first render)
        $this->measure($this->surface->cols(), $this->surface->rows());
        foreach ($this->fields() as $field) {
            $field->focus();
            return;
        }
    }

    public function measure(int $cols, int $rows): ?array
    {
        return $this->measureElement($this->tree(), $cols, $rows);
    }

    protected function render(): Element
    {
        return $this->tree();
    }

    private function tree(): Element
    {
        if ($this->errors === []) {
            return new Column([$this->content]);
        }
        $lines = [];
        foreach ($this->errors as $message) {
            $lines[] = $message;
        }
        return new Column([$this->content, new Text(implode("\n", $lines), class: 'form-error', style: $this->style('error')->properties())]);
    }

}
