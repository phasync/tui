<?php

namespace phasync\Tui\Component;

use phasync\Tui\Component;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Text as TextElement;

/**
 * Pick a file (or a directory) from the file system: a `DirectoryTree` over a line that shows
 * what is highlighted.
 *
 *     new FilePicker('/home/me', extensions: ['png', 'jpg'], onPick: fn(string $path) => $this->load($path))
 *     new FilePicker('/srv', directories: true)
 *
 * Arrow keys, Enter, the mouse and typing to jump work as in `DirectoryTree`. Enter on a file picks
 * it: `onPick($path, $picker)` is called and the event `pick` emitted; with `directories: true`
 * the files are left out and Enter on a directory picks it (and also opens or closes it). `value()`
 * is the path picked last (null before), what a `Form` collects; `current()` is the highlighted one.
 * `extensions` and `showHidden` are as for `DirectoryTree`. The tree fills the room, the line
 * under it is one row (class `muted`). The root and the filters are given once.
 *
 * **Style**: as `Tree`.
 */
class FilePicker extends Component implements Field
{
    private ?string $picked = null;

    /**
     * @param list<string>|null $extensions
     * @param (\Closure(string $path, FilePicker $picker): void)|null $onPick
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        private readonly string $root = '.',
        private readonly ?array $extensions = null,
        private readonly bool $showHidden = false,
        private readonly bool $directories = false,
        private readonly ?\Closure $onPick = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
        bool $disabled = false,
    ) {
        $this->with($key, $id, $class, $style, disabled: $disabled);
    }

    /** The path picked last, null before one was. */
    public function value(): ?string
    {
        return $this->picked;
    }

    /** The path highlighted in the tree. */
    public function current(): ?string
    {
        return $this->child('tree')?->path();
    }

    private function pick(string $path): void
    {
        $this->picked = $path;
        $this->call($this->onPick, $path, $this);
        $this->emit('pick', $this);
        $this->requestRender();
    }

    protected function render(): Element
    {
        $tree = $this->child('tree', function () {
            $tree = new DirectoryTree(
                $this->root,
                $this->extensions,
                $this->showHidden,
                $this->directories,
                onFile: fn(string $path) => $this->pick($path),
                onSelect: fn() => $this->requestRender(),
            );
            if ($this->directories) {
                // (Enter on a directory picks it too)
                $tree->on('activate', function (string $key) use ($tree): void {
                    if ($tree->isBranch($key)) {
                        $this->pick($key);
                    }
                });
            }
            return $tree->with(height: '1fr', disabled: (bool) $this->attribute('disabled'));
        });
        return new Column([$tree, new TextElement($this->picked ?? $tree->path() ?? '', wrap: false, height: 1, class: 'muted')]);
    }
}
