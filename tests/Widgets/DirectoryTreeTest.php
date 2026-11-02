<?php

/*
 * DirectoryTree and FilePicker, against a temporary directory.
 */

use phasync\Tui\Component\DirectoryTree;
use phasync\Tui\Component\FilePicker;
use phasync\Tui\Element\Column;
use phasync\Tui\FakeTerminal;

/** A directory with a known content, removed when $test is done. */
function withProject(Closure $test): void
{
    $root = sys_get_temp_dir() . '/tui-dirtree-' . bin2hex(random_bytes(4));
    mkdir("$root/src/Deep", 0777, true);
    mkdir("$root/Docs", 0777, true);
    mkdir("$root/.git", 0777, true);
    foreach (['README.md', 'composer.json', 'src/App.php', 'src/Deep/Inner.PHP', 'src/notes.txt', 'Docs/guide.md', '.env', '.git/HEAD'] as $f) {
        file_put_contents("$root/$f", "x\n");
    }
    try {
        $test($root);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
}

it('lists a directory lazily: directories first, sorted ignoring case, hidden left out', function () {
    withProject(function (string $root) {
        $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('t', fn() => new DirectoryTree($root))]));
        mountOn($app, 60, 12, function (FakeTerminal $t, WidgetScreen $app) use ($root) {
            $t->settle();
            $rows = screenRows($t);
            expect($rows[0])->toContain($root);
            expect(array_slice($rows, 1, 5))->toBe([
                '├─ ▸ Docs', '├─ ▸ src', '├─   composer.json', '└─   README.md', '',
            ]);
            $tree = $app->children()['t'];
            expect($tree->has("$root/src/App.php"))->toBeFalse();       // not loaded before it is opened
            $tree->focus();
            $t->press('down', 'down', 'right');                         // src: opens
            $t->settle();
            expect(array_slice(screenRows($t), 3, 4))->toBe(['│  ├─ ▸ Deep', '│  ├─   App.php', '│  └─   notes.txt', '├─   composer.json']);
            expect($tree->path())->toBe("$root/src");
        });
    });
});

it('filters files by extension (ignoring case) but always shows directories, and can show hidden ones and only directories', function () {
    withProject(function (string $root) {
        $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
            $s->make('t', fn() => new DirectoryTree($root, extensions: ['php', 'md'])),
        ]));
        mountOn($app, 60, 14, function (FakeTerminal $t, WidgetScreen $app) use ($root) {
            $t->settle();
            $tree = $app->children()['t'];
            expect(array_slice(screenRows($t), 1, 4))->toBe(['├─ ▸ Docs', '├─ ▸ src', '└─   README.md', '']);
            $tree->expand("$root/src");
            $tree->expand("$root/src/Deep");
            $t->settle();
            $text = implode("\n", screenRows($t));
            expect($text)->toContain('App.php')->toContain('Inner.PHP')->not->toContain('notes.txt')->not->toContain('composer.json');
            $tree->setExtensions(null);
            $tree->setShowHidden(true);
            $t->settle();
            $text = implode("\n", screenRows($t));
            expect($text)->toContain('.git')->toContain('.env')->toContain('composer.json');
        });
        $dirs = new WidgetScreen(fn(WidgetScreen $s) => new Column([$s->make('t', fn() => new DirectoryTree($root, directoriesOnly: true))]));
        mountOn($dirs, 60, 8, function (FakeTerminal $t) {
            $t->settle();
            expect(array_slice(screenRows($t), 1, 3))->toBe(['├─ ▸ Docs', '└─ ▸ src', '']);
        });
    });
});

it('calls onFile for Enter on a file, opens directories, and follows the mouse', function () {
    withProject(function (string $root) {
        $files = new ArrayObject();
        $selected = new ArrayObject();
        $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
            $s->make('t', fn() => new DirectoryTree($root, onFile: function ($p) use ($files) { $files[] = $p; }, onSelect: function ($k) use ($selected) { $selected[] = $k; })),
        ]));
        mountOn($app, 60, 12, function (FakeTerminal $t, WidgetScreen $app) use ($root, $files, $selected) {
            $t->settle();
            $tree = $app->children()['t'];
            $tree->focus();
            $t->press('down', 'down', 'enter');                          // src: opens, no file callback
            $t->settle();
            expect($files->getArrayCopy())->toBe([]);
            $t->press('down', 'down', 'enter');                          // Deep -> App.php: Enter
            expect($files->getArrayCopy())->toBe(["$root/src/App.php"]);
            $t->click(8, 5);                                             // the row of notes.txt
            $t->press('enter');
            expect($files->getArrayCopy())->toBe(["$root/src/App.php", "$root/src/notes.txt"]);
            expect($selected[count($selected) - 1])->toBe("$root/src/notes.txt");
        });
    });
});

it('picks a file in a FilePicker, shows the highlighted path and keeps the value for a Form', function () {
    withProject(function (string $root) {
        $picked = new ArrayObject();
        $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
            $s->make('p', fn() => new FilePicker($root, extensions: ['md'], onPick: function ($p) use ($picked) { $picked[] = $p; })),
        ]));
        mountOn($app, 60, 10, function (FakeTerminal $t, WidgetScreen $app) use ($root, $picked) {
            $t->settle();
            $p = $app->children()['p'];
            expect($p->value())->toBeNull();
            expect(screenRows($t)[9])->toBe($root);                       // the line under the tree: the root is highlighted
            $p->focus();
            $t->press('down', 'down', 'down');                            // Docs, src, README.md
            $t->settle();
            expect(screenRows($t)[9])->toBe("$root/README.md");
            $t->press('enter');
            $t->settle();
            expect($p->value())->toBe("$root/README.md");
            expect($picked->getArrayCopy())->toBe(["$root/README.md"]);
            expect($p->current())->toBe("$root/README.md");
        });
    });
});

it('picks directories in directory mode', function () {
    withProject(function (string $root) {
        $picked = new ArrayObject();
        $app = new WidgetScreen(fn(WidgetScreen $s) => new Column([
            $s->make('p', fn() => new FilePicker($root, directories: true, onPick: function ($p) use ($picked) { $picked[] = $p; })),
        ]));
        mountOn($app, 60, 10, function (FakeTerminal $t, WidgetScreen $app) use ($root, $picked) {
            $t->settle();
            $app->children()['p']->focus();
            $t->press('down', 'down', 'enter');
            $t->settle();
            expect($picked->getArrayCopy())->toBe(["$root/src"]);
            expect(implode("\n", screenRows($t)))->toContain('Deep')->not->toContain('App.php');
        });
    });
});
