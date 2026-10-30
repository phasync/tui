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
 * **Lines.** `maxLines: 1` is a single-line field: Enter submits, pasted line breaks become
 * spaces, the text scrolls sideways, and Up/Down are left to the parent (to move between
 * fields). `maxLines: 0` (the default) takes any number of lines; Enter starts a new line and
 * Alt-Enter (or Ctrl-Enter where the terminal tells it apart) submits. A larger `maxLines`
 * stops new lines at that number. Without a `height`, a single-line editor is one row high and
 * one with a `maxLines` as high as its text up to that; an editor of any number of lines fills
 * the room it is given. With `wrap` (the default) long lines wrap between words, else they are
 * cut at the edge and the view scrolls sideways with the cursor.
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
final class Editor extends Component
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

    /**
     * @param string $value       the initial text
     * @param string $placeholder shown while the editor is empty
     * @param int    $maxLines    1 for a single-line field, 0 for any number of lines, or a limit
     * @param bool   $wrap        wrap long lines between words (else they scroll sideways)
     * @param (\Closure(string $value, Editor $editor): void)|null $onSubmit Enter (one line) or Alt-Enter
     * @param (\Closure(string $value, Editor $editor): void)|null $onChange after every change of the text
     */
    public function __construct(
        string $value = '',
        public string $placeholder = '',
        public int $maxLines = 0,
        public bool $wrap = true,
        private readonly ?\Closure $onSubmit = null,
        private readonly ?\Closure $onChange = null,
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
        return $this->vertical(-1, false);
    }

    /** Cursor one row down; false on the last row. */
    public function down(): bool
    {
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

    /** Enter: submit a single-line editor, else start a new line (up to `maxLines`). */
    public function enter(): void
    {
        if ($this->maxLines === 1) {
            $this->submit();
        } elseif ($this->maxLines === 0 || \count($this->lines) - $this->selectedLines() < $this->maxLines) {
            $this->type("\n");
        }
    }

    /** Call `onSubmit` with the text. */
    public function submit(): void
    {
        $this->last = 'submit';
        $this->call($this->onSubmit, $this->value(), $this);
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
            $text = str_replace("\n", ' ', $text);
        } elseif ($this->maxLines > 1) {
            // line breaks beyond the limit become spaces
            $room = $this->maxLines - (\count($this->lines) - $this->selectedLines());
            $parts = explode("\n", $text);
            $text = implode("\n", \array_slice($parts, 0, $room + 1)) . (\count($parts) > $room + 1 ? ' ' . implode(' ', \array_slice($parts, $room + 1)) : '');
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
        $this->goal = null;
        $this->free = false;
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

    private function selectedLines(): int
    {
        $sel = $this->selection();
        return $sel === null ? 0 : $sel[2] - $sel[0];
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
        return $this->fromVisual($this->top + $y, $x + ($this->wrap && $this->maxLines !== 1 ? 0 : $this->left), $cols);
    }

    // ------------------------------------------------------------------------------------------

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
