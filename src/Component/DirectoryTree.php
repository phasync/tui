<?php

namespace phasync\Tui\Component;

/**
 * A `Tree` of the file system: directories that open to show what is in them, loaded the first
 * time they are opened.
 *
 *     new DirectoryTree('/home/me/project', extensions: ['php', 'md'],
 *         onFile: fn(string $path, DirectoryTree $tree) => $this->open($path))
 *
 * The root directory is the first node, open. Directories come before files, each sorted by
 * name (ignoring case). `extensions` (a list without dots, ignoring case; null: all files) limits
 * the files shown, never the directories; `showHidden` includes names starting with a dot;
 * `directoriesOnly` leaves out files. Keys and values are the paths (the root's as given): `path()`
 * is the current node's. Enter on a file calls `onFile($path, $tree)`; on a directory it opens or
 * closes it (the `Tree`'s `onActivate`). A directory that cannot be read is shown empty. Everything
 * of `Tree` works (keys, mouse, `setCurrent($path)`, `reload($path)`, `bind()`, `onSelect`). The
 * `path` is a prop (a new path shows another tree); the filters change with `setExtensions()`
 * and `setShowHidden()`, which load the directories again.
 *
 * **Style**: as `Tree`.
 */
class DirectoryTree extends Tree
{
    private ?array $extensions;

    /**
     * @param list<string>|null $extensions
     * @param (\Closure(string $path, DirectoryTree $tree): void)|null $onFile Enter on a file
     * @param (\Closure(string $path, DirectoryTree $tree): void)|null $onSelect the current node changed
     * @param string|list<string>|null $class
     * @param array<string, mixed>|null $style
     */
    public function __construct(
        string $path = '.',
        ?array $extensions = null,
        private bool $showHidden = false,
        private readonly bool $directoriesOnly = false,
        private readonly ?\Closure $onFile = null,
        ?\Closure $onSelect = null,
        ?string $key = null,
        ?string $id = null,
        string|array|null $class = null,
        ?array $style = null,
    ) {
        $this->extensions = $extensions === null ? null : array_map('strtolower', $extensions);
        $root = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
        parent::__construct(
            [['label' => $root, 'key' => $root, 'value' => $root, 'expanded' => true]],
            fn(array $node) => $this->listing((string) $node['value']),
            onSelect: $onSelect === null ? null : fn(string $key, Tree $tree) => $onSelect($key, $this),
            onActivate: function (int|string $key, Tree $tree): void {
                if ($this->onFile !== null && !is_dir((string) $key)) {
                    $this->call($this->onFile, (string) $key, $this);
                }
            },
            key: $key,
            id: $id,
            class: $class,
            style: $style,
        );
    }

    /** The path of the current node, null for no node. */
    public function path(): ?string
    {
        $c = $this->current();
        return $c === null ? null : (string) $c;
    }

    /** Show only files with these extensions (without dots, ignoring case); null: all. Loads again. */
    public function setExtensions(?array $extensions): void
    {
        $this->extensions = $extensions === null ? null : array_map('strtolower', $extensions);
        $this->reloadRoot();
    }

    public function setShowHidden(bool $showHidden): void
    {
        $this->showHidden = $showHidden;
        $this->reloadRoot();
    }

    private function reloadRoot(): void
    {
        $root = $this->items[0]['key'];
        $this->reload($root);
    }

    /** @return list<array<string, mixed>> the items of directory $dir */
    private function listing(string $dir): array
    {
        $dirs = $files = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || (!$this->showHidden && $name[0] === '.')) {
                continue;
            }
            $path = rtrim($dir, '/') . '/' . $name;
            if (is_dir($path)) {
                $dirs[$name] = ['label' => $name, 'key' => $path, 'value' => $path];
            } elseif (!$this->directoriesOnly && ($this->extensions === null || \in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), $this->extensions, true))) {
                $files[$name] = ['label' => $name, 'key' => $path, 'value' => $path, 'leaf' => true];
            }
        }
        uksort($dirs, 'strcasecmp');
        uksort($files, 'strcasecmp');
        return [...array_values($dirs), ...array_values($files)];
    }
}
