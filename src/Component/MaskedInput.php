<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Key;
use phasync\Tui\Mouse;
use phasync\Tui\Paste;
use phasync\Tui\Text;

/**
 * A one-line field that shows a mask instead of what is typed: a password, a token, a PIN.
 *
 *     new MaskedInput(placeholder: 'password', onSubmit: fn(string $secret) => $this->login($secret))
 *     new MaskedInput(mask: '*', maxLength: 6, revealable: false)
 *
 * Each character typed (a grapheme cluster: an emoji is one) shows as `$mask`. Left/Right,
 * Home/End move, Backspace and Delete erase, Ctrl-U and Ctrl-K erase to the start and end; no word
 * motions or copy (they would tell the length of the words, or leak the secret). Enter calls
 * `onSubmit($value, $input)` (without one the key goes on, so a `Form` submits). Alt-R, or a click
 * on the sign at the right end (`◎` hidden, `◉` shown), reveals the text; the sign is left out
 * with `revealable: false`. Pasted text is inserted, line breaks as spaces. `maxLength` (0: none)
 * limits the characters. `onChange($value, $input)` is called after every edit and the event
 * `change` emitted. In a `Form`, its value is `value()`. One row; it takes the width it is given.
 * `$mask`, `$placeholder`, `$maxLength`, `$revealable` are props.
 *
 * **Style**: `MaskedInput` (the default theme gives the focused field the `$panel` background),
 * parts `MaskedInput::placeholder` and `MaskedInput::reveal`; states `:focused`, `:disabled`.
 */
class MaskedInput extends Component implements Field
{
    /** @var list<string> the value as grapheme clusters */
    private array $chars = [];
    private int $cursor = 0;
    private int $scroll = 0;
    private bool $revealed = false;

    /**
     * @param (\Closure(string $value, MaskedInput $input): void)|null $onSubmit
     * @param (\Closure(string $value, MaskedInput $input): void)|null $onChange
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        string $value = '',
        public string $mask = '•',
        public string $placeholder = '',
        public int $maxLength = 0,
        public bool $revealable = true,
        private readonly ?\Closure $onSubmit = null,
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        if ($mask === '' || Text::width($mask) !== 1) {
            throw new \InvalidArgumentException('The mask of a MaskedInput must be one character wide');
        }
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $this->chars = self::split($value);
        $this->cursor = \count($this->chars);
    }

    protected function bindings(): array
    {
        return [
            'left' => 'left', 'right' => 'right', 'home' => 'lineStart', 'end' => 'lineEnd',
            'backspace' => 'deleteBack', 'delete' => 'deleteForward', 'ctrl+u' => 'killToStart', 'ctrl+k' => 'killToEnd',
            'enter' => 'submit', 'alt+r' => 'toggleReveal',
        ];
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $text = preg_replace('/[\x00-\x1f\x7f]/', '', str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text));
        return array_map(static fn(array $c) => $c[0], Text::clusters($text));
    }

    /** The text typed. */
    public function value(): string
    {
        return implode('', $this->chars);
    }

    /** Replace the text (the cursor goes to the end; `$onChange` is not called). */
    public function setValue(string $value): void
    {
        $this->chars = self::split($value);
        if ($this->maxLength > 0) {
            $this->chars = \array_slice($this->chars, 0, $this->maxLength);
        }
        $this->cursor = \count($this->chars);
        $this->requestRender();
    }

    /** The cursor position, in characters from the start. */
    public function cursor(): int
    {
        return $this->cursor;
    }

    /** True while the text is shown instead of the mask. */
    public function isRevealed(): bool
    {
        return $this->revealed;
    }

    public function setRevealed(bool $revealed): void
    {
        $this->revealed = $revealed;
        $this->requestRender();
    }

    private function usable(): bool
    {
        return !$this->attribute('disabled');
    }

    private function moveTo(int $to): bool
    {
        if (!$this->usable()) {
            return false;
        }
        $this->cursor = max(0, min(\count($this->chars), $to));
        $this->requestRender();
        return true;
    }

    private function edited(): bool
    {
        $this->call($this->onChange, $this->value(), $this);
        $this->emit('change', $this);
        $this->requestRender();
        return true;
    }

    public function left(): bool { return $this->moveTo($this->cursor - 1); }
    public function right(): bool { return $this->moveTo($this->cursor + 1); }
    public function lineStart(): bool { return $this->moveTo(0); }
    public function lineEnd(): bool { return $this->moveTo(\PHP_INT_MAX); }

    public function deleteBack(): bool
    {
        if (!$this->usable()) {
            return false;
        }
        if ($this->cursor > 0) {
            array_splice($this->chars, --$this->cursor, 1);
            $this->edited();
        }
        return true;
    }

    public function deleteForward(): bool
    {
        if (!$this->usable()) {
            return false;
        }
        if ($this->cursor < \count($this->chars)) {
            array_splice($this->chars, $this->cursor, 1);
            $this->edited();
        }
        return true;
    }

    public function killToStart(): bool
    {
        if (!$this->usable()) {
            return false;
        }
        array_splice($this->chars, 0, $this->cursor);
        $this->cursor = 0;
        return $this->edited();
    }

    public function killToEnd(): bool
    {
        if (!$this->usable()) {
            return false;
        }
        array_splice($this->chars, $this->cursor);
        return $this->edited();
    }

    /** Enter: `onSubmit`; false without one (the key goes on to a form or dialog). */
    public function submit(): bool
    {
        if (!$this->usable() || $this->onSubmit === null) {
            return false;
        }
        $this->call($this->onSubmit, $this->value(), $this);
        return true;
    }

    /** Show or hide the text (Alt-R); false when not revealable or disabled. */
    public function toggleReveal(): bool
    {
        if (!$this->usable() || !$this->revealable) {
            return false;
        }
        $this->setRevealed(!$this->revealed);
        return true;
    }

    /** Type $text at the cursor (as a paste does). */
    public function insert(string $text): void
    {
        if (!$this->usable()) {
            return;
        }
        $new = self::split($text);
        if ($this->maxLength > 0) {
            $new = \array_slice($new, 0, max(0, $this->maxLength - \count($this->chars)));
        }
        if ($new === []) {
            return;
        }
        array_splice($this->chars, $this->cursor, 0, $new);
        $this->cursor += \count($new);
        $this->edited();
    }

    protected function onKey(Key $key): bool
    {
        if ($key->isText() && $this->usable()) {
            $this->insert($key->text);
            return true;
        }
        return false;
    }

    protected function onPaste(Paste $paste): bool
    {
        $this->insert($paste->text);
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if (!$this->usable()) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $cols = $this->surface->cols();
            if ($this->revealable && $mouse->x >= $cols - 1) {
                $this->setRevealed(!$this->revealed);
            } else {
                $x = 0;
                for ($i = $this->scroll; $i < \count($this->chars); $i++) {
                    $x += $this->cellWidth($i);
                    if ($mouse->x < $x) {
                        return $this->moveTo($i) || true;
                    }
                }
                $this->moveTo(\count($this->chars));
            }
        }
        return true;
    }

    private function cellWidth(int $i): int
    {
        return $this->revealed ? Text::clusterWidth($this->chars[$i]) : 1;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [$cols, 1];
    }

    protected function render(): null
    {
        $cols = $this->surface->cols();
        $room = max(1, $cols - ($this->revealable ? 2 : 0));
        $this->cursor = min($this->cursor, \count($this->chars));
        $widths = array_map(fn(int $i) => $this->cellWidth($i), array_keys($this->chars));
        $this->scroll = min($this->scroll, $this->cursor);
        while ($this->scroll < $this->cursor && array_sum(\array_slice($widths, $this->scroll, $this->cursor - $this->scroll)) + 1 > $room) {
            $this->scroll++;
        }
        $out = Ansi::style($this->style()->text) . "\e[2J\e[H";
        if ($this->chars === [] && $this->placeholder !== '') {
            $out .= Ansi::style($this->style('placeholder')->text) . Text::truncate($this->placeholder, $room);
        } else {
            $shown = '';
            $used = 0;
            for ($i = $this->scroll; $i < \count($this->chars) && $used + $widths[$i] <= $room; $i++) {
                $shown .= $this->revealed ? $this->chars[$i] : $this->mask;
                $used += $widths[$i];
            }
            $out .= $shown;
        }
        if ($this->revealable) {
            $out .= Ansi::moveTo($cols - 1, 0) . Ansi::style($this->style('reveal')->text) . ($this->revealed ? '◉' : '◎');
        }
        $x = array_sum(\array_slice($widths, $this->scroll, $this->cursor - $this->scroll));
        $out .= Ansi::moveTo(min($room, $x), 0) . Ansi::style($this->style()->text) . ($this->hasFocus() && $this->usable() ? "\e[?25h" : "\e[?25l");
        $this->surface->write($out);
        return null;
    }
}
