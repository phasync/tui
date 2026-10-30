<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Key;
use phasync\Tui\Paste;
use phasync\Tui\Style;
use phasync\Tui\Text;

/**
 * A single line of editable text, with a cursor, for commands, search fields and forms.
 *
 *     $input = $this->child('input', fn() => new TextInput(
 *         prompt: '> ',
 *         placeholder: 'type a command',
 *         onSubmit: function (string $line, TextInput $input) { ...; $input->setValue(''); },
 *         history: true,
 *     ));
 *
 * Enter submits: `$onSubmit` receives the text (the application decides whether to clear the
 * field, with `setValue('')`). With `$history`, every submitted line is remembered, and Up/Down
 * walk back and forth through them as in a shell (the line being typed is kept and comes back
 * after the newest); `history()` and `setHistory()` let a program save and restore it.
 *
 * Editing keys, as in a shell: Left/Right (Ctrl-B/Ctrl-F), Home/End (Ctrl-A/Ctrl-E),
 * Ctrl-Left/Ctrl-Right or Alt-B/Alt-F by word, Backspace and Delete (Ctrl-D), Ctrl-W or
 * Alt-Backspace (delete the word before), Ctrl-U and Ctrl-K (delete to the start / end), Enter
 * (submit). Pasted text is inserted, line breaks as spaces. Characters are edited as grapheme
 * clusters, so an emoji or an accented letter is one step. The text scrolls sideways when longer
 * than the field; the cursor shows while the field has the focus. Uses the first row of its area.
 */
final class TextInput extends Component
{
    /** @var list<string> the value as grapheme clusters */
    private array $chars = [];
    private int $cursor = 0;
    private int $scroll = 0;
    /** @var list<string> submitted lines, oldest first */
    private array $entries = [];
    /** The history entry shown, or null while editing a new line. */
    private ?int $browsing = null;
    private string $draft = '';

    /**
     * @param string     $value       the initial text
     * @param string     $prompt      shown before the text, not editable
     * @param string     $placeholder shown (dimmed) while the field is empty
     * @param (\Closure(string $value, TextInput $input): void)|null $onSubmit called on Enter
     * @param (\Closure(string $value, TextInput $input): void)|null $onChange called after every edit
     * @param Style|null $style       the style of the text
     * @param bool       $history     remember submitted lines and walk through them with Up/Down
     */
    public function __construct(
        string $value = '',
        private string $prompt = '',
        private string $placeholder = '',
        private readonly ?\Closure $onSubmit = null,
        private readonly ?\Closure $onChange = null,
        private readonly ?Style $style = null,
        private readonly bool $history = false,
    ) {
        $this->setChars($value);
        $this->cursor = \count($this->chars);
    }

    /** The current text. */
    public function value(): string
    {
        return implode('', $this->chars);
    }

    /** Replace the text; the cursor goes to the end. */
    public function setValue(string $value): void
    {
        $this->setChars($value);
        $this->cursor = \count($this->chars);
        $this->requestRender();
    }

    /**
     * The submitted lines, oldest first (empty without `$history`).
     *
     * @return list<string>
     */
    public function history(): array
    {
        return $this->entries;
    }

    /**
     * Replace the remembered lines (oldest first), for example with those saved by an earlier
     * run of the program.
     *
     * @param list<string> $lines
     */
    public function setHistory(array $lines): void
    {
        $this->entries = array_values(array_map('strval', $lines));
        $this->browsing = null;
    }

    /** The cursor position, in characters (grapheme clusters) from the start. */
    public function cursor(): int
    {
        return $this->cursor;
    }

    /** Move the cursor to character $position (clamped to the text). */
    public function setCursor(int $position): void
    {
        $this->cursor = max(0, min(\count($this->chars), $position));
        $this->requestRender();
    }

    /** Change the prompt shown before the text. */
    public function setPrompt(string $prompt): void
    {
        $this->prompt = $prompt;
        $this->requestRender();
    }

    /** Show a history entry (or the draft) for editing. */
    private function show(string $value): void
    {
        $this->setChars($value);
        $this->cursor = \count($this->chars);
        $this->changed(true);
    }

    private function setChars(string $value): void
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        $this->chars = array_map(fn(array $c) => $c[0], Text::clusters(preg_replace('/[\x00-\x1f\x7f]/', '', $value)));
    }

    protected function onKey(Key $key): bool
    {
        $n = \count($this->chars);
        $c = $this->cursor;
        $edit = true;
        switch (true) {
            case $key->is('enter'):
                $value = $this->value();
                if ($this->history && $value !== '' && end($this->entries) !== $value) {
                    $this->entries[] = $value;
                }
                $this->browsing = null;
                if ($this->onSubmit !== null) {
                    ($this->onSubmit)($value, $this);
                }
                return true;
            case $this->history && $key->is('up'):
                if ($this->entries !== [] && $this->browsing !== 0) {
                    if ($this->browsing === null) {
                        $this->draft = $this->value();
                    }
                    $this->browsing = ($this->browsing ?? \count($this->entries)) - 1;
                    $this->show($this->entries[$this->browsing]);
                }
                return true;
            case $this->history && $key->is('down'):
                if ($this->browsing !== null) {
                    $this->browsing++;
                    if ($this->browsing >= \count($this->entries)) {
                        $this->browsing = null;
                    }
                    $this->show($this->browsing === null ? $this->draft : $this->entries[$this->browsing]);
                }
                return true;
            case $key->is('left') || $key->is('ctrl+b'):
                $this->cursor = max(0, $c - 1);
                $edit = false;
                break;
            case $key->is('right') || $key->is('ctrl+f'):
                $this->cursor = min($n, $c + 1);
                $edit = false;
                break;
            case $key->is('home') || $key->is('ctrl+a'):
                $this->cursor = 0;
                $edit = false;
                break;
            case $key->is('end') || $key->is('ctrl+e'):
                $this->cursor = $n;
                $edit = false;
                break;
            case $key->is('ctrl+left') || $key->is('alt+b'):
                $this->cursor = $this->wordStart($c);
                $edit = false;
                break;
            case $key->is('ctrl+right') || $key->is('alt+f'):
                $this->cursor = $this->wordEnd($c);
                $edit = false;
                break;
            case $key->is('backspace') || $key->is('ctrl+h'):
                if ($c > 0) {
                    array_splice($this->chars, $c - 1, 1);
                    $this->cursor--;
                }
                break;
            case $key->is('delete') || $key->is('ctrl+d'):
                if ($c < $n) {
                    array_splice($this->chars, $c, 1);
                }
                break;
            case $key->is('ctrl+w') || $key->is('alt+backspace'):
                $start = $this->wordStart($c);
                array_splice($this->chars, $start, $c - $start);
                $this->cursor = $start;
                break;
            case $key->is('ctrl+u'):
                array_splice($this->chars, 0, $c);
                $this->cursor = 0;
                break;
            case $key->is('ctrl+k'):
                array_splice($this->chars, $c);
                break;
            case $key->isText():
                $this->insert($key->text);
                return true;
            default:
                return false;
        }
        $this->changed($edit);
        return true;
    }

    protected function onPaste(Paste $paste): bool
    {
        $this->insert($paste->text);
        return true;
    }

    private function insert(string $text): void
    {
        $before = $this->chars;
        $this->setChars($text);
        $new = $this->chars;
        $this->chars = [...\array_slice($before, 0, $this->cursor), ...$new, ...\array_slice($before, $this->cursor)];
        $this->cursor += \count($new);
        $this->changed(true);
    }

    private function changed(bool $edit): void
    {
        if ($edit && $this->onChange !== null) {
            ($this->onChange)($this->value(), $this);
        }
        $this->requestRender();
    }

    private function wordStart(int $c): int
    {
        while ($c > 0 && $this->chars[$c - 1] === ' ') {
            $c--;
        }
        while ($c > 0 && $this->chars[$c - 1] !== ' ') {
            $c--;
        }
        return $c;
    }

    private function wordEnd(int $c): int
    {
        $n = \count($this->chars);
        while ($c < $n && $this->chars[$c] === ' ') {
            $c++;
        }
        while ($c < $n && $this->chars[$c] !== ' ') {
            $c++;
        }
        return $c;
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $prompt = Text::truncate($this->prompt, max(0, $cols - 1), '');
        $room = max(1, $cols - Text::width($prompt));
        $widths = array_map(Text::clusterWidth(...), $this->chars);
        // scroll sideways so the cursor (one cell after the text at the end) stays in the field
        $this->scroll = min($this->scroll, $this->cursor);
        while ($this->scroll < $this->cursor && array_sum(\array_slice($widths, $this->scroll, $this->cursor - $this->scroll)) + 1 > $room) {
            $this->scroll++;
        }
        $style = $this->style ?? Style::plain();
        $out = "\e[0m\e[H\e[J" . $prompt;
        if ($this->chars === [] && $this->placeholder !== '') {
            $out .= Ansi::styled(Text::truncate($this->placeholder, $room), $style->with(Style::DIM));
        } else {
            $shown = '';
            $used = 0;
            for ($i = $this->scroll; $i < \count($this->chars) && $used + $widths[$i] <= $room; $i++) {
                $shown .= $this->chars[$i];
                $used += $widths[$i];
            }
            $out .= Ansi::styled($shown, $style);
        }
        $x = Text::width($prompt) + array_sum(\array_slice($widths, $this->scroll, $this->cursor - $this->scroll));
        $out .= Ansi::moveTo(min($cols - 1, $x), 0) . ($this->hasFocus() ? "\e[?25h" : "\e[?25l");
        $this->surface->write($out);
        return null;
    }
}
