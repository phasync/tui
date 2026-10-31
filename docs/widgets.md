# Widgets

Components for the screens applications are made of, in `phasync\Tui\Component\`: `Editor`,
`Table`, `Select`, `Tree`, `Dialog` and `Confirm`, `Form` with `Button` and `Checkbox`, `ScrollView`,
`Tabs`, `ProgressBar` and `Spinner`, and `Markdown`. Each is an element (place it in a tree where it
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
| `array $rows` | By key (your ids), each a list of cells in column order; cells take markup (`'<fg=red>failed</>'`, see [styling.md](styling.md#markup-in-text)) and may carry SGR sequences. |
| `bool $header = true` | Show the titles. |

A cell wider than its column is cut with "…" (by its visible text). When the columns do not
fit, the table scrolls sideways by column. The current row stays the same row (by key) through
new rows and sorting. **Cost**: the widths of `'auto'` columns and the order of the rows are
worked out when the rows change (a new `rows` array, `setItems()`), the sorting changes or a
`Select`'s filter does, not when the cursor or the view moves: moving through 10,000 rows costs
what moving through 100 does (the rows in view are drawn). A subclass that shows only some rows
overrides `filterRows(array $keys): array` and returns what they depend on from
`filterKey(): string`. After `$table->rows = $fresh` from outside a render, call
`requestRender()` (or give the rows by placing a `new Table(...)` in the tree).

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
| `array $items` | By key: a label (one column, no header) or a list of cells (with `columns`); labels and cells take markup. |
| `array $columns = []` | As for `Table`; with columns there is a header, sortable by a click. |
| `bool $multiple = false` | Several items can be marked (Space). A prop. |
| `bool $filterable = true` | Typing filters. A prop. |
| `?Closure $onSelect` | `fn($key, Select $s)`: the current item changed. |
| `?Closure $onActivate` | `fn($key, Select $s)`: Enter, or a click on the current item. |
| `?Closure $onChange` | `fn(array $keys, Select $s)`: the marked items changed. |
| `bool $dropdown = false` | A one-line field showing the current item, that opens its list in an overlay. A prop. |

**Which callback when**: `onSelect` fires whenever the current (highlighted) item changes, by
the arrow keys, a click, the filter or `setCurrent()`; `onActivate` when the user picks the
current item (Enter, a click on it, a dropdown's choice); `onChange` when the marked items
change (Space, Alt-A, `setSelection()`; only with `multiple`).

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

## Tree

Items that open and close: a file browser, an outline, a process tree. See `examples/tree.php`.

```php
$tree = $this->child('files', fn() => new Tree(
    items: [
        ['label' => 'src', 'expanded' => true, 'children' => [
            ['label' => 'Tree.php', 'value' => '/src/Tree.php'],
        ]],
        ['label' => 'vendor'],                       // no children given: loaded when opened
    ],
    load: fn(array $node) => $this->listing($node['value'] ?? $node['key']),   // a list of items, or an awaitable of it
    onActivate: fn($key, Tree $tree) => $this->open($tree->value($key)),
));
```

| Constructor argument | |
|---|---|
| `array $items` | A prop: a list of items (below). |
| `?Closure $load` | `fn(array $node): iterable\|awaitable` the children of a lazy node. `$node` has `key`, `label`, `icon`, `value` and `depth`. |
| `bool $multiple = false` | Space marks nodes (`selection()` lists them). |
| `bool $guides = true` | Draw `├─ └─ │` lines; off: two spaces of indent a level. |
| `onSelect`, `onExpand`, `onCollapse`, `onActivate` | `fn($key, Tree $tree)`: the current node changed; a node opened; a node closed; Enter on a node. |
| `onChange` | `fn(array $keys, Tree $tree)`: the marked nodes changed. |

**An item** is an array: `label`, `key` (your id; default the parent's key and the label joined
by `/`; keys are unique in the tree, and a duplicate throws), `icon` (shown before the label),
`value` (anything; `value($key)` gives it back), `children` (a list of items), `expanded` (open
when first shown), `leaf` (never has children). With `load`, an item without `children` is a
branch whose children are fetched when it is first opened.

**Lazy children.** `load` returns the items (an array or any `Traversable`, a generator
included), or an awaitable of them: **any other object is taken for an awaitable** and passed to
`fiber\await()` in a coroutine of the tree, so a `fiber\go()` handle, a `fiber\Deferred` or a
promise with `then()` all work. While it is awaited the node shows a spinning sign in place of
▸/▾ and the tree stays usable.
The children are kept: closing and opening again does not load again (`reload($key)` does). When
the load fails the node closes (state `failed`) and opening it tries again.

| Keys | Action | |
|---|---|---|
| Up/Down | `previous`, `next` | False on the first/last node (the key goes on). Add Ctrl-P/Ctrl-N or j/k with `bind()`. |
| Page Up/Page Down, Home/End | `pageUp`, `pageDown`, `first`, `last` | |
| Right | `open` | Open a closed node; on an open one go to its first child. |
| Left | `close` | Close an open node; else go to the parent. |
| Enter | `activate` | Open or close a branch, and call `onActivate`. |
| Space | `toggleOrMark` | Open or close a branch; with `multiple` mark or unmark the node. |
| letters | | Jump to the next node whose label starts with them. Letters typed within a second make one word; the same letter again goes on to the next node starting with it. |

**Mouse**: a click on a row makes it current, a click on its ▸/▾ opens or closes it, Ctrl-click
marks (with `multiple`), the wheel scrolls three rows.

| Method | What it does |
|---|---|
| `current(): int\|string\|null` / `setCurrent($key): bool` | The current node's key; make a node current, opening what is above it and scrolling it into view. |
| `selection(): array` / `setSelection(array)` / `isMarked($key)` | The marked keys in tree order (without `multiple`: the current node's). |
| `expand($key = null)`, `collapse($key = null)`, `toggle($key = null): bool` | Open or close a node (the current one by default); false when nothing changed. Closing a node that holds the current one makes it current. |
| `isExpanded`, `isBranch`, `isLoading`, `hasFailed`, `has` | Questions about a node. |
| `value($key)`, `label($key)`, `parentOf($key)` | What an item holds. |
| `setItems(array)` | New items; which nodes are open, marked and current is kept by key, and so are loaded children. |
| `reload($key)` | Forget the loaded children of a lazy node and load them again if it is open. |
| `visibleCount()`, `visibleKeys()` | The nodes shown (the open ones). The view scrolls as `Scrollable` says: `scrollTop()` (the row at the top), `scrollTo()`, the `scroll` event. |

**Events** (for `on()`): `select`, `expand`, `collapse`, `activate` (each with the key and the
tree) and `change` (the marked keys and the tree).

**Size and speed.** It takes the room it is given. Only the rows in view are laid out and drawn,
so 100,000 nodes cost what a screenful does; opening or closing a node walks the open nodes once.
A wide label is cut with "…", and wide characters and emoji count two columns.

**Style**: parts `Tree::node` (states `current`, `selected` for a marked node, `loading`,
`failed`), `Tree::marker` and `Tree::guide`, in the same states. The marker and the guides are
drawn on their node's row: they inherit its colours and attributes, so one without a colour of
its own keeps the current row one inverted bar (see [parts on one row](components.md#parts-on-one-row)).
The default theme shows the current node inverted, the markers in the accent colour and the
guides muted.

```php
$app->setStylesheet(Stylesheet::default()->with([
    'Tree::node:current' => ['background' => '$accent', 'color' => 'black'],
    'Tree::guide' => ['color' => 'green'],
]));
```

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

**A form in a dialog.** Give the dialog a `Form` as its content (or put one in it): the form
is validated before an answer closes the dialog. Enter in a field submits the form and, when
it is valid, answers with the first button's answer; any button but the cancel one submits it
first and answers only when it is valid (the form shows its messages, the field in error gets
the focus); the cancel button and Escape close without validating. The form's `onSubmit` has
the values:

```php
public function add(): void
{
    $this->go(function () {
        $contact = null;
        $answer = fiber\await($this->dialog(new Dialog('Add a contact', new Form(
            new Column([
                new Text('Name'),
                (new Editor(maxLines: 1))->with(id: 'name'),
                new Text('Email'),
                (new Editor(maxLines: 1))->with(id: 'email'),
            ]),
            validate: fn(array $v) => trim($v['name']) === '' ? ['name' => 'A name, please.'] : [],
            onSubmit: function (array $values) use (&$contact) {
                $contact = $values;
            },
        ), ['Add' => 'add', 'Cancel' => null])));
        if ($answer === 'add') {
            $this->state->contacts = [...$this->state->contacts, $contact];
        }
    });
}
```

**Where to read the values.** The fields end with the dialog, so read the values in one of
three places: in the form's `onSubmit` (above: it runs before the dialog closes), in the
dialog's `accept()` with `$form->values()`, or after the answer came with `$form->values()`,
which once the form has ended gives the values of its last submit:

```php
$form = new Form($fields, validate: $check);
if (fiber\await($this->dialog(new Dialog('Add', $form, ['Add' => 'add', 'Cancel' => null]))) === 'add') {
    $this->add($form->values());      // ['name' => 'Ada', 'email' => ...], as submitted
}
```

`accept()` is asked after the form was submitted and valid: a `submit()` there does nothing
(and returns true), so an `accept()` that calls it does not submit twice or loop.

**A dropdown in a dialog** opens on the side that leaves the dialog's buttons in view when its
list fits on both, and stays anchored to its field when the terminal is resized: the dialog is
placed again first, then the list next to where the field is now (or it closes when the field
can no longer be shown).

For checks of your own, subclass the dialog and override `accept(mixed $answer): bool`: it is
asked before a button's answer closes the dialog (after a form inside was valid), and returning
false keeps it open (show the errors in its content). It is not asked for the cancel answer.

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
| `submit(): bool` | Validate and submit (true when valid). With errors: each field in error is in the state `:invalid` (the default theme: the error colour), the messages show under the content (part `Form::error`), the first field in error gets the focus. Without: `$onSubmit`, then the event `submit` (with the form). |
| `values(): array`, `fields(): array`, `errors(): array` | The values and the fields by id, the errors of the last submit. Once the form has ended (its dialog closed), `values()` gives the values of its last submit. |
| `nextField`, `previousField` | Tab and Shift-Tab: from field to field in the order of the tree (buttons included), around; inside a component that moves the focus with Tab too (a `Dialog`), on from the last field to what follows (the dialog's buttons). |
| `enter()` | Enter and Alt-Enter: `submit()`, the key taken also when the values are not valid. |

**Keys**: Tab/Shift-Tab move; Enter in a one-line field, or on a `Button` without `onPress`,
submits (as in HTML); Alt-Enter submits from anywhere (a multi-line `Editor` takes Enter).
In a `Dialog`, see "A form in a dialog" above.

| `Button` | |
|---|---|
| `new Button(string $label, ?Closure $onPress = null, ...attributes)` | Drawn `[ label ]`, one row as wide as that. `$onPress($button)` when pressed: Enter, Space, or a click released on it; also the event `press`. In a `Form`, a button without `$onPress` submits it. `press(): bool` from code (false when disabled). |

Button and Checkbox labels take markup (`<b>Save</b>`, see [styling.md](styling.md#markup-in-text)); the width is that of the visible text.

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

**Cropped, never squeezed.** Content that does not fit is laid out at the height it needs and
cropped by the view: a `Box` around a one-line field stays three rows (border, field, border)
and scrolls. Content with something that takes any room (an `Editor` without `maxLines`, a
`Log`) is as high as its parts need at least, one row for each such thing (so a form with a
notes field still scrolls instead of shrinking its boxes to their borders). Giving a field the
focus brings it into view with what frames it: the largest window around it that holds no other
field and fits the view (its `Box`, with the border and the title). A dropdown opened from a
field of a scrolled view opens under the row where the field is shown.

## Tabs

A tab bar (top or bottom) and one content per tab: panes of a workspace, open files, shells.

```php
$tabs = $this->child('tabs', fn() => new Tabs([
    'shell' => ['label' => 'Shell', 'content' => new Terminal('sh')],
    'log'   => ['label' => 'Log',   'content' => new Log()],
    'help'  => ['label' => 'Help',  'content' => new Markdown($help), 'closable' => true],
], hints: true, onChange: fn(string $key) => $this->status->set($key)));

$tabs->setTabs([...$open, 'new' => ['label' => 'New', 'content' => new Editor()]], current: 'new');
```

| Constructor argument | |
|---|---|
| `array $tabs = []` | By key: `['label' => string, 'content' => Component\|Element, 'closable' => ?bool]`. Labels are plain text. Taken from the first instance only; change them with `setTabs()`. |
| `?string $current` | The key of the first current tab (default the first). |
| `string $position = 'top'` | `'top'` or `'bottom'`. A prop. |
| `bool $hints = false` | Show the number of each of the first nine tabs (what Alt-number selects). A prop. |
| `bool $closable = false` | A `×` on every tab (a tab's own `closable` overrides). A prop. |
| `?Closure $onChange` | `fn(string $key, Tabs $tabs)`: the current tab changed. Also the event `change`. |
| `?Closure $onClose` | `fn(string $key, Tabs $tabs)`: a tab is asked to close (the `×`, a middle click, Delete). It decides: call `close($key)` or `setTabs()`. Without it the tab is removed and its component disposed. The event `close` is emitted either way. |

**Hidden tabs keep their state.** A content that is a component is started when the tabs are
and lives until its tab goes (it is a kept child, see [kept-alive children](components.md#kept-alive-children)):
hidden, it is not laid out but it is not stopped either; `setTabs()` without it stops it. A
`Terminal` in a hidden tab goes on running and receiving output (it starts when its tab is
first shown, because a terminal starts when it is sized); an `Editor` keeps its text, a `Log`
goes on collecting. A content that is an element tree holding components
(`Row([$select, new Box($tree)])`) keeps them the same way: the components in it are kept
children of the tabs, alive while their tab is hidden; the elements around them are built while
the tab is shown.

**Whose the components are.** A component made by the tabs from the props (`new Log()` in the
array) is the tabs': `content($key)` returns it, and it is stopped when its tab goes. A
component you made with `child()` and gave as a content stays yours: the tabs show it and keep it
running while its tab is hidden, `child('log')` in your component returns it all the time, and
when its tab goes the tabs let go of it (a `child()` of yours that no render shows any more is
stopped then, as always).

**The focus follows into a tab.** Going to a tab while the focus is in the content (by key,
by `select()`, Alt-number) gives the focus to the first component of the new content that takes
keys itself (`canFocus()`), whatever elements wrap it, else to its first component; a tab with no
component in it gives the focus to the bar. So Ctrl-Tab from a shell in tab B to a tab A of
`Row([$select, Box($tree)])` lands in the `Select`, and Ctrl-Tab back lands in the shell.

| Method | What it does |
|---|---|
| `tabs(): array` | The keys, in order. |
| `content(string $key): Component\|Element\|null` | What a tab shows: the running component for a component content (the tabs' own or the one you gave), the element for an element content; null for an unknown key. |
| `current(): ?string` | The current key; null without tabs. |
| `select(string $key): bool` | Make a tab current (the focus follows when it was in the content); false for an unknown key. |
| `next()`, `previous()` | The actions of Right/Left (false at the ends, so the key goes on). |
| `setTabs(array $tabs, ?string $current = null)` | Add, remove, reorder and rename. The current tab stays **by key**; if it is gone the tab at its place takes over (a `change`). Components of tabs that went are disposed, the others keep their state. |
| `close(string $key): bool`, `closeCurrent()` | As the `×` and Delete do (see `$onClose`). |
| `focusContent()`, `focusBar()`, `inContent(): bool` | Move the focus into the shown content (its first component that takes keys, see above) or back to the bar; where it is (true when any component of the content has it). |

**Keys.** On the bar: Left/Right, Home/End change tab (not around), Tab or Enter move the
focus into the shown content (a content with nothing to focus keeps it on the bar), Delete
closes the current tab when it is closable. In the content: Escape moves the focus back to the
bar, but only when the content did not take it (an `Editor` with a selection clears it first),
and never for a `Terminal`, which keeps Escape and all other keys. Before the content sees them,
and from the bar or the content alike: Ctrl-Tab and Ctrl-Shift-Tab (around; bindings marked
`first`, actions `nextTab` and `previousTab`), Alt-1 to Alt-9 (the focus follows into the new
tab's content when it was in the content). Escape is a plain binding: it comes after the
content. Rebind the keys with `bind()`; `Help` lists them.

**Mouse.** A click on a tab selects it and gives the bar the focus; a click on its `×` or a
middle click closes it; the wheel over the bar steps to the previous or next tab; `‹` and `›`
do the same.

**Overflow.** When the labels are wider than the bar, it shows as many whole tabs as fit with
`‹` and `›` in the first and last column (dim when there is nothing beyond); the current tab is
always in the window, and one wider than the bar is shown cut with "…". The window only moves
when the current tab leaves it.

**Style**: `Tabs`; parts `Tabs::bar` (the row; give it a background), `Tabs::tab` with the states
`current`, `hover` and `focused` (the current tab while the bar has the focus, so
`Tabs::tab:current:focused`), `Tabs::hint`, `Tabs::close` and `Tabs::more` (`‹` `›`). Parts
compose in that order, in the tab's states, so a tab inherits the bar's background and a hint
or a `×` the tab's colours. The default theme: muted tabs, the current one bold and underlined
in the accent colour, inverted while the bar has the focus, the one under the mouse in the
accent colour (a `Tabs::tab:hover` rule is what makes the bar follow the mouse).

## ProgressBar and Spinner

```php
new ProgressBar($done / $total, label: "$done/$total files")    // ███████░░░░ 7/12 files 58%
new ProgressBar($v, label: 'upload {percent} of 2 GB')           // ███░░░░░░░░ upload 30% of 2 GB
new ProgressBar(null, label: 'connecting')                       // indeterminate: moves by itself
new Spinner('saving')                                            // ⠹ saving, spins by itself
```

| | |
|---|---|
| `new ProgressBar(float\|Closure\|null $value = 0.0, string $label = '', ...attributes)` | Filled to `$value` (0 to 1, clamped); the label (markup allowed) and the percentage after it, the percentage alone without a label. A label holding `{percent}` (`58%`) or `{value}` (`0.58`) is shown with them filled in, and no percentage after. The label takes at most half the width (cut with "…"); nothing is drawn past the bar's width. Null: indeterminate, a block moving to and fro (animated by the bar itself, ten steps a second, while it is indeterminate; a bar with a value has no coroutine and renders only when its value changes). A closure value (`fn() => $state->done / 100`) is read when the bar renders, and the bar renders by itself when a `State` the closure read changes (its parent does not). `$value` and `$label` are props; `setValue(float\|Closure\|null $value, ?string $label = null)` renders only when the bar then looks different (a change below a cell, the same percentage: nothing is drawn), so it may be called from a fast loop. Style: `ProgressBar`, part `ProgressBar::bar`. |
| `new Spinner(string $label = '', array $frames = Spinner::DOTS, float $interval = 0.08, ...attributes)` | A sign cycling through `$frames` (`Spinner::DOTS`, `Spinner::LINE`, or yours) every `$interval` seconds while it is shown, then the label. No coroutine of yours: show it while something runs, take it out of the tree after. `frame(): int`. Style: `Spinner`, part `Spinner::sign`. |

## Markdown

CommonMark in the terminal, scrolling, and fed by a stream: a README, a help page, a model's
answer as it arrives.

```php
$md = $this->child('answer', fn() => new Markdown(tail: true));
foreach ($tokens as $chunk) {
    $md->append($chunk);          // may end mid-fence, mid-emphasis, mid-link
}
```

| | |
|---|---|
| `new Markdown(string $text = '', bool $tail = false, bool $hyperlinks = false, bool $scrollbar = false, ...attributes)` | The initial text. `$tail`, `$hyperlinks` and `$scrollbar` are props. With `$tail` the view follows the end as text arrives, until the user scrolls back. With `$hyperlinks` a link is its text as an OSC 8 hyperlink the user can click; else the text with its address dimmed after it. Pass `$device->supportsHyperlinks()` (see [the terminal](terminal.md)). With `$scrollbar` the last column shows where the view is. |
| `set(string $text)`, `append(string $chunk)`, `text(): string` | Replace, add to, and read back the Markdown text. |
| `scrollTo(int $row): bool`, `scrollBy(int $rows): bool`, `scrollTop(): int`, `rowCount(): int` | Rows of the laid-out document (they depend on the width), 0 the first. False when the view did not move. |
| `follow()`, `isFollowing(): bool` | Follow the end again (with `tail`); whether the view is following. |
| `lineUp`, `lineDown`, `pageUp`, `pageDown`, `toTop`, `toBottom` | The actions of Up, Down, Page Up, Page Down, Home, End; a key the view can not use goes on to the parent. The wheel scrolls three rows. End follows the stream again. Other keys with `bind()`: `$md->bind(['j' => 'lineDown', 'k' => 'lineUp', 'space' => 'pageDown'])`. |

It scrolls with [`Scrollable`](components.md#scrolling-in-a-widget-that-draws-itself-scrollable),
as `Log` does. Event `scroll` (with the Markdown): the rows in view or `isFollowing()` changed.

**Supported**: headings (ATX and setext), paragraphs wrapped between words (hard breaks with two
trailing spaces or a backslash), `*emphasis*`, `**strong**`, `~~strike~~`, `` `code` ``,
`[links](url)` and `<autolinks>` (images show their alt text as a link), bullet and numbered lists
to any depth (a numbered list keeps its numbers aligned; items wrap under their marker), block
quotes (holding any block), fenced and indented code, tables (column alignment from the `:--:`
row, cells shrunk and wrapped to fit the width), thematic breaks. Text is laid out by display
width: wide characters and emoji take two cells. Not supported: raw HTML (shown as text),
reference links, footnotes, task-list boxes. Escape sequences and control characters in the text
are dropped, so text from outside can not drive the terminal.

**Code blocks** are filled across the width; a fence with `php`, `js` (also `javascript`, `ts`),
`sh` (`bash`, `zsh`) or `json` info gets a simple keyword, string, comment, number and variable
highlighter; other fences are plain.

**Streaming.** The whole text is read again at every change, so what shows is the best the text
so far allows: an open fence is a code block to the end, an emphasis with no end is its asterisk,
`[text](` with no address yet is text. The cost stays small: the rows of every block that did not
change are remembered (the last block, the tail, is laid out anew), and only the screen rows
that differ are written. Feeding a document in any chunking ends on the screen `set()` of the
whole gives.

**Style**: one part per kind, `Markdown::h1` to `Markdown::h6`, `paragraph`, `strong`, `emphasis`,
`strike`, `code` (a span), `codeblock` (the block and its background), `keyword`, `string`,
`comment`, `number`, `variable`, `key` (the highlighter), `link`, `url`, `quote` (text and bar),
`bullet`, `rule`, `th`, `td` and `border` (tables), `scrollbar` and `scrollbar:thumb`. Parts
that apply together compose (`strong` in `h2`, `link` in `quote`): attributes add up and the
innermost colour wins. Text outside every part, the fill and the cleared rows are in the
`Markdown` style itself, so `'Markdown' => ['background' => '$panel']` gives the whole view
a background.

```php
$app->setStylesheet(Stylesheet::default()->with([
    'Markdown::h1' => ['color' => 'yellow', 'underline' => false],
    'Markdown::codeblock' => ['background' => '#202830'],
]));
```

A hyperlink needs a terminal that shows OSC 8, and the Surface passes none today: until it does
`hyperlinks: true` shows the link text only (a link inside a pane shows its text without the
address either way).
