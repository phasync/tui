# Cookbook

Ten screens, each a whole program of under forty lines, none of them with a class. Copy
one into a file after `require 'vendor/autoload.php';` and run it; Ctrl-Q quits. Each is run
by `tests/Docs/CookbookTest.php` under a `FakeTerminal` at 60x20 and at 160x50, so what you
read here works. Without a terminal, `TUI_HEADLESS=80x24 php screen.php` prints the screen as
text, and `TUI_THEME=light` (or `--theme=mono`) picks the theme it is drawn in.

The pattern is the same in all of them: a `State` holds what changes, coroutines
(`Tui::go()`, which ends the program when one fails), key handlers (`keys:`, `onKey:`) or
callbacks (`onSubmit:`, `onSelect:`, `onFocus:`) change it, and a closure
builds the screen from it; the screen follows the state by itself (see
[components.md](components.md), "Reactive"). Utility classes (`grow`, `text-muted`,
`md:flex-row`, see [styling.md](styling.md)) do the styling, and the default theme the rest.
A coroutine that should end with the screen waits with `Tui::sleep()`, which throws `Quit` once
the screen has quit (and `Tui::go()` takes that as the coroutine's end), so `fiber\run()`
returns with the screen.

## 1. A progress runner

Jobs side by side with a bar each and a total, in the terminal's own scrollback (inline mode):
each finished job leaves its line above and the live region shrinks.

```php
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\{Column, Text};
use phasync\Tui\{State, Tui};

$jobs = ['compile' => 0.04, 'test' => 0.07, 'package' => 0.02, 'upload' => 0.05];
$s = new State(['done' => array_map(fn() => 0.0, $jobs)]);
\fiber\run(function () use ($jobs, $s) {
    foreach ($jobs as $job => $every) {
        Tui::go(function () use ($job, $every, $s) {
            while ($s->done[$job] < 1.0) {
                Tui::sleep($every);
                $s->done = [...$s->done, $job => min(1.0, $s->done[$job] + 0.05)];
            }
        });
    }
    Tui::inline(fn() => new Column([
        ...array_map(fn(string $job) => new ProgressBar(fn() => $s->done[$job], label: str_pad($job, 8) . ' {percent}'), array_keys($jobs)),
        new Text(fn() => sprintf('<b>%d of %d done</b>', count(array_filter($s->done, fn($d) => $d >= 1.0)), count($jobs)), class: 'text-accent'),
    ]));
});
```

`Tui::inline()` keeps the last frame on the terminal when it ends; with a `$tty->freeze()` of a
finished row (`Component::device()`) the row goes into the scrollback while the others go on.

## 2. A log tail with a filter

A log that keeps growing, and a one-line field that filters what it shows as you type.

```php
use phasync\Tui\Component\{Editor, Log};
use phasync\Tui\Element\{Box, Column, Help, Text};
use phasync\Tui\{State, Tui};

$s = new State(['lines' => [], 'filter' => '']);
$log = new Log();
$show = function () use ($s, $log) {
    $log->setLines(array_values(array_filter($s->lines, fn($l) => $s->filter === '' || stripos($l, $s->filter) !== false)));
};
\fiber\run(function () use ($s, $log, $show) {
    Tui::go(function () use ($s, $show) {
        for ($i = 1; ; $i++) {      // (until the screen quits: Tui::sleep() then throws Quit)
            $level = ['info', 'info', 'warn', 'error'][$i % 4];
            $s->lines = [...array_slice($s->lines, -999), sprintf('%04d %-5s request %d served', $i, $level, $i * 7)];
            $show();
            Tui::sleep(0.05);
        }
    });
    Tui::run(fn() => new Column([
        new Box(new Editor(placeholder: 'filter (error, warn, a number)', maxLines: 1, onChange: function (string $v) use ($s, $show) {
            $s->filter = $v;
            $show();
        }), 'filter'),
        new Box($log, fn() => "log <dim>(" . count($s->lines) . " lines)</dim>", class: 'grow'),
        new Help(),
    ]));
});
```

## 3. A form with validation in a dialog

A key of the program (`keys:`) opens the dialog; its handler awaits the answer
(`Dialog::open()`), and the other keys keep working meanwhile.

```php
use phasync\Tui\Component\{Dialog, Editor, Form};
use phasync\Tui\Element\{Column, Help, Text};
use phasync\Tui\{State, Tui};

$s = new State(['contacts' => []]);
$add = function () use ($s) {
    $form = new Form(new Column([
        new Text('Name'), (new Editor(maxLines: 1))->with(id: 'name'),
        new Text('Email'), (new Editor(maxLines: 1))->with(id: 'email'),
    ]), validate: fn(array $v) => array_filter([
        'name' => trim($v['name']) === '' ? 'A name, please.' : null,
        'email' => str_contains($v['email'], '@') ? null : 'An email address has an @.',
    ]));
    if (\fiber\await(Dialog::open(new Dialog('New contact', $form, ['Add' => true, 'Cancel' => false])))) {
        $s->contacts = [...$s->contacts, $form->values()];
    }
};
Tui::run(fn() => new Column([
    new Text('Contacts', class: 'bold text-accent'),
    new Column(array_map(fn(array $c) => new Text("<b>{$c['name']}</b> <dim>{$c['email']}</dim>"), $s->contacts)
        ?: [new Text('none yet', class: 'text-muted')], class: 'grow'),
    new Help(),
]), keys: ['ctrl+n' => [$add, 'new contact']]);
```

The dialog checks the form before it closes: Enter or Add with an empty name shows the
message under the field and keeps the dialog open; Escape and Cancel close it at once.

## 4. Two panes: a file list and a preview

The files of the current directory on the left, the one under the cursor on the right.

```php
use phasync\Tui\Component\{Markdown, Select};
use phasync\Tui\Element\{Box, Column, Help, Row};
use phasync\Tui\{State, Tui};

$files = array_values(array_filter(scandir('.'), fn($f) => is_file($f)));
$s = new State(['file' => $files[0] ?? '']);
$preview = new Markdown();
$show = function (string $file) use ($s, $preview) {
    $s->file = $file;
    $text = substr((string) @file_get_contents($file), 0, 8000);
    $fence = str_repeat('`', 3);      // (a code block)
    $preview->set(str_ends_with($file, '.md') ? $text : "$fence\n" . str_replace($fence, "'''", $text) . "\n$fence");
};
$show($s->file);
Tui::run(new Column([
    new Row([
        new Box(new Select(items: array_combine($files, $files), onSelect: fn(string $file) => $show($file)), 'files', class: 'w-1/3'),
        new Box($preview, fn() => $s->file, class: 'grow'),
    ], class: 'grow'),
    new Help(),
]));
```

A Markdown file shows formatted, any other as a code block (highlighted when it is PHP, JS,
shell or JSON). The preview is one `Markdown` whose text is set when the cursor moves.

## 5. A tabbed shell host

Two shells and a log in tabs; Ctrl-Tab or Alt-1..3 switch, a click on a tab too.

```php
use phasync\Tui\Component\{Log, Tabs, Terminal};
use phasync\Tui\Element\{Column, Help};
use phasync\Tui\Tui;

$log = new Log();
$log->append("started at " . date('H:i:s') . "\n");
$tabs = new Tabs([
    'one' => ['label' => 'shell', 'content' => new Terminal(['sh'])],
    'two' => ['label' => 'another shell', 'content' => new Terminal(['sh'])],
    'log' => ['label' => 'log', 'content' => $log],
], hints: true, onChange: fn(string $key) => $log->append("tab $key\n"));
Tui::run(new Column([$tabs->with(class: 'grow'), new Help()]));
```

Each shell runs in a pty of its own and keeps running while its tab is hidden.

## 6. A data table with a search box

```php
use phasync\Tui\Component\{Editor, Table};
use phasync\Tui\Element\{Box, Column, Help, Text};
use phasync\Tui\{State, Tui};

$rows = [];
foreach (['nginx', 'php-fpm', 'postgres', 'redis', 'cron', 'sshd', 'node', 'mysqld'] as $i => $name) {
    $rows[$name] = [(string) (100 + $i * 37), $name, sprintf('%.1f', ($i * 13 % 70) / 10), ['running', 'sleeping'][$i % 2]];
}
$s = new State(['q' => '']);
$search = new Editor(placeholder: 'search', maxLines: 1, onChange: fn(string $v) => $s->q = $v);
Tui::run(function () use ($rows, $s, $search) {
    $shown = array_filter($rows, fn(array $r) => $s->q === '' || str_contains($r[1], $s->q));
    return new Column([
        new Box($search, 'search'),
        new Box(new Table(columns: ['PID' => 'auto', 'Command' => '1fr', 'CPU %' => ['align' => 'right'], 'State' => 'auto'], rows: $shown),
            count($shown) . ' processes', class: 'grow'),
        new Text('click a title to sort', class: 'text-muted'),
        new Help(),
    ]);
});
```

The closure reads `$s->q`, so it runs again as you type: the `Table` made anew hands its
`rows` to the one shown (they are props), which keeps its cursor and sorting. It measures its
`auto` columns again only when the rows have other cells, so a render that builds the same
rows costs a comparison. The search field is made once, outside: it keeps what you typed.

## 7. A chat pane with a growing editor

The input grows with what you type up to five rows; Alt-Enter sends.

```php
use phasync\Tui\Component\{Editor, Log};
use phasync\Tui\Element\{Box, Column, Help};
use phasync\Tui\Tui;

$chat = new Log(wordWrap: true);
$chat->append("<span class=\"text-accent\">bot</span> Hello! Type a message, Alt-Enter sends it.\n");
$input = new Editor(placeholder: 'message', maxLines: 5, onSubmit: function (string $text, Editor $editor) use ($chat) {
    if (trim($text) !== '') {
        $chat->append('<b>you</b> ' . str_replace("\n", "\n    ", $text) . "\n");
        $chat->append('<span class="text-accent">bot</span> ' . strlen($text) . " characters, thank you.\n");
    }
    $editor->setValue('');
});
$input->focus();
Tui::run(new Column([
    new Box($chat, 'chat', class: 'grow'),
    new Box($input, 'message'),
    new Help(),
]));
```

## 8. Settings: checkboxes and a select

```php
use phasync\Tui\Component\{Checkbox, Select};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$s = new State(['on' => ['notifications' => true, 'sounds' => false, 'updates' => true], 'theme' => 'dark', 'focus' => '']);
$box = fn(string $name) => new Checkbox(ucfirst($name), checked: $s->on[$name], onChange: fn(bool $v) => $s->on = [...$s->on, $name => $v]);
$themes = ['dark' => 'Dark', 'light' => 'Light', 'mono' => 'Monochrome'];
Tui::run(fn() => new Column([
    new Text('Settings', class: 'bold text-accent'),
    new Box(new Column(array_map(fn(string $name) => new Row([$box($name)], onFocus: fn() => $s->focus = $name), array_keys($s->on))), 'general'),
    new Box(new Select(items: $themes, dropdown: true, onSelect: fn(string $key) => $s->theme = $key), 'theme', onFocus: fn() => $s->focus = 'theme'),
    new Text(fn() => 'on: ' . implode(', ', array_keys(array_filter($s->on))) . " · theme {$s->theme}", class: 'text-muted'),
    new Text(fn() => $s->focus === '' ? '' : "<dim>editing</dim> {$s->focus}", class: 'grow'),
    new Help(),
], class: 'px-1'));
```

`onFocus:` on an element tells which setting has the focus; no polling of `hasFocus()`.

## 9. A live chart from a coroutine

Block characters in a `Text` closure, one column per sample, redrawn as the samples come.

```php
use phasync\Tui\Element\{Box, Column, Help, Text};
use phasync\Tui\{State, Tui};

$s = new State(['samples' => []]);
$chart = function (int $height) use ($s): string {
    $rows = [];
    for ($y = $height; $y >= 1; $y--) {
        $rows[] = implode('', array_map(fn(float $v) => mb_substr(' ▁▂▃▄▅▆▇█', (int) max(0, min(8, ($v * $height - $y + 1) * 8)), 1), $s->samples));
    }
    return implode("\n", $rows);
};
\fiber\run(function () use ($s, $chart) {
    Tui::go(function () use ($s) {
        for ($t = 0; ; $t++) {
            $s->samples = [...array_slice($s->samples, -119), 0.5 + 0.4 * sin($t / 6) * cos($t / 17)];
            Tui::sleep(0.1);
        }
    });
    Tui::run(fn() => new Column([
        new Box(new Text(fn() => $chart(8), wrap: false, class: 'text-accent'), fn() => sprintf('signal <b>%.2f</b>', $s->samples[count($s->samples) - 1] ?? 0), class: 'title-center'),
        new Text(fn() => ($n = count($s->samples)) . ($n === 1 ? ' sample' : ' samples') . ', 10 a second', class: 'text-muted grow'),
        new Help(),
    ]));
});
```

Only the chart's `Text` is drawn again for each sample: its closure is a component of its own.

## 10. A Markdown viewer with a table of contents

The headings on the left; the section under the cursor on the right.

```php
use phasync\Tui\Component\{Markdown, Select};
use phasync\Tui\Element\{Box, Column, Help, Row};
use phasync\Tui\{State, Tui};

$doc = (string) file_get_contents('README.md');
$sections = [];
foreach (preg_split('/^(?=#{1,3} )/m', $doc) as $part) {
    if (preg_match('/^(#{1,3}) (.+)$/m', $part, $m)) {
        $sections[str_repeat('  ', strlen($m[1]) - 1) . $m[2]] = $part;
    }
}
$s = new State(['at' => array_key_first($sections)]);
$view = new Markdown($sections[$s->at] ?? $doc, scrollbar: true);
$toc = new Select(items: array_combine(array_keys($sections), array_keys($sections)), onSelect: function (string $key) use ($s, $view, $sections) {
    $s->at = $key;
    $view->set($sections[$key]);
});
Tui::run(fn() => new Column([
    new Row([new Box($toc, 'contents', class: 'w-1/3 max-w-40'), new Box($view, fn() => trim($s->at), class: 'grow')], class: 'grow flex-col md:flex-row'),
    new Help(),
]));
```

Under 80 columns the contents go above the text (`flex-col md:flex-row`).

## 11. Rows that keep their widgets

A list whose rows move (sorted by progress) and come and go, each row with a bar and a button
that keep their state: the focus stays on the button it is on while the rows reorder.

```php
use phasync\Tui\Component\{Button, ProgressBar};
use phasync\Tui\Element\{Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$s = new State(['done' => ['build' => 0.0, 'test' => 0.0, 'lint' => 0.0, 'docs' => 0.0], 'paused' => []]);
$bars = $buttons = [];
\fiber\run(function () use ($s, &$bars, &$buttons) {
    foreach (array_keys($s->done) as $i => $job) {
        Tui::go(function () use ($s, $job, $i) {
            while ($s->done[$job] < 1.0) {
                Tui::sleep(0.01 * ($i + 1));
                $s->done[$job] = empty($s->paused[$job]) ? min(1.0, $s->done[$job] + 0.01) : $s->done[$job];
            }
        });
    }
    Tui::run(function () use ($s, &$bars, &$buttons) {
        $done = $s->done;
        arsort($done);                      // the furthest first: the rows move as they go
        $rows = [];
        foreach ($done as $job => $_) {
            // made once and kept: the same instances in every render, wherever the row is
            $bars[$job] ??= new ProgressBar(fn() => $s->done[$job]);
            $buttons[$job] ??= new Button(fn() => empty($s->paused[$job]) ? 'Pause' : 'Resume',
                fn() => $s->paused[$job] = empty($s->paused[$job]));
            $rows[] = new Row([new Text($job, width: 6), $bars[$job], $buttons[$job]], gap: 1, key: $job);
        }
        return new Column([...$rows, new Text(fn() => count(array_filter($s->done, fn($d) => $d >= 1.0)) . ' done', class: 'grow text-muted'), new Help()]);
    });
});
```

The `??=` keeps each widget for the life of the program: a `ProgressBar` keeps its animation
and a `Button` its focus whichever row it is shown in, and the bar, which reads the state in a
closure, is drawn again by itself without the list being rendered. `key:` names the row, so
the windows behind it go with it when the order changes. Made anew in every render instead
(`new Button(...)` in the loop), the instances would take over the ones before them by
position, and a focus or an animation would stay at the place in the list rather than with
the job.
