<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;

/**
 * A stack of `Collapsible` sections, by default one open at a time.
 *
 *     new Accordion([
 *         'general' => ['title' => 'General', 'content' => new Checkbox('Autosave')],
 *         'network' => ['title' => 'Network', 'content' => 'Proxy settings go here'],
 *     ], open: 'general', onChange: fn(?string $open) => ...)
 *
 * Each section is `key => ['title' => ..., 'content' => Element|string]`, given once (the first
 * instance's, as `Tabs`' tabs are). The sections are components of the accordion, so each is
 * focused with Tab and toggled as a `Collapsible` is (Enter, Space, a click on its header).
 * With `exclusive: true` (the default) opening a section closes the one that was open;
 * with `exclusive: false` they open independently. `open` names the section(s) open at the
 * start. `onChange($open, $accordion)` gets the keys of the open sections after every change
 * (a list), and the event `change` is emitted.
 *
 * **Style**: `Accordion`; the sections are `Collapsible`s and take their styles.
 */
class Accordion extends Component
{
    /** @var array<string, bool> collapsed, by section key */
    private array $collapsed = [];

    /**
     * @param array<string, array{title: string, content: Element|string|null}> $sections
     * @param string|list<string>|null $open the section(s) open at the start
     * @param (\Closure(list<string> $open, Accordion $accordion): void)|null $onChange
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        private readonly array $sections = [],
        string|array|null $open = null,
        public bool $exclusive = true,
        private readonly ?\Closure $onChange = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
        $open = (array) $open;
        if ($exclusive) {
            $open = \array_slice($open, 0, 1);
        }
        foreach (array_keys($sections) as $k) {
            $this->collapsed[(string) $k] = !\in_array((string) $k, $open, true);
        }
    }

    /** @return list<string> the keys of the open sections */
    public function open(): array
    {
        return array_keys(array_filter($this->collapsed, static fn(bool $c) => !$c));
    }

    /** Open or close a section (closing the others when exclusive and opening). */
    public function setOpen(string $key, bool $open = true): void
    {
        if (!isset($this->collapsed[$key]) || $this->collapsed[$key] === !$open) {
            return;
        }
        if (($section = $this->child("s:$key")) !== null) {
            $section->setCollapsed(!$open);     // (its onToggle gets back to toggled())
        } else {
            $this->toggled($key, !$open);
        }
    }

    private function toggled(string $key, bool $collapsed): void
    {
        if ($this->collapsed[$key] === $collapsed) {
            return;
        }
        $this->collapsed[$key] = $collapsed;
        if (!$collapsed && $this->exclusive) {
            foreach ($this->collapsed as $k => $_) {
                if ($k !== $key && !$this->collapsed[$k]) {
                    $this->collapsed[$k] = true;
                    $this->child("s:$k")?->setCollapsed(true);
                }
            }
        }
        $this->call($this->onChange, $this->open(), $this);
        $this->emit('change', $this);
        $this->requestRender();
    }

    protected function render(): Element
    {
        $items = [];
        foreach ($this->sections as $k => $section) {
            $k = (string) $k;
            $items[] = $this->child("s:$k", fn() => new Collapsible(
                $section['title'],
                $section['content'] ?? null,
                collapsed: $this->collapsed[$k],
                onToggle: fn(bool $collapsed) => $this->toggled($k, $collapsed),
                disabled: (bool) $this->attribute('disabled'),
            ));
        }
        return new Column($items);
    }
}
