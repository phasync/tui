<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Text;

/**
 * Editable text: one line (a form field, a command line) or many (a message, a file), with word
 * wrap, selection by keyboard and mouse, undo and redo, and a kill ring.
 *
 *     $name = $this->child('name', fn() => new Editor(placeholder: 'your name', maxLines: 1,
 *         onSubmit: fn(string $value) => $this->save($value)));
 *     $body = $this->child('body', fn() => new Editor());        // multi-line, fills its box
 *
 * **Lines.** `maxLines: 1` is a single-line field: Enter submits (without `onSubmit` the key
 * goes on, to the `Dialog` or `Form` it is in), pasted line breaks become spaces, the text
 * scrolls sideways, and Up/Down are left to the parent (to move between fields). Otherwise it
 * takes any number of lines: Enter starts a new line and Alt-Enter (or Ctrl-Enter where the
 * terminal tells it apart) submits; nothing typed or pasted is ever joined or dropped.
 * `maxLines` above 1 is how many rows it **shows** at most: without a `height` it is as high as
 * its text up to that (a chat input that grows as you type, and a `Box` around it with it), and
 * scrolls beyond. `maxLines: 0` (the default) fills the room it is given. With `wrap` (the
 * default) long lines wrap between words, else they are cut at the edge and the view scrolls
 * sideways with the cursor.
 *
 * **History** (`history: true`): submitted texts are remembered, and Up on the first row (Down
 * on the last) walks through them as in a shell; the text being written comes back after the
 * newest. `history()` and `setHistory()` save and restore them.
 *
 * **Completion** (`complete:`): a function that gets the word before the cursor and returns
 * candidates; Tab shows them in a list under the cursor, an overlay (`Component::overlay()`)
 * as large as the candidates (8 rows and 40 columns at most): Up/Down choose, Tab or Enter accepts (the word is
 * replaced), Escape closes it, and every edit while it is open (typing on, deleting) asks for
 * the candidates again, the list growing and shrinking with them.
 *
 * **Extending it**: subclass it for a chat input, a command line or a form field of your own.
 * `bindings()` can add or change keys (`[...parent::bindings(), 'ctrl+l' => 'clear']`); the
 * actions below are public methods to call from yours; `insert()` types text at the cursor;
 * `onKey()`, `onPaste()` and `measure()` can be overridden and call the parent's.
 *
 * **Keys** (actions you can rebind with `bind()`; see docs/widgets.md for the table): arrows,
 * Home/End (Ctrl-A/Ctrl-E), Ctrl-Home/Ctrl-End, Page Up/Down, Ctrl-Left/Right or Alt-B/Alt-F by
 * word; with Shift they select. Backspace, Delete (Ctrl-D); Ctrl-W/Alt-Backspace and Alt-D
 * kill a word, Ctrl-K to the end of the line, Ctrl-U to its start, Ctrl-Y yanks the last kill
 * back and Alt-Y then cycles through older ones (the kill ring, as in Emacs and readline).
 * Ctrl-Z (or Ctrl-_) undoes, Alt-Z (or Ctrl-Shift-Z) redoes; typing in one go is one step.
 * Alt-A selects all, Ctrl-C copies the selection and Ctrl-X cuts it (to the kill ring and to
 * the terminal's clipboard; without a selection the keys go on to the parent), Escape drops
 * the selection. Typing or pasting replaces the selection. Paste (bracketed) inserts the text
 * as one step. The mouse: a press puts the cursor there, a drag selects, the wheel scrolls.
 * Text is edited by grapheme cluster, so an emoji is one step and wide characters take two
 * columns.
 *
 * **Style** parts: `Editor` itself (the text and the fill; the default theme gives the focused
 * editor a panel background), `Editor::placeholder`, `Editor::selection`; states `:focused`,
 * `:disabled` (a disabled editor takes no input).
 */
class Editor extends Component implements Field
{
    /** @var list<list<string>> the lines, as grapheme clusters */
    private array $lines = [[]];
    private int $row = 0;
    private int $col = 0;
    /** @var array{int,int}|null the other end of the selection (line, column) */
    private ?array $anchor = null;
    /** The visual column Up/Down keep to. */
    private ?int $goal = null;
    /** The first visual row in view (and the first column, without wrapping). */
    private int $top = 0;
    private int $left = 0;
    /** The wheel moved the view; the next cursor move brings it back. */
    private bool $free = false;
    /** @var list<array{list<list<string>>, int, int}> */
    private array $undoStack = [];
    /** @var list<array{list<list<string>>, int, int}> */
    private array $redoStack = [];
    /** What the last action was: typing in a row is one undo step, kills in a row are one kill. */
    private string $last = '';
    /** @var list<string> killed text, newest last */
    private array $kills = [];
    /** @var array{int, int, int, int}|null the text the last yank inserted (from, to) and the kill shown */
    private ?array $yanked = null;
    private int $yankAt = 0;
    /** @var list<string> submitted texts, oldest first */
    private array $past = [];
    /** The position in `$past` while walking it (null: not walking), and the text being written. */
    private ?int $walk = null;
    private string $draft = '';
    private ?\phasync\Tui\Overlay $completion = null;
    /** @var array{int,int} where the word being completed starts (line, column) */
    private array $wordAt = [0, 0];

    /**
     * @param string $value       the initial text
     * @param string $placeholder shown while the editor is empty
     * @param int    $maxLines    1 for a single-line field, 0 for any number of lines, or a limit
     * @param bool   $wrap        wrap long lines between words (else they scroll sideways)
     * @param (\Closure(string $value, Editor $editor): void)|null $onSubmit Enter (one line) or Alt-Enter
     * @param (\Closure(string $value, Editor $editor): void)|null $onChange after every change of the text
     * @param bool   $history     remember submitted texts; Up/Down on the edge rows walk them
     * @param (\Closure(string $word, Editor $editor): iterable<string>)|null $complete candidates for the word before the cursor
     */
    public function __construct(
        string $value = '',
        public string $placeholder = '',
        public int $maxLines = 0,
        public bool $wrap = true,
        private readonly ?\Closure $onSubmit = null,
        private readonly ?\Closure $onChange = null,
        private readonly bool $history = false,
        private readonly ?\Closure $complete = null,
    ) {
        $this->lines = $this->split($value);
        $this->row = \count($this->lines) - 1;
        $this->col = \count($this->lines[$this->row]);
    }

    protected function bindings(): array
    {
        $keys = [
            'left' => 'left', 'ctrl+b' => 'left', 'right' => 'right', 'ctrl+f' => 'right',
            'up' => 'up', 'ctrl+p' => 'up', 'down' => 'down', 'ctrl+n' => 'down',
            'home' => 'lineStart', 'ctrl+a' => 'lineStart', 'end' => 'lineEnd', 'ctrl+e' => 'lineEnd',
            'ctrl+home' => 'textStart', 'ctrl+end' => 'textEnd', 'pageup' => 'pageUp', 'pagedown' => 'pageDown',
            'ctrl+left' => 'wordLeft', 'alt+b' => 'wordLeft', 'ctrl+right' => 'wordRight', 'alt+f' => 'wordRight',
            'shift+left' => 'selectLeft', 'shift+right' => 'selectRight', 'shift+up' => 'selectUp', 'shift+down' => 'selectDown',
            'shift+home' => 'selectLineStart', 'shift+end' => 'selectLineEnd',
            'ctrl+shift+left' => 'selectWordLeft', 'ctrl+shift+right' => 'selectWordRight', 'alt+a' => 'selectAll',
            'backspace' => 'deleteBack', 'ctrl+h' => 'deleteBack', 'delete' => 'deleteForward', 'ctrl+d' => 'deleteForward',
            'ctrl+w' => 'killWordBack', 'alt+backspace' => 'killWordBack', 'alt+d' => 'killWord',
            'ctrl+k' => 'killToEnd', 'ctrl+u' => 'killToStart', 'ctrl+y' => 'yank', 'alt+y' => 'yankPop',
            'ctrl+z' => ['undo', 'undo'], 'ctrl+_' => 'undo', 'alt+z' => ['redo', 'redo'], 'ctrl+shift+z' => 'redo',
            'ctrl+c' => 'copy', 'ctrl+x' => 'cut', 'escape' => 'clearSelection',
            'enter' => 'enter', 'alt+enter' => 'submit', 'ctrl+enter' => 'submit',
        ];
        if ($this->complete !== null) {
            $keys['tab'] = 'completeWord';
        }
        if ($this->maxLines !== 1) {
            $keys['alt+enter'] = ['submit', 'submit'];
        }
        return $keys;
    }

    // ------------------------------------------------------------------------------------------
    // The text

    /** The text, lines joined with "\n". */
    public function value(): string
    {
        return implode("\n", array_map(fn(array $l) => implode('', $l), $this->lines));
    }

    /** Replace the text (an undo step); the cursor goes to the end, the selection is dropped. */
    public function setValue(string $value): void
    {
        $this->remember('set');
        $this->lines = $this->split($value);
        $this->row = \count($this->lines) - 1;
        $this->col = \count($this->lines[$this->row]);
        $this->anchor = null;
        $this->changed();
    }

    /**
     * The cursor as [line, column], both from 0, the column in grapheme clusters.
     *
     * @return array{int,int}
     */
    public function cursor(): array
    {
        return [$this->row, $this->col];
    }

    /** Put the cursor at $line, $column (clamped to the text); drops the selection. */
    public function setCursor(int $line, int $column): void
    {
        $this->anchor = null;
        $this->moveTo($line, $column);
    }

    /** The selected text ('' when nothing is selected). */
    public function selectedText(): string
    {
        $sel = $this->selection();
        return $sel === null ? '' : $this->textBetween(...$sel);
    }

    /** Select from ($fromLine, $fromColumn) to ($toLine, $toColumn); the cursor goes to the end given last. */
    public function select(int $fromLine, int $fromColumn, int $toLine, int $toColumn): void
    {
        $this->moveTo($fromLine, $fromColumn);
        $this->anchor = [$this->row, $this->col];
        $this->moveTo($toLine, $toColumn, true);
    }

    // ------------------------------------------------------------------------------------------
    // Actions (bound to keys in bindings(); public so they can be bound to other keys)

    /** Cursor one character left (to the end of the line above at the start of a line). */
    public function left(): void
    {
        $this->step(-1, false);
    }

    /** Cursor one character right. */
    public function right(): void
    {
        $this->step(1, false);
    }

    /** Cursor one row up; false (key not taken) on the first row, so a parent may use it. */
    public function up(): bool
    {
        if ($this->completion() !== null) {
            $this->completion()->previous();
            return true;
        }
        if ($this->history && ($this->maxLines === 1 || $this->visual($this->row, $this->col, max(1, $this->surface->cols()))[0] === 0) && $this->historyBack()) {
            return true;
        }
        return $this->vertical(-1, false);
    }

    /** Cursor one row down; false on the last row. */
    public function down(): bool
    {
        if ($this->completion() !== null) {
            $this->completion()->next();
            return true;
        }
        $cols = max(1, $this->surface->cols());
        if ($this->history && ($this->maxLines === 1 || $this->visual($this->row, $this->col, $cols)[0] === $this->visualRows($cols) - 1) && $this->historyForward()) {
            return true;
        }
        return $this->vertical(1, false);
    }

    /** Cursor to the start of the line. */
    public function lineStart(): void
    {
        $this->moveTo($this->row, 0, false);
    }

    /** Cursor to the end of the line. */
    public function lineEnd(): void
    {
        $this->moveTo($this->row, \PHP_INT_MAX, false);
    }

    /** Cursor to the start of the text. */
    public function textStart(): void
    {
        $this->moveTo(0, 0, false);
    }

    /** Cursor to the end of the text. */
    public function textEnd(): void
    {
        $this->moveTo(\PHP_INT_MAX, \PHP_INT_MAX, false);
    }

    /** Cursor one screen up. */
    public function pageUp(): bool
    {
        return $this->vertical(-max(1, $this->surface->rows() - 1), false);
    }

    /** Cursor one screen down. */
    public function pageDown(): bool
    {
        return $this->vertical(max(1, $this->surface->rows() - 1), false);
    }

    /** Cursor to the start of the word before it. */
    public function wordLeft(): void
    {
        $this->moveTo(...[...$this->wordStart(), false]);
    }

    /** Cursor to the end of the word after it. */
    public function wordRight(): void
    {
        $this->moveTo(...[...$this->wordEnd(), false]);
    }

    /** Extend the selection one character left. */
    public function selectLeft(): void
    {
        $this->step(-1, true);
    }

    /** Extend the selection one character right. */
    public function selectRight(): void
    {
        $this->step(1, true);
    }

    /** Extend the selection one row up. */
    public function selectUp(): void
    {
        $this->vertical(-1, true) || $this->moveTo($this->row, 0, true);
    }

    /** Extend the selection one row down. */
    public function selectDown(): void
    {
        $this->vertical(1, true) || $this->moveTo($this->row, \PHP_INT_MAX, true);
    }

    /** Extend the selection to the start of the line. */
    public function selectLineStart(): void
    {
        $this->moveTo($this->row, 0, true);
    }

    /** Extend the selection to the end of the line. */
    public function selectLineEnd(): void
    {
        $this->moveTo($this->row, \PHP_INT_MAX, true);
    }

    /** Extend the selection to the start of the word before. */
    public function selectWordLeft(): void
    {
        $this->moveTo(...[...$this->wordStart(), true]);
    }

    /** Extend the selection to the end of the word after. */
    public function selectWordRight(): void
    {
        $this->moveTo(...[...$this->wordEnd(), true]);
    }

    /** Select the whole text. */
    public function selectAll(): void
    {
        $this->select(0, 0, \PHP_INT_MAX, \PHP_INT_MAX);
    }

    /** Drop the selection; false when there was none (Escape goes on to the parent then). */
    public function clearSelection(): bool
    {
        if ($this->completion() !== null) {
            $this->completion->close();
            return true;
        }
        if ($this->selection() === null) {
            return false;
        }
        $this->anchor = null;
        return true;
    }

    /** Delete the selection, or the character before the cursor. */
    public function deleteBack(): void
    {
        if (!$this->deleteSelection('delete')) {
            [$r, $c] = $this->offset($this->row, $this->col, -1);
            $this->edit('delete', $r, $c, $this->row, $this->col, '');
        }
    }

    /** Delete the selection, or the character after the cursor. */
    public function deleteForward(): void
    {
        if (!$this->deleteSelection('delete')) {
            [$r, $c] = $this->offset($this->row, $this->col, 1);
            $this->edit('delete', $this->row, $this->col, $r, $c, '');
        }
    }

    /** Kill (cut to the kill ring) the word before the cursor. */
    public function killWordBack(): void
    {
        [$r, $c] = $this->wordStart();
        $this->kill($r, $c, $this->row, $this->col, true);
    }

    /** Kill the word after the cursor. */
    public function killWord(): void
    {
        [$r, $c] = $this->wordEnd();
        $this->kill($this->row, $this->col, $r, $c, false);
    }

    /** Kill to the end of the line (at the end of a line: the line break). */
    public function killToEnd(): void
    {
        $end = \count($this->lines[$this->row]);
        $this->col === $end
            ? $this->kill($this->row, $this->col, ...[...$this->offset($this->row, $this->col, 1), false])
            : $this->kill($this->row, $this->col, $this->row, $end, false);
    }

    /** Kill to the start of the line. */
    public function killToStart(): void
    {
        $this->kill($this->row, 0, $this->row, $this->col, true);
    }

    /** Insert the last killed text at the cursor. */
    public function yank(): void
    {
        if ($this->kills === []) {
            return;
        }
        $this->yankAt = \count($this->kills) - 1;
        $this->insertYank();
    }

    /** Right after a yank: replace the yanked text with the kill before it (cycling). */
    public function yankPop(): void
    {
        if ($this->yanked === null || $this->last !== 'yank' || \count($this->kills) < 2) {
            return;
        }
        [$r1, $c1, $r2, $c2] = $this->yanked;
        $this->yankAt = ($this->yankAt + \count($this->kills) - 1) % \count($this->kills);
        $this->replace($r1, $c1, $r2, $c2, '');
        $this->row = $r1;
        $this->col = $c1;
        $this->insertYank();
    }

    /** Undo the last change. */
    public function undo(): void
    {
        $this->travel($this->undoStack, $this->redoStack);
    }

    /** Redo what undo took back. */
    public function redo(): void
    {
        $this->travel($this->redoStack, $this->undoStack);
    }

    /** Copy the selection to the kill ring and the terminal's clipboard; false without one. */
    public function copy(): bool
    {
        $text = $this->selectedText();
        if ($text === '') {
            return false;
        }
        $this->kills[] = $text;
        $this->surface->write(Ansi::clipboard($text));
        $this->last = 'copy';
        return true;
    }

    /** Cut the selection (copy, then delete); false without one. */
    public function cut(): bool
    {
        if (!$this->copy()) {
            return false;
        }
        $this->deleteSelection('cut');
        return true;
    }

    /**
     * Enter: submit a single-line editor, else start a new line. False for a single-line editor
     * without `onSubmit`: the key goes on (to the dialog or form it is in, which submits).
     */
    public function enter(): bool
    {
        if ($this->completion() !== null) {
            return $this->acceptCompletion();
        }
        if ($this->maxLines === 1) {
            if ($this->onSubmit === null) {
                return false;
            }
            $this->submit();
            return true;
        }
        $this->type("\n");
        return true;
    }

    /** Call `onSubmit` with the text (and remember it, with `history`). */
    public function submit(): void
    {
        $this->last = 'submit';
        if ($this->history && $this->value() !== '' && end($this->past) !== $this->value()) {
            $this->past[] = $this->value();
            $this->past = \array_slice($this->past, -500);
        }
        $this->walk = null;
        $this->call($this->onSubmit, $this->value(), $this);
    }

    /** Type $text at the cursor, over the selection, as typing or pasting it does (one undo step). */
    public function insert(string $text): void
    {
        $this->type($text, 'paste');
    }

    /** The submitted texts remembered with `history`, oldest first. */
    public function history(): array
    {
        return $this->past;
    }

    /** @param list<string> $texts submitted texts to remember, oldest first (to restore a saved history) */
    public function setHistory(array $texts): void
    {
        $this->past = array_values(array_map('strval', $texts));
        $this->walk = null;
    }

    /** Up on the first row with `history`: the text submitted before. False when there is none. */
    public function historyBack(): bool
    {
        if (!$this->history || $this->past === [] || $this->walk === 0) {
            return false;
        }
        if ($this->walk === null) {
            $this->draft = $this->value();
            $this->walk = \count($this->past);
        }
        $this->showPast(--$this->walk);
        return true;
    }

    /** Down on the last row with `history`: the text submitted after, then the one being written. */
    public function historyForward(): bool
    {
        if ($this->walk === null) {
            return false;
        }
        if (++$this->walk >= \count($this->past)) {
            $this->walk = null;
            $this->showPast(null);
        } else {
            $this->showPast($this->walk);
        }
        return true;
    }

    private function showPast(?int $i): void
    {
        $walk = $this->walk;
        $this->setValue($i === null ? $this->draft : $this->past[$i]);
        $this->walk = $walk;
    }

    /**
     * Tab with `complete`: show the candidates for the word before the cursor in a list under
     * it (or accept the one chosen, when the list is open). False when there are none (the key
     * goes on).
     */
    public function completeWord(): bool
    {
        if ($this->completion?->isOpen()) {
            return $this->acceptCompletion();
        }
        return $this->showCompletion(true);
    }

    /** The open completion list, or null. */
    public function completion(): ?Select
    {
        $c = $this->completion?->isOpen() ? $this->completion->content : null;
        return $c instanceof Select ? $c : null;
    }

    private function showCompletion(bool $force): bool
    {
        if ($this->complete === null || !$this->isMounted()) {
            return false;
        }
        $line = $this->lines[$this->row];
        $c = $this->col;
        while ($c > 0 && !\in_array($line[$c - 1], [' ', "\t"], true)) {
            $c--;
        }
        $word = implode('', \array_slice($line, $c, $this->col - $c));
        if ($word === '' && !$force) {
            $this->completion?->close();
            return false;
        }
        $items = [];
        foreach (($this->complete)($word, $this) as $candidate) {
            $items[(string) $candidate] = (string) $candidate;
        }
        if ($items === []) {
            $this->completion?->close();
            return false;
        }
        $this->wordAt = [$this->row, $c];
        if ($this->completion?->isOpen()) {
            $this->completion()->setItems($items);
            return true;
        }
        $cols = max(1, $this->surface->cols());
        [$vr, $vx] = $this->visual($this->row, $c, $cols);
        $left = !$this->wrap || $this->maxLines === 1 ? $this->left : 0;
        $list = new Select($items, filterable: false, onActivate: fn() => $this->acceptCompletion());
        // as large as the candidates (measured every frame: typing that filters them shrinks it), 8 rows and 40 columns at most
        $this->completion = $this->overlay($list->with(style: ['max-height' => 8, 'max-width' => 40]), new \phasync\Tui\Rect($vx - $left, $vr - $this->top, 1, 1), 'below');
        $this->focus();     // the editor keeps the keys; the list follows them
        return true;
    }

    private function acceptCompletion(): bool
    {
        $pick = $this->completion()?->current();
        $this->completion?->close();
        if ($pick === null) {
            return false;
        }
        [$r, $c] = $this->wordAt;
        if ($r === $this->row && $c <= $this->col) {
            $this->edit('paste', $r, $c, $this->row, $this->col, (string) $pick);
        }
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Input

    protected function onKey(Key $key): bool
    {
        if (!$key->isText()) {
            return false;
        }
        $this->type($key->text);
        return true;
    }

    protected function onPaste(Paste $paste): bool
    {
        $this->last = '';
        $this->type($paste->text, 'paste');
        $this->last = 'paste';
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->isWheel()) {
            $this->free = true;
            $this->top = max(0, min($this->top + ($mouse->action === Mouse::WHEEL_UP ? -3 : 3), $this->visualRows($this->surface->cols()) - 1));
            return true;
        }
        if ($mouse->button !== Mouse::LEFT && $mouse->action !== Mouse::RELEASE) {
            return false;
        }
        [$r, $c] = $this->at($mouse->x, $mouse->y);
        if ($mouse->action === Mouse::PRESS) {
            $this->focus();
            $this->anchor = null;
            $this->moveTo($r, $c);
            $this->anchor = [$this->row, $this->col];
        } elseif ($mouse->action === Mouse::DRAG) {
            $this->moveTo($r, $c, true);
        } elseif ($this->selection() === null) {
            $this->anchor = null;
        }
        return true;
    }

    /** Insert $text at the cursor, over the selection. */
    private function type(string $text, string $kind = 'type'): void
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
        $text = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/', '', $text);
        if ($this->maxLines === 1) {
            $text = str_replace("\n", ' ', $text);     // (only a single-line field joins lines)
        }
        $sel = $this->selection();
        [$r1, $c1, $r2, $c2] = $sel ?? [$this->row, $this->col, $this->row, $this->col];
        // typing in one go is one undo step, up to a space or a line break
        $step = $kind === 'type' && ($sel !== null || preg_match('/[\s]/', $text)) ? 'space' : $kind;
        $this->edit($step, $r1, $c1, $r2, $c2, $text);
    }

    // ------------------------------------------------------------------------------------------
    // Editing

    /** Replace the text between two positions with $text as one undo step of $kind. */
    private function edit(string $kind, int $r1, int $c1, int $r2, int $c2, string $text): void
    {
        if ([$r1, $c1] === [$r2, $c2] && $text === '') {
            return;
        }
        $this->remember($kind);
        [$this->row, $this->col] = $this->replace($r1, $c1, $r2, $c2, $text);
        $this->anchor = null;
        $this->changed();
    }

    /** @return array{int,int} the position after the inserted text */
    private function replace(int $r1, int $c1, int $r2, int $c2, string $text): array
    {
        $before = \array_slice($this->lines[$r1], 0, $c1);
        $after = \array_slice($this->lines[$r2], $c2);
        $new = $this->split($text);
        $last = \count($new) - 1;
        $endCol = \count($new[$last]) + ($last === 0 ? \count($before) : 0);
        $new[0] = [...$before, ...$new[0]];
        $new[$last] = [...$new[$last], ...$after];
        array_splice($this->lines, $r1, $r2 - $r1 + 1, $new);
        return [$r1 + $last, $endCol];
    }

    private function deleteSelection(string $kind): bool
    {
        $sel = $this->selection();
        if ($sel === null) {
            return false;
        }
        $this->edit($kind, ...[...$sel, '']);
        return true;
    }

    private function kill(int $r1, int $c1, int $r2, int $c2, bool $backwards): void
    {
        if ($this->deleteSelection('kill')) {
            return;
        }
        $text = $this->textBetween($r1, $c1, $r2, $c2);
        if ($text === '') {
            return;
        }
        if ($this->last === 'kill' && $this->kills !== []) {
            $top = array_pop($this->kills);
            $this->kills[] = $backwards ? $text . $top : $top . $text;
        } else {
            $this->kills[] = $text;
            $this->kills = \array_slice($this->kills, -30);
        }
        $this->edit('kill', $r1, $c1, $r2, $c2, '');
        $this->last = 'kill';
    }

    private function insertYank(): void
    {
        $from = [$this->row, $this->col];
        $this->type($this->kills[$this->yankAt], 'yank');
        $this->yanked = [...$from, $this->row, $this->col];
        $this->last = 'yank';
    }

    /** Push the state before a change of $kind on the undo stack (typing in a row is one step). */
    private function remember(string $kind): void
    {
        if (!($kind === 'type' && $this->last === 'type')) {
            $this->undoStack[] = [$this->lines, $this->row, $this->col];
            $this->undoStack = \array_slice($this->undoStack, -200);
        }
        $this->redoStack = [];
        $this->last = $kind;
    }

    /** @param list<array{list<list<string>>, int, int}> $from */
    private function travel(array &$from, array &$to): void
    {
        if ($from === []) {
            return;
        }
        $to[] = [$this->lines, $this->row, $this->col];
        [$this->lines, $this->row, $this->col] = array_pop($from);
        $this->anchor = null;
        $this->last = 'undo';
        $this->changed();
    }

    private function changed(): void
    {
        if ($this->walk !== null && $this->last !== 'set') {
            $this->walk = null;     // (edited: no longer a past text)
        }
        $this->goal = null;
        $this->free = false;
        if ($this->completion() !== null) {
            $this->showCompletion(false);      // an edit (typing, deleting) filters the open list again
        }
        $this->call($this->onChange, $this->value(), $this);
        $this->emit('change', $this);
        $this->requestRender();
    }

    // ------------------------------------------------------------------------------------------
    // Positions

    /** @return list<list<string>> */
    private function split(string $text): array
    {
        return array_map(fn(string $line) => array_map(fn(array $c) => $c[0], Text::clusters($line)), explode("\n", $text));
    }

    /** @return array{int,int,int,int}|null the selection, start first */
    private function selection(): ?array
    {
        if ($this->anchor === null || $this->anchor === [$this->row, $this->col]) {
            return null;
        }
        $a = $this->anchor;
        $b = [$this->row, $this->col];
        return $a <= $b ? [...$a, ...$b] : [...$b, ...$a];
    }

    private function textBetween(int $r1, int $c1, int $r2, int $c2): string
    {
        if ($r1 === $r2) {
            return implode('', \array_slice($this->lines[$r1], $c1, $c2 - $c1));
        }
        $out = [implode('', \array_slice($this->lines[$r1], $c1))];
        for ($r = $r1 + 1; $r < $r2; $r++) {
            $out[] = implode('', $this->lines[$r]);
        }
        $out[] = implode('', \array_slice($this->lines[$r2], 0, $c2));
        return implode("\n", $out);
    }

    /** @return array{int,int} the position $n characters (line breaks count one) from the given one */
    private function offset(int $r, int $c, int $n): array
    {
        while ($n < 0) {
            if ($c > 0) {
                $c--;
            } elseif ($r > 0) {
                $c = \count($this->lines[--$r]);
            }
            $n++;
        }
        while ($n > 0) {
            if ($c < \count($this->lines[$r])) {
                $c++;
            } elseif ($r < \count($this->lines) - 1) {
                $r++;
                $c = 0;
            }
            $n--;
        }
        return [$r, $c];
    }

    private function step(int $n, bool $select): void
    {
        $sel = $this->selection();
        if (!$select && $sel !== null) {
            // an arrow without Shift collapses the selection to its side
            $this->anchor = null;
            $this->moveTo(...($n < 0 ? [$sel[0], $sel[1]] : [$sel[2], $sel[3]]));
            return;
        }
        $this->moveTo(...[...$this->offset($this->row, $this->col, $n), $select]);
    }

    private function moveTo(int $r, int $c, bool $select = false): void
    {
        if ($select) {
            $this->anchor ??= [$this->row, $this->col];
        } elseif ($this->selection() !== null || $this->anchor !== null) {
            $this->anchor = null;
        }
        $this->row = max(0, min(\count($this->lines) - 1, $r));
        $this->col = max(0, min(\count($this->lines[$this->row]), $c));
        $this->goal = null;
        $this->free = false;
        $this->last = 'move';
        $this->requestRender();
    }

    /** Move $n visual rows, keeping the visual column; false when there is no such row. */
    private function vertical(int $n, bool $select): bool
    {
        if ($this->maxLines === 1) {
            return false;
        }
        $cols = max(1, $this->surface->cols());
        [$vr, $vx] = $this->visual($this->row, $this->col, $cols);
        $target = $vr + $n;
        $total = $this->visualRows($cols);
        if ($target < 0 || $target >= $total) {
            if ($select || \abs($n) === 1) {
                return false;
            }
            $target = max(0, min($total - 1, $target));
        }
        $goal = $this->goal ?? $vx;
        [$r, $c] = $this->fromVisual($target, $goal, $cols);
        $this->moveTo($r, $c, $select);
        $this->goal = $goal;
        return true;
    }

    /** @return array{int,int} the start of the word before the cursor */
    private function wordStart(): array
    {
        [$r, $c] = [$this->row, $this->col];
        if ($c === 0 && $r > 0) {
            $c = \count($this->lines[--$r]);     // on to the line above
        }
        $line = $this->lines[$r];
        while ($c > 0 && !self::isWord($line[$c - 1])) {
            $c--;
        }
        while ($c > 0 && self::isWord($line[$c - 1])) {
            $c--;
        }
        return [$r, $c];
    }

    /** @return array{int,int} the end of the word after the cursor */
    private function wordEnd(): array
    {
        [$r, $c] = [$this->row, $this->col];
        if ($c === \count($this->lines[$r]) && $r < \count($this->lines) - 1) {
            [$r, $c] = [$r + 1, 0];     // on to the line below
        }
        $line = $this->lines[$r];
        $n = \count($line);
        while ($c < $n && !self::isWord($line[$c])) {
            $c++;
        }
        while ($c < $n && self::isWord($line[$c])) {
            $c++;
        }
        return [$r, $c];
    }

    private static function isWord(string $cluster): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}_]/u', $cluster);
    }

    // ------------------------------------------------------------------------------------------
    // Layout: visual rows

    /**
     * The rows line $r takes at $cols columns: start and end (exclusive) cluster indexes.
     *
     * @return list<array{int,int}>
     */
    private function rowsOf(int $r, int $cols): array
    {
        $line = $this->lines[$r];
        $n = \count($line);
        if (!$this->wrap || $this->maxLines === 1 || $n === 0) {
            return [[0, $n]];
        }
        $rows = [];
        $start = 0;
        $width = 0;
        $space = null;      // the index after the last space in the row being laid out
        for ($i = 0; $i < $n; $i++) {
            $w = Text::clusterWidth($line[$i]);
            if ($width + $w > $cols && $i > $start) {
                if ($line[$i] === ' ') {
                    $rows[] = [$start, $i + 1];     // a space at the edge hangs
                    $start = $i + 1;
                    $width = 0;
                    $space = null;
                    continue;
                }
                $cut = $space ?? $i;
                $rows[] = [$start, $cut];
                $start = $cut;
                $width = 0;
                for ($j = $start; $j < $i; $j++) {
                    $width += Text::clusterWidth($line[$j]);
                }
                $space = null;
            }
            $width += $w;
            if ($line[$i] === ' ') {
                $space = $i + 1;
            }
        }
        $rows[] = [$start, $n];
        return $rows;
    }

    private function visualRows(int $cols): int
    {
        $n = 0;
        foreach (array_keys($this->lines) as $r) {
            $n += \count($this->rowsOf($r, $cols));
        }
        return $n;
    }

    /** @return array{int,int} the visual row and column of a position */
    private function visual(int $r, int $c, int $cols): array
    {
        $vr = 0;
        for ($i = 0; $i < $r; $i++) {
            $vr += \count($this->rowsOf($i, $cols));
        }
        $rows = $this->rowsOf($r, $cols);
        foreach ($rows as $k => [$s, $e]) {
            if ($c < $e || $k === \count($rows) - 1) {
                return [$vr + $k, $this->width($r, $s, $c)];
            }
        }
        return [$vr, 0];
    }

    /** @return array{int,int} the position at visual row $vr, visual column $x */
    private function fromVisual(int $vr, int $x, int $cols): array
    {
        foreach (array_keys($this->lines) as $r) {
            $rows = $this->rowsOf($r, $cols);
            if ($vr < \count($rows)) {
                [$s, $e] = $rows[$vr];
                $last = $vr === \count($rows) - 1;
                $c = $s;
                $w = 0;
                while ($c < $e && $w + Text::clusterWidth($this->lines[$r][$c]) <= $x) {
                    $w += Text::clusterWidth($this->lines[$r][$c]);
                    $c++;
                }
                // (on a wrapped row the cursor stays before the row's last character)
                return [$r, !$last && $c === $e ? max($s, $e - 1) : $c];
            }
            $vr -= \count($rows);
        }
        return [\count($this->lines) - 1, \count(end($this->lines))];
    }

    /** The width of the clusters $from..$to-1 of line $r. */
    private function width(int $r, int $from, int $to): int
    {
        $w = 0;
        for ($i = $from; $i < $to; $i++) {
            $w += Text::clusterWidth($this->lines[$r][$i]);
        }
        return $w;
    }

    /** @return array{int,int} the position under cell ($x, $y) of the surface */
    private function at(int $x, int $y): array
    {
        $cols = max(1, $this->surface->cols());
        $y = max(0, min($this->surface->rows() - 1, $y));      // (a drag held above or below it)
        return $this->fromVisual($this->top + $y, $x + ($this->wrap && $this->maxLines !== 1 ? 0 : $this->left), $cols);
    }

    // ------------------------------------------------------------------------------------------

    /** One row with `maxLines: 1`; its text's rows up to `maxLines`; null (the room given) for any number of lines. */
    public function measure(int $cols, int $rows): ?array
    {
        if ($this->maxLines === 1) {
            return [$cols, 1];
        }
        if ($this->maxLines === 0) {
            return null;
        }
        return [$cols, max(1, min($this->visualRows(max(1, $cols)), $this->maxLines))];
    }

    protected function render(): null
    {
        $cols = max(1, $this->surface->cols());
        $height = max(1, $this->surface->rows());
        $base = $this->style();
        $focused = $this->hasFocus();
        [$cr, $cx] = $this->visual($this->row, $this->col, $cols);
        $scrolls = !$this->wrap || $this->maxLines === 1;
        if (!$this->free) {
            $this->top = min(max($this->top, $cr - $height + 1), $cr, max(0, $this->visualRows($cols) - $height));
            if ($scrolls) {
                $this->left = min(max($this->left, $cx - $cols + 1), $cx);
            }
        }
        $left = $scrolls ? $this->left : 0;
        $out = "\e[0m\e[?7l" . Ansi::style($base->text) . "\e[2J";
        if ($this->lines === [[]] && $this->placeholder !== '') {
            $out .= Ansi::moveTo(0, 0) . Ansi::style($this->style('placeholder')->text) . Text::truncate($this->placeholder, $cols);
        } else {
            $sel = $this->selection();
            $selStyle = Ansi::style($this->style('selection')->text);
            $plain = Ansi::style($base->text);
            $vr = 0;
            foreach ($this->lines as $r => $line) {
                foreach ($this->rowsOf($r, $cols) as [$s, $e]) {
                    $y = $vr++ - $this->top;
                    if ($y < 0 || $y >= $height) {
                        continue;
                    }
                    $out .= Ansi::moveTo(0, $y);
                    $x = 0;
                    $inSel = false;
                    for ($c = $s; $c < $e; $c++) {
                        $w = Text::clusterWidth($line[$c]);
                        if ($x + $w <= $left) {
                            $x += $w;
                            continue;
                        }
                        if ($x - $left + $w > $cols) {
                            break;
                        }
                        $selected = $sel !== null && [$r, $c] >= [$sel[0], $sel[1]] && [$r, $c] < [$sel[2], $sel[3]];
                        if ($selected !== $inSel) {
                            $out .= $selected ? $selStyle : $plain;
                            $inSel = $selected;
                        }
                        $out .= $x < $left ? ' ' : $line[$c];
                        $x += $w;
                    }
                    // a selected line break shows as one selected cell
                    if ($sel !== null && $e === \count($line) && $r < $sel[2] && [$r, $e] >= [$sel[0], $sel[1]] && $x - $left < $cols) {
                        $out .= $selStyle . ' ';
                    }
                    $out .= $plain;
                }
            }
        }
        $x = max(0, min($cols - 1, $cx - $left));
        $y = $cr - $this->top;
        $visible = $focused && $y >= 0 && $y < $height && !$this->attribute('disabled');
        $out .= "\e[0m\e[?7h" . Ansi::moveTo($x, max(0, min($height - 1, $y))) . ($visible ? "\e[?25h" : "\e[?25l");
        $this->surface->write($out);
        return null;
    }
}
