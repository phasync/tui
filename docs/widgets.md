# Widgets

Components for the screens applications are made of, in `phasync\Tui\Component\`: `Editor`,
`Table`, `Select`, `Dialog` and `Confirm`. Each is an element (place it in a tree where it
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
| `int $maxLines = 0` | `1`: a single-line field (Enter submits, pasted line breaks become spaces, it scrolls sideways, Up/Down go on to the parent). `0`: any number of lines (Enter starts a line, Alt-Enter or Ctrl-Enter submits). More: a limit on the lines. |
| `bool $wrap = true` | Wrap long lines between words; else they are cut at the edge and the view scrolls sideways with the cursor. |
| `?Closure $onSubmit` | `fn(string $value, Editor $editor)`: Enter in a one-line field, Alt-Enter in a multi-line one. |
| `?Closure $onChange` | `fn(string $value, Editor $editor)`: after every change of the text. It also emits the event `change`. |

`placeholder`, `maxLines` and `wrap` are props: an `Editor` placed with `new` on every render
takes new ones and keeps its text.

**Size.** Without a `height`: one row with `maxLines: 1`; as high as its text up to `maxLines`
with a limit; the room it is given with `maxLines: 0`.

| Method | What it does |
|---|---|
| `value(): string` / `setValue(string $value)` | The text, lines joined by "\n"; replacing it is an undo step, the cursor goes to the end. |
| `cursor(): array` / `setCursor(int $line, int $column)` | The cursor as `[line, column]`, from 0, the column in grapheme clusters. |
| `selectedText(): string` | The selected text ('' for none). |
| `select(int $fromLine, int $fromColumn, int $toLine, int $toColumn)` | Select a range; the cursor goes to its end. |
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

A `Table` to pick from: one item or several, filtering as you type.

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

| Method | What it does |
|---|---|
| `setItems(array $items)` | New items; the current and marked items stay (by key) where they still are; when the current one is gone, the one at its place becomes current. |
| `selected(): int\|string\|null` | The current item's key. |
| `selection(): array` / `setSelection(array $keys)` | The marked items in item order (without `multiple`: the current one). |
| `filter(): string` / `setFilter(string $text)` | The filter typed so far. |
| `activate`, `toggle`, `markAll`, `filterBack`, `clearFilter` | The actions of Enter, Space, Alt-A, Backspace, Escape (each false when it does not apply, so the key goes on: Space types into the filter without `multiple`, Escape with no filter goes to the parent). |
| and those of `Table` | `current()`, `setCurrent()`, `sortBy()`, ... |

**Filtering**: typed text shows only the items whose text contains it (ignoring case), with a
filter line under them (part `Select::filter`). **Style**: as `Table`, the row state
`selected` for a marked item (the current one without `multiple`; the default theme: accent
and bold).

## Dialog and Confirm

A modal dialog with a message (or any element tree) and buttons, opened with
`Component::dialog()`, which returns an awaitable of the answer
(see [components](components.md#dialogs)).

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
| `string\|Element $content = ''` | The message (wrapped at 60 columns or the screen), or an element tree (give the dialog a size with `with(width: 50, height: 12)`). |
| `array $buttons = ['OK' => true]` | The answer of each button by label, in order; the first has the focus. |
| `mixed $cancel = null` | The answer for Escape, and when the dialog ends unanswered (its component disposed, the program quit). |

`Confirm(string $question, string $yes = 'Yes', string $no = 'No', string $title = '')`
answers true or false (Escape: false).

| Method | What it does |
|---|---|
| `result(): object` | The awaitable of the answer (what `dialog()` returns). |
| `close(mixed $answer = null)` | Close with an answer, from code. |
| `previousButton`, `nextButton`, `press`, `cancel` | The actions of Left/Shift-Tab, Right/Tab, Enter, Escape. |

A click on a button presses it. **Style**: `Dialog` (the default theme: a round border in the
accent colour, padding `0 1`, centered), parts `Dialog::title` and `Dialog::button` with state
`focused`; `Confirm` for rules of its own.
