# phasync/tui

A terminal UI library for PHP built on a real terminal emulator. Think tmux as a library: every
window is a terminal, so a shell, `vim`, `htop` or Claude Code runs in a pane of your PHP program
exactly as in a terminal, next to widgets that are themselves programs writing to a terminal.

This is a whole program, `examples/dashboard.php` (`php examples/dashboard.php`, with
`--inline` for the three boxes in the shell's own scrollback, `--theme=light` or `mono`):

```text
 ⠇ deploying v2.4                                                                 staging · eu-west
╭ build ────────────────────────╮ ╭ test ─────────────────────────╮ ╭ deploy ──────────────────────╮
│ ██░░░░░░░░░░░░░░░░░░░░░░░░ 9% │ │ ████████████████░░░░░░░░░ 63% │ │ ███████░░░░░░░░░░░░░░░░░ 30% │
│ run 3  ▁▇█▂▂█▅▁▃▅▄▃▅▃▅▆       │ │ run 1  ▇▂▅▅▅▅▇▂▇█▁▂▇▅▅▄       │ │ run 0  ▅▁▆▅▂▁                │
╰───────────────────────────────╯ ╰───────────────────────────────╯ ╰──────────────────────────────╯
╭ events ────────────────────────────────────────╮ ╭ release notes ────────────────────────────────╮
│08:46:56 ✓ build #1 done                        │ │ Release 2.4                                   │
│08:46:57 ✓ test #1 done                         │ │                                               │
│08:46:57 ✓ build #2 done                        │ │ • Faster start-up, half the memory            │
│08:46:58 ✓ build #3 done                        │ │ • --json output for every command             │
│                                                │ │ • Wide characters line up: 日本語 ✓           │
│                                                │ │                                               │
│                                                │ │ │ Thanks to everyone who reported bugs!       │
│                                                │ │                                               │
│                                                │ │                                               │
│                                                │ │                                               │
│                                                │ │                                               │
│                                                │ │                                               │
│                                                │ │                                               │
│                                                │ │                                               │
╰────────────────────────────────────────────────╯ ╰───────────────────────────────────────────────╯
 ctrl+q quit
```

```php
use phasync\Tui\Component\{Log, Markdown, ProgressBar, Spinner};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};
use phasync\Tui\{State, Tui};

$jobs = ['build' => 0.07, 'test' => 0.15, 'deploy' => 0.4];          // seconds per step
$s = new State(['done' => array_map(fn() => 0.0, $jobs), 'runs' => array_map(fn() => 0, $jobs), 'steps' => array_map(fn() => [], $jobs)]);
$log = new Log();
$notes = new Markdown(<<<'MD'
    # Release 2.4
    - **Faster** start-up, half the memory
    - `--json` output for every command
    - Wide characters line up: 日本語 ✓
    > Thanks to everyone who reported bugs!
    MD);
$spark = fn(array $steps) => implode('', array_map(fn(float $v) => mb_substr(' ▁▂▃▄▅▆▇█', (int) min(8, $v * 9), 1), $steps));
$box = fn(string $job) => new Box(new Column([
    new ProgressBar(fn() => $s->done[$job], label: '{percent}'),
    new Text(fn() => "<dim>run</dim> {$s->runs[$job]}  <span class=\"text-accent\">{$spark($s->steps[$job])}</span>"),
]), "<b>$job</b>", class: 'grow px-1 hover:border-accent');
$boxes = fn() => new Row(array_map($box, array_keys($jobs)), class: 'flex-col md:flex-row md:gap-1');
$screen = fn() => new Column([
    new Row([new Spinner('<b>deploying</b> v2.4'), new Text('staging · eu-west', class: 'grow text-right text-muted')], class: 'px-1'),
    $boxes(),
    new Row([new Box($log, 'events', class: 'grow'), new Box($notes, 'release notes', class: 'grow px-1')], class: 'grow flex-col md:flex-row md:gap-1'),
    new Help(class: 'px-1'),
]);

$theme = ($t = preg_grep('/^--theme=/', $argv)) ? substr(reset($t), 8) : null;
\fiber\run(function () use ($jobs, $s, $log, $screen, $boxes, $theme, $argv) {
    foreach ($jobs as $job => $every) {
        Tui::go(function () use ($job, $every, $s, $log) {
            while (true) {      // (Tui::sleep() ends the job when the screen quits)
                Tui::sleep($every * (0.5 + mt_rand() / mt_getrandmax()));
                $step = mt_rand(2, 12) / 100;
                $s->steps = [...$s->steps, $job => [...array_slice($s->steps[$job], -15), $step * 8]];
                $s->done = [...$s->done, $job => min(1.0, $s->done[$job] + $step)];
                if ($s->done[$job] >= 1.0) {
                    $s->runs = [...$s->runs, $job => $s->runs[$job] + 1];
                    $log->append(date('H:i:s') . " <span class=\"text-success\">✓</span> $job #{$s->runs[$job]} done\n");
                    $s->done = [...$s->done, $job => 0.0];
                }
            }
        });
    }
    in_array('--inline', $argv, true) ? Tui::inline($boxes, theme: $theme) : Tui::run($screen, theme: $theme);
});
```

Three jobs run in coroutines of the program (`Tui::go()`, which stop with it: once the screen
has quit, they are cancelled with `Quit`, which their `Tui::sleep()` throws) and write their progress into a
`State`; the screen is closures
over that state, so each `ProgressBar` and `Text` is drawn again when what it read changes,
and nothing else is. The look is utility classes (`grow`, `px-1`, `text-muted`,
`flex-col md:flex-row`: stacked under 80 columns) on the default theme (rounded borders, one
accent colour, the focused window in it). [docs/cookbook.md](docs/cookbook.md) has ten more
screens like it: a log tail with a filter, a form in a dialog, a file list with a preview, a
tabbed shell host, a table with a search box, a chat pane, settings, a live chart and a
Markdown viewer with a table of contents.

The smallest program:

```php
use phasync\Tui\{State, Tui};
use phasync\Tui\Component\ProgressBar;
use phasync\Tui\Element\{Column, Text};

$s = new State(['done' => 0]);
fiber\run(function () use ($s) {
    Tui::go(function () use ($s) { for ($i = 1; $i <= 100; $i++) { Tui::sleep(0.05); $s->done = $i; } });
    Tui::inline(fn() => new Column([
        new Text("Working… {$s->done}%"),
        new ProgressBar($s->done / 100),
    ]));
});
```

That is a whole program: a line and a progress bar as a live region under the shell's output,
counting to 100%. The closure is the screen; it is rendered again whenever a `State` it read
changes, here from a coroutine. Ctrl-Q quits; `Tui::run()` shows the same screen full screen,
`Tui::headless()` on an in-memory terminal for tests (`examples/working.php` runs both ways).
`Tui::go()` is `fiber\go()` for a coroutine of the program: its exception ends the program, and
it stops with the program when it waits with `Tui::sleep()`; both must be called inside
`fiber\run()`, which is why the program is wrapped in one.

Such a program takes input without a class too: `keys:` on the runner binds keys to closures
(`Tui::run($screen, keys: ['ctrl+n' => [fn() => add($s), 'new card']])`), every element takes
`onKey:` (keys that apply while the focus is in it; an element with nothing focusable inside is a stop of the Tab walk itself), `onMouse:` (presses, drags and the
release of a drag, with what it was dropped on) and `focused:` (the focus follows the state:
`focused: $s->sel === $i`; a `ScrollView` keeps the focused, and the `selected:`, card in view),
and a handler may await a dialog:
`fiber\await(Confirm::ask('Delete', 'Delete the card?'))`, `Dialog::open(new Dialog(...))`. See
[docs/components.md](docs/components.md#without-a-class).

Classes are for what has behaviour of its own. A chat-like log with an input line next to your
shell:

```php
use phasync\Tui\{Component, Key, Layout, Split, Tty};
use phasync\Tui\Component\{Log, Terminal, TextInput};

final class App extends Component
{
    protected function render(): Layout
    {
        $log = $this->child('log', fn() => new Log(wordWrap: true));
        $input = $this->child('input', fn() => new TextInput(prompt: '> ', history: true,
            onSubmit: function (string $line, TextInput $input) use ($log) {
                $log->append("you said: $line\n");
                $input->setValue('');
            }));
        $shell = $this->child('shell', fn() => new Terminal(getenv('SHELL') ?: '/bin/sh'));
        $input->focus();
        return Split::horizontal(separators: true)
            ->add(Split::vertical()->add($log->surface)->add($input->surface, 1))
            ->add($shell->surface, '50%');
    }

    protected function onKey(Key $key): bool
    {
        match (true) {
            $key->is('ctrl+q') => $this->dispose(),                        // quit
            $key->is('alt+left') => $this->surface->focusToward('left'),
            $key->is('alt+right') => $this->surface->focusToward('right'),
            default => null,
        };
        return $key->is('ctrl+q') || $key->is('alt+left') || $key->is('alt+right');
    }
}

fiber\run(fn() => (new App())->start(new Tty()));
```

In any terminal size: the log keeps its text and wraps it again whenever the terminal is
resized, the shell is told its new size. Alt-Left/Alt-Right move between them; Ctrl-Q quits,
which hangs the shell up and gives the terminal back, and `fiber\run()` returns.

PHP 8.3+, MIT. Runs on any [fiber-interop](https://packagist.org/packages/fiber-interop/api)
runtime: phasync, Revolt/AMPHP, ReactPHP.

## Install

```bash
composer require phasync/tui fiber-interop/phasync     # or fiber-interop/revolt, /amp, /react
```

`fiber-interop/api` (0.3.2 or later) is the only runtime dependency; install one adapter to
choose the event loop (on phasync, fiber-interop/phasync 0.4.2 or later: the library conflicts
with older ones, which cannot end a program whose starting code threw). Optional: ext-phasync 2.0.7+ (bundled with `phasync/phasync`) captures `echo` of a PHP coroutine
into a window; ext-pcntl gives immediate resizes and terminal restore on SIGTERM.

## The model

One primitive, one structure:

1. **Surface** ([docs](docs/surface.md)): a terminal emulator in memory, xterm-compatible.
   Bytes in with `write()`, cells out with `cell()`, `line()`, `text()`. ANSI escape sequences
   are the only way anything gets onto a Surface; there is no cell-drawing API, and the library
   draws its own widgets with escape sequences too (cells written once can be copied between
   surfaces with `copyArea()` and `drawArea()`).
2. **Producers** ([docs](docs/producers.md)): what writes into a Surface. A program in a `Pty`,
   a PHP closure with `Surface::run()` (whose `echo` is captured with ext-phasync), or your own
   code with the `Ansi` helper. Input goes back through `$surface->input`.
3. **Hosting** ([docs](docs/hosting.md)): a Surface hosts other Surfaces through `Viewport`s, to
   any depth. What a host shows is its own cells with its windows copied over them, to any
   depth, made again only on the rows something changed on; input events go down the focus path and are encoded for each program's modes. The screen of
   your program is a root Surface; a pane running another multiplexer is the same structure one
   level down.
4. **Terminal device** ([docs](docs/terminal.md)): `Tty` attaches the real terminal to a root
   Surface (drawing only what changed, decoding keys, mouse and paste, following resizes);
   `FakeTerminal` does the same in memory for tests and headless programs.
5. **Layout** ([docs](docs/layout.md)): `Split` and `Stack` arrange a host's windows; resizing
   goes down to every pty.
6. **Components** ([docs](docs/components.md)): `Component` adds a lifecycle, a coroutine, keyed
   children and error boundaries; `render()` returns an element tree (`Column`, `Row`, `Layers`,
   `Box`, `Text`, `Help`) that the library lays out into surfaces and paints through `write()`;
   state that renders by itself, a stylesheet, key bindings as data, and overlays: anything can
   float over everything (`overlay()`), placed next to its anchor inside the screen. Built-ins
   `Terminal`, `Log`, `TextInput`, `ListView`, `StatusBar`, `Box`. A closure is a component
   too: `Tui::run(fn() => ...)` renders it again when the state it read changes.
7. **Styling** ([docs](docs/styling.md)): Tailwind's utility classes in `class:`, a stylesheet
   with variables and states, symfony/console markup in text (`<b>`, `<fg=red>`); the themes
   `dark`, `light` and `mono`, chosen by the terminal's background; colours drawn in what the
   terminal has.
9. **Cookbook** ([docs](docs/cookbook.md)): ten screens of under forty lines each, to copy from.
8. **Widgets** ([docs](docs/widgets.md)): `Editor` (with history and completion), `Select`
   (a list, or a dropdown), `Table`, `Dialog` and `Confirm`, `Form` with `Button` and
   `Checkbox`, `ScrollView`, `ProgressBar`, `Spinner`, `Markdown`.

The surfaces form one tree, whatever is in it: every surface knows its `parent()` and its
`area()` on the root, and focus (`focus()`, `focusNext()`, `focusToward('left')`) follows that
tree through plain surfaces, nested layouts and components alike. The focus belongs to the
surface focused last, wherever it is shown: a re-render that wraps the layout in a `Stack` with a
dialog and takes it away again leaves the keys where they were, and the window focused before a
dialog gets them back when it closes.

## A three-minute tour

**A Surface is a terminal.** Write what a program would write; read what a terminal would show.

```php
$s = new Surface(24, 3);
$s->write("\e[1;32mok\e[0m tests passed\r\n中文 and 👍 are two cells wide");
echo $s->dump();      // [bold fg=2]ok[/] tests passed
                      // 中文 and 👍 are two cell
                      // s wide
```

**A program in a window.** Inside `fiber\run()`, start it in a pty attached to a surface:

```php
fiber\run(function () {
    $s = new Surface(80, 24);
    $pty = Pty::start(['ls', '--color=always', '/'], $s);
    $pty->wait();
    echo $s->text();
});
```

**Surfaces host surfaces.** Put windows on a root, with borders and titles; the root's cells
show them.

```php
$root = new Surface(60, 12);
$log = new Surface();
$root->setLayout(Split::horizontal()->add($log, title: 'log'));     // a border with a title
$log->write("first line\r\n");
echo $root->text();
```

**Draw with escape sequences.** Widgets are producers too: build sequences with `Ansi`.

```php
$log->write(Ansi::moveTo(0, 3) . Ansi::styled('warning', Style::of(Color::YELLOW, attrs: Style::BOLD)));
```

**Show it on the terminal.** Attach a device to the root; input arrives as events.

```php
$tty = new Tty();
$tty->attach($root);
$root->on('key', function (Key $key) use ($tty) {
    if ($key->is('ctrl+q')) {
        $tty->detach();       // gives the terminal back (and closes the root)
        return true;          // handled: it goes no further down
    }
});
$tty->wait();
```

**Components** give pieces of UI a lifecycle and a coroutine (`mount()` for state, `sized()` for
work that needs the component's real size, `render()`, `run()`):

```php
final class App extends Component
{
    protected function render(): ?Layout
    {
        $log = $this->child('log', fn() => new Log());
        $input = $this->child('input', fn() => new TextInput(prompt: '> ', onSubmit: function (string $line, TextInput $input) use ($log) {
            $log->append("you said: $line\n");
            $input->setValue('');
        }));
        $input->focus();      // keys go to the input (by default the first window has the focus)
        return Split::vertical()->add($log->surface)->add($input->surface, 1);
    }
}

fiber\run(fn() => (new App())->start(new Tty()));     // until it quits
```

**Screens from elements.** `render()` may return an element tree instead: the library lays it
out into surfaces, keeps what is behind each element (focus, scroll, the child components)
from render to render, and paints it with escape sequences like everything else. State
renders by itself; keys are data; a form collects its fields; a dialog's answer is an awaitable.

```php
use phasync\Tui\{Component, Element, Stylesheet, Tty};
use phasync\Tui\Component\{Button, Checkbox, Confirm, Editor, Form, Select};
use phasync\Tui\Element\{Box, Column, Help, Row, Text};

final class Profile extends Component
{
    protected function bindings(): array
    {
        return ['ctrl+q' => ['quit', 'quit', 'always' => true]];     // also while a dialog is open
    }

    protected function render(): Element
    {
        return new Column([
            new Text('Profile', class: 'title'),
            new Form(
                new Column([
                    new Box((new Editor(placeholder: 'your name', maxLines: 1))->with(id: 'name'), 'Name'),
                    (new Select(['dev' => 'Developer', 'ops' => 'Operations'], dropdown: true))->with(id: 'role'),
                    (new Checkbox('Send me the newsletter'))->with(id: 'news'),
                    new Row([new Button('Save'), new Button('Quit', onPress: fn() => $this->quit())], gap: 2, height: 1),
                ]),
                validate: fn(array $v) => $v['name'] === '' ? ['name' => 'Your name, please.'] : [],
                onSubmit: fn(array $v) => $this->save($v),
            ),
            new Text($this->state->status ?? 'not saved yet', class: 'muted'),
            new Help(show: 'application'),                  // ctrl+q quit
        ]);
    }

    public function save(array $profile): void
    {
        $this->go(function () use ($profile) {
            if (fiber\await($this->dialog(new Confirm('Save the profile?')))) {
                $this->state->status = "saved: {$profile['name']}, {$profile['role']}"
                    . ($profile['news'] ? ', newsletter' : '');           // shows by itself
            }
        });
    }

    public function quit(): void
    {
        $this->dispose();
    }
}

$app = new Profile();
$app->setStylesheet(Stylesheet::default()->with(['Text.title' => ['bold' => true, 'color' => '$accent']]));
fiber\run(fn() => $app->start(new Tty()));
```

The name field has the focus and types every letter; Tab goes from field to field (the role is
a dropdown: Enter or Down opens its list), Space ticks the box, Enter submits the form (in a
field, or on the Save button, which has no `onPress` of its own). Without a name the form
stays, with its message and the field marked `:invalid`; with one, `Confirm` asks, and the
answer arrives in the coroutine that awaits it. `examples/form.php` is a larger form.

**Styling where it is used.** `class:` takes Tailwind's utility classes (padding, gap, borders,
colours, grow, widths, `focus:`, `hover:`, `selected:` and the width variants `sm:` `md:`
`lg:` `xl:`, from 60, 80, 100 and 140 columns on), and text takes symfony/console's markup
tags. Both resolve through the stylesheet, so a theme can set everything a class sets; the
terminal draws truecolour in the nearest colours it has. Three themes ship, `dark`, `light`
and `mono`, chosen by the terminal's background or `Tui::run($root, theme: 'light')`.
([docs](docs/styling.md))

```php
use phasync\Tui\Tui;
use phasync\Tui\Element\{Box, Column, Row, Text};

Tui::run(fn() => new Column([
    new Row([
        new Text('<b>deploy</b> v1.4', class: 'grow'),
        new Text('<fg=green>●</> online', class: 'text-right'),
    ], class: 'px-1 bg-slate-800'),
    new Row([
        new Box(new Text('3 jobs running'), 'jobs', class: 'grow title-center'),
        new Box(new Text('<dim>no errors</dim>'), 'errors', class: 'grow border-emerald-500'),
    ], class: 'gap-1 flex-col lg:flex-row'),
], class: 'p-1'));
```

On a terminal 100 columns or wider (`lg:`) the two boxes stand side by side, on a narrower one
on top of each other; resizing across that width lays the screen out again.

**Markdown, as it arrives.** A `Markdown` pane renders CommonMark (headings, lists, code with
highlighting, tables, links) and takes text in chunks that end anywhere, as a model's answer
does; with `tail` it follows the end until the user scrolls back.

```php
final class Answer extends Component
{
    protected function render(): Element
    {
        return new Box($this->doc(), 'answer');
    }

    private function doc(): Markdown
    {
        return $this->child('doc', fn() => new Markdown(tail: true, scrollbar: true));
    }

    protected function run(): void
    {
        foreach (["# Hel", "lo\n\nThis *arri", "ves* in chunks: `code`, ", "[a link](https://phasync.dev)."] as $chunk) {
            $this->doc()->append($chunk);
            $this->sleep(0.2);
        }
    }
}
```

**Tabs, with a shell that keeps running.** `Tabs` shows one content per tab; a component in
a hidden tab keeps its state, so the shell goes on running while the notes show. `Tui::run()`
is the whole program: it runs the root until it quits and gives the terminal back.

```php
final class Workspace extends Component
{
    protected function bindings(): array
    {
        return ['ctrl+q' => ['dispose', 'quit', 'first' => true]];     // before the shell sees it
    }

    protected function render(): Element
    {
        return $this->child('tabs', fn() => new Tabs([
            'shell' => ['label' => 'Shell', 'content' => new Terminal(getenv('SHELL') ?: '/bin/sh')],
            'notes' => ['label' => 'Notes', 'content' => new Markdown("# Notes\n\nThe shell keeps running.")],
        ]));
    }
}

Tui::run(new Workspace());
```

Left/Right or a click change tab on the bar, Enter goes into the shell; Ctrl-Tab changes tab
from anywhere, also from inside the shell. A `first` binding runs before the focused child gets
the key (a plain binding runs after it, when nothing took the key), so Ctrl-Q quits even while
the shell has the focus. `examples/tabs.php` adds and closes tabs.

**A tree of 100,000 nodes.** `Tree` lays out and draws only the rows in view, so a large tree
costs what a screenful does; lazy children may come from an awaitable (`load:`).

```php
final class Outline extends Component
{
    protected function render(): Element
    {
        $tree = $this->child('tree', fn() => new Tree(
            items: array_map(fn(int $i) => ['label' => "section $i", 'expanded' => true, 'children' => array_map(
                fn(int $j) => ['label' => "item $i.$j", 'leaf' => true], range(1, 99))], range(1, 1000)),
            onActivate: fn(string $key) => $this->state->picked = $key,
        ));
        return new Box($tree, $this->state->picked ?? 'outline');
    }
}

Tui::run(new Outline());
```

Up/Down, Page Up/Page Down and Home/End move, Left closes a section, Enter on an item puts its
key in the title. `examples/tree.php` browses a directory, loading folders as they open.

**Inline, under the shell's output.** `Tty::inline()` shows the same components as a live
region at the bottom of the terminal instead of taking it over; `println()` writes lines above
it into the terminal's scrollback, and when the program ends the last frame stays with the
prompt under it.

```php
final class Copy extends Component
{
    private float $done = 0.0;

    protected function render(): Element
    {
        return new Row([new Text('copying', width: 8), new ProgressBar($this->done)]);
    }

    protected function run(): void
    {
        foreach (['a.txt', 'b.txt', 'c.txt', 'd.txt'] as $i => $file) {
            $this->sleep(0.2);
            $this->device()?->println("copied $file");  // above the region, scrolled out with the rest
            $this->done = ($i + 1) / 4;
            $this->requestRender();
        }
        $this->dispose();                               // the last frame stays
    }
}

fiber\run(fn() => Tui::inline(new Copy()));          // or (new Copy())->start(Tty::inline())
```

`examples/inline-progress.php` runs five jobs this way, or full screen with `--fullscreen`.

**How a program ends.** `fiber\run()` returns once nothing runs any more. Quitting (disposing
the root component, closing the root surface, or detaching the device; each leads to the others)
disposes the component tree: `Terminal`s hang their programs up with everything those started
(a shell's foreground `vim` or `sleep` too), coroutines are told to stop,
the terminal is restored. An exception in the root component, in a coroutine of it, or in the
code that started it does the same and is then thrown to you, by `start()` or by
`fiber\run()`, with the terminal already given back. `Tui::run($root)`, `Tui::inline($root)`
and `Tui::headless($root, $cols, $rows)` are the runner: they start the root, quit it on Ctrl-Q
when nothing binds that key (or when `Tui::quit()` is called, or a key bound to `'quit'` is
pressed), and return when it ended. A `Pty` you start yourself is yours to end.

**Test it headless.** `FakeTerminal` is a terminal device in memory; what it shows must equal
the root's cells, and input is encoded as a real terminal would: `type()` for text, `press()`
for keys by name, `feed()` for raw bytes as a terminal sends them.

```php
fiber\run(function () {
    $app = (new App())->start($terminal = new FakeTerminal(80, 24));
    $terminal->run(function (FakeTerminal $t) {     // detaches afterwards, also when it fails
        $t->type("hello\n");
        $t->settle();                               // the render that the input asked for
        expect($t->text())->toContain('you said: hello');
    });
});
```

Renders come in the next frame: `settle()` lets the frames that input asked for run (keys
and output may take a few). `text()` draws what is pending first, renders included, so it reads
what the next frame shows. Never `usleep()` in a coroutine: it stops the whole program, renders
included. A test that fails ends its program and comes out of `fiber\run()` at once.

## Examples

**Headless, as written.** A program run by `Tui::run()` or `Tui::inline()` (the dashboard,
`examples/working.php`, the cookbook's screens) runs without a terminal when it is started with
`--headless=COLSxROWS`, or with the environment variable `TUI_HEADLESS=COLSxROWS`: on an
in-memory terminal of that size for a second, then it prints the screen as text and exits
(`php examples/dashboard.php --headless=100x30`; `@SECONDS` after the size runs it longer or
shorter: `TUI_HEADLESS=100x30@3`). For a look at a screen in CI, over a pipe or in a bug report.
The theme of such a run (of any run whose program passes none) is chosen the same way:
`--theme=light` or `TUI_THEME=mono`.
The examples with a class start on a `Tty` themselves and need a terminal.

- `examples/working.php`: the one-file program at the top, inline; `--full` for full screen.
- `examples/split-shells.php`: two shells side by side with a status bar, a tiny tmux. Ctrl-B Tab
  or Ctrl-B and an arrow switch, Ctrl-B q quits; it runs inside itself as tmux runs in tmux.
- `examples/host.php <command...>`: any command in a window, e.g. `php examples/host.php claude`.
- `examples/coroutine-pane.php`: a shell next to a PHP coroutine printing progress bars and
  colours (with `echo` under ext-phasync).
- `examples/dashboard.php`: the dashboard at the top: three jobs, their events and release
  notes in under sixty lines without a class; `--inline`, `--theme=light|mono`.
- `examples/graphs.php`: bar charts drawn with escape sequences from coroutines, by components
  that are classes.
- `examples/inline-progress.php`: five progress bars and a total under the shell's output
  (inline mode), finished jobs printed above them; `--fullscreen` for the same full screen.
- `examples/markdown.php [file.md]`: a Markdown document streamed in chunks, followed as it
  arrives, scrolled with the keys and the wheel.
- `examples/form.php`: a form built from elements: one-line and multi-line editors, a pick list
  with filtering and marks, a confirmation dialog, the keys in a footer.
- `examples/tabs.php`: tabs holding a shell, a log that fills by itself and help pages; tabs
  added with Ctrl-N and closed with Delete.
- `examples/tree.php [directory]`: a directory browser whose folders load as they are opened.

## How it compares

symfony/console, laravel/prompts and termwind print to the terminal. symfony/tui is a widget
toolkit that paints cells; it has no terminal emulator and cannot host a program in a window.
This library emulates: every window is a terminal, widgets are programs writing to one, and the
emulator is checked against xterm behaviour, tmux reference captures and recorded output of real
programs (`ls`, `vim`, `htop`, `less`, a symfony/console progress bar). The acceptance tests run
Claude Code in a pane next to a shell (its first-run screens in a home of its own), and `split-shells.php` nested three levels deep with
resizes, focus, paste, scrollback and copy working at every level.

## Development

```bash
vendor/bin/pest                                           # the tests
php -d extension=vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so vendor/bin/pest
composer grammar                                          # recompile src/Surface/ansi.cpeg
```

See [CHANGELOG.md](CHANGELOG.md) for the changes per version.

## Licence

MIT. `src/Surface/AnsiParser.php` is generated from `src/Surface/ansi.cpeg` by
[charm/parsing](https://github.com/frodeborli/charm-parsing), whose author licenses this
generated output for use under this package's licence.
