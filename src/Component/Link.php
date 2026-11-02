<?php

namespace phasync\Tui\Component;

use phasync\Tui\Ansi;
use phasync\Tui\Component;
use phasync\Tui\Markup;
use phasync\Tui\Mouse;
use phasync\Tui\Text;

/**
 * A hyperlink: underlined text that a terminal with hyperlink support (OSC 8) opens with a
 * Ctrl-click, and that a program can act on itself.
 *
 *     new Link('https://example.com/docs', 'the documentation')
 *     new Link('https://example.com', onActivate: fn(string $url) => $this->open($url))
 *
 * The text is `$label` (markup allowed; default the URL) drawn with the URL attached to its cells
 * (see `Ansi::link()`; a terminal without hyperlinks shows the text alone). It takes the focus;
 * Enter or a click calls `onActivate($url, $link)` and emits `activate`, which is how a program
 * opens it itself (`xdg-open`, a browser pane), since a terminal that reports the mouse to the
 * program does not follow links by itself. One row, as wide as the text. All of `$url`, `$label`
 * are props.
 *
 * **Style**: `Link` (the default theme: accent colour, underlined), states `:focused` (inverse),
 * `:hover` and `:disabled`.
 */
class Link extends Component
{
    /**
     * @param (\Closure(string $url, Link $link): void)|null $onActivate
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        public string $url = '',
        public ?string $label = null,
        private readonly ?\Closure $onActivate = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
    }

    protected function bindings(): array
    {
        return ['enter' => 'activate'];
    }

    /** Call `onActivate` (Enter, a click); false when disabled. */
    public function activate(): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        $this->call($this->onActivate, $this->url, $this);
        $this->emit('activate', $this);
        return true;
    }

    protected function onMouse(Mouse $mouse): bool
    {
        if ($this->attribute('disabled')) {
            return false;
        }
        if ($mouse->action === Mouse::PRESS && $mouse->button === Mouse::LEFT) {
            $this->focus();
            $this->activate();
        }
        return true;
    }

    private function text(): string
    {
        return $this->label ?? $this->url;
    }

    public function measure(int $cols, int $rows): ?array
    {
        return [min($cols, Text::width(Markup::plain($this->text()))), 1];
    }

    protected function render(): null
    {
        $line = Ansi::style($this->style()->text) . Ansi::hyperlink($this->markup($this->text()), $this->url);
        $this->surface->write("\e[?25l\e[2J\e[H" . Text::truncate($line, $this->surface->cols()));
        return null;
    }
}
