# Widgets

Components for the screens applications are made of, in `phasync\Tui\Component\`: `Editor`,
`Table`, `Select`, `Dialog` and `Confirm`, `Form` with `Button` and `Checkbox`, `ScrollView`,
`ProgressBar` and `Spinner`. Each is an element (place it in a tree where it
shows), takes its keys from `bindings()` (rebind them with `bind()`, see
[components](components.md#key-bindings)), draws itself through `write()` in the look the
stylesheet gives it, and is tested through `FakeTerminal` for the keyboard and the mouse.

The older built-ins (`TextInput`, `ListView`, `Log`, `Terminal`, `StatusBar`, `Box`) are
described in [components](components.md#built-in-components); `Editor` with `maxLines: 1`
does what `TextInput` does and more, `Select` what `ListView` does.

## Editor

Editable text: a one-line field or a multi-line text, with word wrap, selection by keyboard and
mouse, undo and redo, a kill ring and bracketed paste.

```php
$name = $this->child('name', fn() => new Editor(placeholder: 'your name', maxLines: 1,
    onSubmit: fn(string $value) => $this->save($value)));
$body = $this->child('body', fn() => new Editor(wrap: true));      // fills its box
```

| Constructor argument | |
|---|---|
| `string $value = ''` | The initial text. |
| `string $placeholder = ''` | Shown while it is empty (part `placeholder`). |
| `int $maxLines = 0` | `1`: a single-line field (Enter submits, or goes on to the dialog or form it is in when there is no `onSubmit`; pasted line breaks become spaces; it scrolls sideways; Up/Down go on to the parent). Otherwise any number of lines (Enter starts a line, Alt-Enter or Ctrl-Enter submits): nothing typed or pasted is joined or dropped. Above 1, how many rows it **shows** at most (it grows with its text up to there, and scrolls beyond); `0` fills its room. |
| `bool $wrap = true` | Wrap long lines between words; else they are cut at the edge and the view scrolls sideways with the cursor. |
| `?Closure $onSubmit` | `fn(string $value, Editor $editor)`: Enter in a one-line field, Alt-Enter in a multi-line one. |
| `?Closure $onChange` | `fn(string $value, Editor $editor)`: after every change of the text. It also emits the event `change`. |
| `bool $history = false` | Remember submitted texts; Up on the first row and Down on the last walk them, as in a shell (the text being written comes back after the newest). |
| `?Closure $complete` | `fn(string $word, Editor $editor): iterable` candidates for the word before the cursor: Tab shows them in a list under the cursor (an overlay); Up/Down choose, Tab or Enter accept (the word is replaced), Escape closes, typing filters. |

`placeholder`, `maxLines` and `wrap` are props: an `Editor` placed with `new` on every render
takes new ones and keeps its text.

**Size.** Without a `height`: one row with `maxLines: 1`; as high as its text up to `maxLines`
above 1 (so a `Box` around it grows as the user types: a chat input); the room it is given
with `maxLines: 0`.

**Extending it.** `Editor` is a class to extend for an input of your own (a chat input, a
command line, a form field): add or change keys in `bindings()`
(`[...parent::bindings(), 'enter' => 'send']`), call the public actions and `insert()` from
yours, override `onKey()`, `onPaste()` or `measure()` and call the parent's.

| Method | What it does |
|---|---|
| `value(): string` / `setValue(string $value)` | The text, lines joined by "\n"; replacing it is an undo step, the cursor goes to the end. |
| `cursor(): array` / `setCursor(int $line, int $column)` | The cursor as `[line, column]`, from 0, the column in grapheme clusters. |
| `selectedText(): string` | The selected text ('' for none). |
| `select(int $fromLine, int $fromColumn, int $toLine, int $toColumn)` | Select a range; the cursor goes to its end. |
| `insert(string $text)` | Type `$text` at the cursor, over the selection (one undo step). |
| `history(): array` / `setHistory(array $texts)` | The remembered submitted texts, oldest first. |
| `completion(): ?Select` | The open completion list. |
| the actions below | Each a public method, bound to its keys. |

| Keys | Action | |
|---|---|---|
| Left/Right, Ctrl-B/Ctrl-F | `left`, `right` | A character (across line breaks). |
| Up/Down, Ctrl-P/Ctrl-N | `up`, `down` | A row, keeping the column; false (the key goes on) on the first/last row. |
| Home/End, Ctrl-A/Ctrl-E | `lineStart`, `lineEnd` | |
| Ctrl-Home/Ctrl-End | `textStart`, `textEnd` | |
| Page Up/Page Down | `pageUp`, `pageDown` | |
| Ctrl-Left/Ctrl-Right, Alt-B/Alt-F | `wordLeft`, `wordRight` | |
| Shift with the arrows, Home, End; Ctrl-Shift-Left/Right | `selectLeft`, `selectRight`, `selectUp`, `selectDown`, `selectLineStart`, `selectLineEnd`, `selectWordLeft`, `selectWordRight` | Extend the selection. |
| Alt-A | `selectAll` | |
| Escape | `clearSelection` | False without a selection (Escape goes on). |
| Backspace/Ctrl-H, Delete/Ctrl-D | `deleteBack`, `deleteForward` | The selection, or a character. |
| Ctrl-W/Alt-Backspace, Alt-D | `killWordBack`, `killWord` | Kill a word (to the kill ring). |
| Ctrl-K, Ctrl-U | `killToEnd`, `killToStart` | Kill to the end (at the end: the line break) or start of the line. Kills in a row make one entry. |
| Ctrl-Y, Alt-Y | `yank`, `yankPop` | Insert the last kill; right after, cycle to older ones. |
| Ctrl-Z/Ctrl-_, Alt-Z/Ctrl-Shift-Z | `undo`, `redo` | Typing in one go (up to a space) is one step, a paste one step. |
| Ctrl-C, Ctrl-X | `copy`, `cut` | The selection to the kill ring and the terminal's clipboard (OSC 52); false without one. |
| Enter | `enter` | Submit (one line) or a new line. |
| Alt-Enter, Ctrl-Enter | `submit` | `onSubmit`. |
| Up/Down on the edge rows, with `history` | `historyBack`, `historyForward` | |
| Tab, with `complete` | `completeWord` | Open the completion list, or accept from it. |

Typed text and pastes replace the selection. **Mouse**: a press puts the cursor there (and
focuses the editor), a drag selects, the wheel scrolls (the next cursor move brings the cursor
back into view). Text is edited by grapheme cluster: an emoji is one step, wide characters two
columns.

**Style**: `Editor` (the text and the fill; the default theme gives the focused editor the
`$panel` background, so the field with the keys stands out), `Editor:focused`,
`Editor:disabled` (dimmed; a disabled editor takes no input), parts `Editor::placeholder` and
`Editor::selection`.

## Table

Rows of cells under a header, in columns: a process list, query results.

```php
new Table(
    columns: ['PID' => ['width' => 7, 'align' => 'right'], 'Command' => '1fr', 'CPU%' => ['align' => 'right']],
    rows: [1201 => ['1201', 'php server.php', '3.2'], 88 => ['88', 'sshd', '0.0']],
)
```

| Constructor argument (all props) | |
|---|---|
| `array $columns` | By title: a width, or `['width' => ..., 'align' => 'left'\|'right'\|'center', 'sortable' => true]`. Widths: cells, `'25%'`, `'1fr'`, or `'auto'` (the default: its widest cell and title). |
| `array $rows` | By key (your ids), each a list of cells in column order; cells may carry SGR sequences. |
| `bool $header = true` | Show the titles. |

A cell wider than its column is cut with "…". When the columns do not fit, the table scrolls
sideways by column. The current row stays the same row (by key) through new rows and sorting.

| Method | What it does |
|---|---|
| `current(): int\|string\|null` / `setCurrent($key): bool` | The current row's key; make a row current (scrolled into view). |
| `sortBy(?int $column, bool $descending = false)` / `sorting(): array` | Sort by a column (numbers as numbers, text without its escape sequences), or back to the order given with null; `[column, descending]`. |
| `topIndex(): int`, `bottomIndex(): int` | The positions of the first and last rows in view. |
| `previous`, `next`, `pageUp`, `pageDown`, `first`, `last`, `scrollLeft`, `scrollRight` | The actions of Up/Down (Ctrl-P/N), Page Up/Down, Home/End, Left/Right. |

**Mouse**: a click on a row makes it current; a click on a title sorts by that column (again:
the other way); the wheel scrolls rows (sideways wheel: columns). **Size**: `'auto'` is its
rows and header. **Style**: parts `Table::header` (state `sorted`) and `Table::row` (state
`current`; the default theme inverts it). **Events**: `scroll` when the rows in view changed.

## Select

A `Table` to pick from: one item or several, filtering as you type; or a dropdown field.

```php
$pick = $this->child('fruit', fn() => new Select(
    items: ['a' => 'Apple', 'b' => 'Banana', 'c' => 'Cherry'],
    onActivate: fn($key) => $this->open($key),
));

new Select(
    items: [1201 => ['1201', 'php', '3.2'], 88 => ['88', 'sshd', '0.0']],
    columns: ['PID' => 6, 'Command' => '1fr', 'CPU' => 5],
    multiple: true,
    onChange: fn(array $keys) => $this->state->picked = $keys,
)
```

| Constructor argument | |
|---|---|
| `array $items` | By key: a label (one column, no header) or a list of cells (with `columns`). |
| `array $columns = []` | As for `Table`; with columns there is a header, sortable by a click. |
| `bool $multiple = false` | Several items can be marked (Space). A prop. |
| `bool $filterable = true` | Typing filters. A prop. |
| `?Closure $onSelect` | `fn($key, Select $s)`: the current item changed. |
| `?Closure $onActivate` | `fn($key, Select $s)`: Enter, or a click on the current item. |
| `?Closure $onChange` | `fn(array $keys, Select $s)`: the marked items changed. |
| `bool $dropdown = false` | A one-line field showing the current item, that opens its list in an overlay. A prop. |

| Method | What it does |
|---|---|
| `value(): mixed` | Its value in a `Form`: the current key, or the marked keys with `multiple`. |
| `isFiltering(): bool` | True while typing goes into the filter. |
| `open(): bool`, `close()`, `isOpen(): bool`, `list(): ?Select` | A dropdown's list: open it, close it unchanged, whether it is open, the list. |
| `setItems(array $items)` | New items; the current and marked items stay (by key) where they still are; when the current one is gone, the one at its place becomes current. |
| `selected(): int\|string\|null` | The current item's key. |
| `selection(): array` / `setSelection(array $keys)` | The marked items in item order (without `multiple`: the current one). |
| `filter(): string` / `setFilter(string $text)` | The filter typed so far. |
| `activate`, `toggle`, `markAll`, `filterBack`, `clearFilter` | The actions of Enter, Space, Alt-A, Backspace, Escape (each false when it does not apply, so the key goes on; Escape with no filter goes to the parent). |
| and those of `Table` | `current()`, `setCurrent()`, `sortBy()`, ... |

**Filtering is a mode.** Typing a character (or `/`) starts it: while it is on, what is typed
goes into the filter, **Space included**, and only the items whose text contains it (ignoring
case) are shown, with the filter line under them (part `Select::filter`); Up/Down still move.
Backspace takes a character back; Escape clears the filter and ends the mode. Enter ends the
mode with `multiple`, keeping the filter, so Space marks again (typing resumes the filter);
without `multiple`, Enter activates the current item as always. So "red a" filters for "red a"
in a multi-select too.

**Dropdown.** With `dropdown: true` it is one line: the current item and `▾`. Enter, Space,
Down or a click opens the list under it (above it near the bottom of the screen; shrunk and
scrolling when neither side has the room; as wide as the field or its items), an overlay
(see [components](components.md#overlays-and-dialogs)). Up/Down move, Enter or a click picks
and closes it (`onActivate`), Escape or a click outside closes it unchanged; the focus comes
back to the field. Typing on the closed field opens it, filtering. **Style**: as `Table`, the row state
`selected` for a marked item (the current one without `multiple`; the default theme: accent
and bold).

## Dialog and Confirm

A modal dialog with a message (or any element tree: a form) and buttons, opened with
`Component::dialog()`, which returns an awaitable of the answer
(see [components](components.md#overlays-and-dialogs)).

```php
$this->go(function () {
    $choice = fiber\await($this->dialog(new Dialog(
        title: 'Unsaved changes',
        content: 'Save the file before closing?',
        buttons: ['Save' => 'save', 'Discard' => 'discard', 'Cancel' => null],
    )));
});

$this->go(function () {
    if (fiber\await($this->dialog(new Confirm('Delete 3 files?')))) { ... }
});
```

| `Dialog` constructor argument (props) | |
|---|---|
| `string $title = ''` | At the top, part `title`. |
| `string\|Element $content = ''` | The message (wrapped at 60 columns or the screen), or an element tree with fields. |
| `array $buttons = ['OK' => true]` | The answer of each button by label, in order; they are `Button`s. |
| `mixed $cancel = null` | The answer for Escape, and when the dialog ends unanswered (its opener disposed, the program quit). |

`Confirm(string $question, string $yes = 'Yes', string $no = 'No', string $title = '')`
answers true or false (Escape: false).

**Keys.** The focus starts on the content's first field, or on the first button when there is
none. Tab and Shift-Tab go through the content's fields first and the buttons after, around;
Left/Right move between the buttons. Enter presses the focused button (from a field: the first
button), Escape cancels; Page Up/Page Down scroll the content. A click on a button presses it.

**Size.** As large as its content needs (a message wraps at 60 columns), never larger than the
screen: a dialog that does not fit is shrunk, its content scrolls (it is in a `ScrollView`) and
its buttons stay in view. `with(width: 50, height: 12)` fixes the size.

**A form in a dialog.** Subclass it and override `accept(mixed $answer): bool`: it is asked
before a button's answer closes the dialog, and returning false keeps it open (show the errors
in its content). It is not asked for the cancel answer.

```php
final class Rename extends Dialog
{
    private Editor $name;

    public function __construct(string $current)
    {
        $this->name = new Editor($current, maxLines: 1);
        parent::__construct('Rename', new Column([$this->name]), ['Rename' => 'ok', 'Cancel' => null]);
    }

    public function name(): string
    {
        return trim($this->name->value());
    }

    protected function accept(mixed $answer): bool
    {
        if ($this->name() !== '') {
            return true;
        }
        $this->content = new Column([$this->name, new Text('A name, please.', class: 'error')]);
        return false;       // stays open, with the message
    }
}

// in a coroutine: if (fiber\await($this->dialog($rename = new Rename($file))) === 'ok') { ... $rename->name() ... }
```

| Method | What it does |
|---|---|
| `result(): object` | The awaitable of the answer (what `dialog()` returns). |
| `close(mixed $answer = null)` | Close with an answer, from code (without asking `accept()`). |
| `accept(mixed $answer): bool` (protected) | Whether a button's answer closes the dialog. |
| `nextField`, `previousField`, `previousButton`, `nextButton`, `press`, `cancel`, `scrollUp`, `scrollDown` | The actions of Tab, Shift-Tab, Left, Right, Enter, Escape, Page Up, Page Down. |

**Style**: `Dialog` (the default theme: a round border in the accent colour, padding `0 1`),
the part `Dialog::title`; the buttons are `Button`s; `Confirm` for rules of its own.

## Form, Button and Checkbox

`Form` collects fields from an element tree and submits them; the fields are components that
implement `Field` (`value()`): `Editor` (its text), `Checkbox` (checked or not), `Select` (the
current key, or the marked keys with `multiple`), named by their `id`.

```php
new Form(
    new Column([
        new Box((new Editor(maxLines: 1))->with(id: 'name'), 'Name'),
        (new Select(['dev' => 'Developer', 'ops' => 'Operations'], dropdown: true))->with(id: 'role'),
        (new Checkbox('Send me the newsletter'))->with(id: 'news'),
        new Row([new Button('Save'), new Button('Cancel', onPress: fn() => $this->cancel())], gap: 2, height: 1),
    ]),
    validate: fn(array $v) => $v['name'] === '' ? ['name' => 'Your name, please.'] : [],
    onSubmit: fn(array $v) => $this->save($v),          // ['name' => 'Ada', 'role' => 'ops', 'news' => true]
)
```

| `Form` | |
|---|---|
| `new Form(Element $content, ?Closure $onSubmit = null, ?Closure $validate = null)` | `$content` (a prop) holds the fields; `$validate(array $values, Form $form): array` returns the errors by field id; `$onSubmit(array $values, Form $form)` is called for a submit without errors. |
| `submit(): bool` | Validate and submit (true when valid). With errors: each field in error is in the state `:invalid` (the default theme: the error colour), the messages show under the content (part `Form::error`), the first field in error gets the focus. |
| `values(): array`, `fields(): array`, `errors(): array` | The values and the fields by id, the errors of the last submit. |
| `nextField`, `previousField` | Tab and Shift-Tab: from field to field in the order of the tree (buttons included), around. |

**Keys**: Tab/Shift-Tab move; Enter in a one-line field, or on a `Button` without `onPress`,
submits (as in HTML); Alt-Enter submits from anywhere (a multi-line `Editor` takes Enter).

| `Button` | |
|---|---|
| `new Button(string $label, ?Closure $onPress = null, ...attributes)` | Drawn `[ label ]`, one row as wide as that. `$onPress($button)` when pressed: Enter, Space, or a click released on it; also the event `press`. In a `Form`, a button without `$onPress` submits it. `press(): bool` from code (false when disabled). |

**Style**: `Button`, states `:focused` (inverted in the default theme), `:pressed` (while the
mouse button is held on it), `:disabled`.

| `Checkbox` | |
|---|---|
| `new Checkbox(string $label = '', bool $checked = false, bool $toggle = false, ?Closure $onChange = null, ...attributes)` | `[x] label`, or with `$toggle` a switch `━● label`. Space or a click flips it (Enter goes on: in a form it submits); `$onChange($checked, $checkbox)` and the event `change`. |
| `checked(): bool`, `setChecked(bool $checked)`, `flip(): bool`, `value(): bool` | |

**Style**: `Checkbox`, states `:focused`, `:checked`, `:disabled`; the part `Checkbox::box`
(state `checked`; the accent colour in the default theme).

## ScrollView

Content taller than its box, scrolling: `new ScrollView(Element $content)` (a prop). The content
is laid out at the view's width and as high as it needs, behind the view. The focused element
in it is kept in view (Tab through a long form scrolls along); the wheel scrolls it, and Up/Down,
Page Up/Page Down and Home/End do when nothing in it takes them (at the end they go on, to move
to the next field). Without a size it is as high as its content, so it only scrolls when its box
is smaller. `top()`, `scrollTo(int $row)`, `scrollBy(int $rows): bool`. A `Dialog` puts its
content in one, and so does an overlay of a plain element.

## ProgressBar and Spinner

```php
new ProgressBar($done / $total, label: "$done/$total files")    // ███████░░░░ 7/12 files
new ProgressBar(null, label: 'connecting')                       // indeterminate: moves by itself
new Spinner('saving')                                            // ⠹ saving, spins by itself
```

| | |
|---|---|
| `new ProgressBar(?float $value = 0.0, string $label = '', ...attributes)` | Filled to `$value` (0 to 1, clamped); the label after it, or the percentage without one. Null: indeterminate, a block moving to and fro (animated by the bar itself). `$value` and `$label` are props; `setValue(?float $value, ?string $label = null)`. Style: `ProgressBar`, part `ProgressBar::bar`. |
| `new Spinner(string $label = '', array $frames = Spinner::DOTS, float $interval = 0.08, ...attributes)` | A sign cycling through `$frames` (`Spinner::DOTS`, `Spinner::LINE`, or yours) every `$interval` seconds while it is shown, then the label. No coroutine of yours: show it while something runs, take it out of the tree after. `frame(): int`. Style: `Spinner`, part `Spinner::sign`. |
