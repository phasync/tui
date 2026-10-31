<?php

/**
 * A directory browser: a Tree whose folders load their children when opened (here from the
 * disk, a little slowly, so the spinner shows), with the file's size shown under it.
 *
 *     php examples/tree.php [directory]
 *
 * Up/Down, Page Up/Page Down, Home/End move; Right opens, Left closes (or goes to the parent);
 * Enter and Space open and close; typing letters jumps to a name; a click on ▸/▾ opens or
 * closes, the wheel scrolls. Ctrl-Q quits.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\Tui\Component;
use phasync\Tui\Component\Tree;
use phasync\Tui\Element;
use phasync\Tui\Element\Column;
use phasync\Tui\Element\Help;
use phasync\Tui\Element\Text;
use phasync\Tui\Tty;

final class Browser extends Component
{
    /** The path of the current node (set by the tree's onSelect, which renders this component again). */
    private string $path = '';

    public function __construct(private readonly string $root)
    {
    }

    protected function bindings(): array
    {
        return ['ctrl+q' => ['quit', 'quit', 'always' => true]];
    }

    /** @return list<array<string, mixed>> */
    private static function entries(string $dir): array
    {
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $items[] = is_dir($path)
                ? ['label' => $name, 'icon' => '📁', 'value' => $path]
                : ['label' => $name, 'icon' => '📄', 'value' => $path, 'leaf' => true];
        }
        usort($items, fn($a, $b) => [isset($a['leaf']), $a['label']] <=> [isset($b['leaf']), $b['label']]);
        return $items;
    }

    protected function render(): Element
    {
        $tree = $this->child('tree', fn() => new Tree(
            items: [['label' => basename($this->root) ?: '/', 'icon' => '📁', 'value' => $this->root, 'expanded' => true]],
            onSelect: fn(string $key, Tree $tree) => $this->path = (string) $tree->value($key),
            load: function (array $node): object {
                // a pretend-slow disk: an awaitable of the entries
                $d = new \fiber\Deferred();
                \fiber\go(function () use ($d, $node) {
                    \fiber\sleep(0.4);
                    $d->resolve(self::entries($node['value']));
                });
                return $d;
            },
        ));
        $path = $this->path;
        $size = $path !== '' && is_file($path) ? number_format((int) filesize($path)) . ' bytes' : '';
        return new Column([$tree, new Text($path . ($size === '' ? '' : "  ($size)"), height: 1), new Help(show: 'application')]);
    }

    public function quit(): void
    {
        $this->dispose();
    }
}

$tty = new Tty();
if (!$tty->isTerminal()) {
    fwrite(STDERR, "tree needs a terminal\n");
    exit(1);
}
fiber\run(fn() => (new Browser(realpath($argv[1] ?? __DIR__ . '/..') ?: '/'))->start($tty));
