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
 * included), around. Enter in a one-line field, or on a `Button` without `onPress`, submits;
 * Alt-Enter submits from anywhere (a multi-line `Editor` takes Enter for its lines).
 *
 * **Submitting**: `values()` (by field id) goes to `$validate`, which returns the errors by
 * field id (none: valid). With errors, each field in error is in the state `:invalid` for the
 * stylesheet (the default theme: the error colour), the messages are shown under the content,
 * the first field in error gets the focus, and `$onSubmit` is not called. Without errors,
 * `$onSubmit($values, $form)` is called. `$content` is a prop: a form placed with `new` on every
 * render keeps what was typed (its fields are matched by place or `key:`).
 *
 * **Style**: `Form`, the part `Form::error` for the messages.
 */
class Form extends Component
{
    /** @var array<string, string> the errors of the last submit, by field id */
    private array $errors = [];

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
        return ['tab' => 'nextField', 'shift+tab' => 'previousField', 'enter' => 'submit', 'alt+enter' => 'submit'];
    }

    /** A form passes the focus on to its fields. */
    protected function canFocus(): bool
    {
        return false;
    }

    /** Tab: the next field (around). */
    public function nextField(): bool
    {
        return $this->surface->focusNext();
    }

    /** Shift-Tab: the field before. */
    public function previousField(): bool
    {
        return $this->surface->focusNext(true);
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

    /** @return array<string, mixed> each field's `value()`, by id */
    public function values(): array
    {
        return array_map(fn(Field $f) => $f->value(), $this->fields());
    }

    /** @return array<string, string> the errors of the last submit, by field id */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Validate, then call `$onSubmit` (see the class description). True when it was valid. */
    public function submit(): bool
    {
        $values = $this->values();
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
