# phasync/tui

A terminal UI library for PHP built on a real terminal emulator. Think tmux as a library: every
window is a terminal, so a shell, `vim`, `htop` or Claude Code runs in a pane of your PHP program
exactly as in a terminal, next to widgets that are themselves programs writing to a terminal.

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

A chat-like log with an input line next to your shell, in any terminal size: the log keeps its
text and wraps it again whenever the terminal is resized, the shell is told its new size.
Alt-Left/Alt-Right move between them; Ctrl-Q quits, which hangs the shell up and gives the
terminal back, and `fiber\run()` returns.

PHP 8.3+, MIT. Runs on any [fiber-interop](https://packagist.org/packages/fiber-interop/api)
runtime: phasync, Revolt/AMPHP, ReactPHP.

## Install

```bash
composer require phasync/tui fiber-interop/phasync     # or fiber-interop/revolt, /amp, /react
```

`fiber-interop/api` is the only runtime dependency; install one adapter to choose the event
loop. Optional: ext-phasync 2.0.7+ (bundled with `phasync/phasync`) captures `echo` of a PHP coroutine
into a window; ext-pcntl gives immediate resizes and terminal restore on SIGTERM.

## The model

One primitive, one structure:

1. **Surface** ([docs](docs/surface.md)): a terminal emulator in memory, xterm-compatible.
   Bytes in with `write()`, cells out with `cell()`, `line()`, `text()`. ANSI escape sequences
   are the only way anything gets onto a Surface; there is no cell-drawing API, and the library
   draws its own windows, borders and widgets with escape sequences too.
2. **Producers** ([docs](docs/producers.md)): what writes into a Surface. A program in a `Pty`,
   a PHP closure with `Surface::run()` (whose `echo` is captured with ext-phasync), or your own
   code with the `Ansi` helper. Input goes back through `$surface->input`.
3. **Hosting** ([docs](docs/hosting.md)): a Surface hosts other Surfaces through `Viewport`s, to
   any depth. The host repaints its windows into itself by writing escape sequences to itself;
   input events go down the focus path and are encoded for each program's modes. The screen of
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
   `Terminal`, `Log`, `TextInput`, `ListView`, `StatusBar`, `Box`.
7. **Widgets** ([docs](docs/widgets.md)): `Editor` (with history and completion), `Select`
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

**Inline, under the shell's output.** `Tty::inline()` shows the same components as a live
region at the bottom of the terminal instead of taking it over; `println()` writes lines above
it into the terminal's scrollback, and when the program ends the last frame stays with the
prompt under it.

```php
final class Copy extends Component
{
    private float $done = 0.0;

    public function __construct(private readonly TerminalDevice $tty) {}

    protected function render(): Element
    {
        return new Row([new Text('copying', width: 8), new ProgressBar($this->done)]);
    }

    protected function run(): void
    {
        foreach (['a.txt', 'b.txt', 'c.txt', 'd.txt'] as $i => $file) {
            $this->sleep(0.2);
            $this->tty->println("copied $file");        // above the region, scrolled out with the rest
            $this->done = ($i + 1) / 4;
            $this->requestRender();
        }
        $this->dispose();                               // the last frame stays
    }
}

$tty = Tty::inline();
fiber\run(fn() => (new Copy($tty))->start($tty));
```

`examples/inline-progress.php` runs five jobs this way, or full screen with `--fullscreen`.

**How a program ends.** `fiber\run()` returns once nothing runs any more. Quitting (disposing
the root component, closing the root surface, or detaching the device; each leads to the others)
disposes the component tree: `Terminal`s hang their programs up with everything those started
(a shell's foreground `vim` or `sleep` too), coroutines are told to stop,
the terminal is restored. An exception in the root component does the same and is then thrown
to you, by `start()` or by `fiber\run()`. A `Pty` you start yourself is yours to end.

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

Renders come in the next frame: read the screen after `settle()`, not right after the input
(and never `usleep()` in a coroutine: it stops the whole program, renders included).

## Examples

- `examples/split-shells.php`: two shells side by side with a status bar, a tiny tmux. Ctrl-B Tab
  or Ctrl-B and an arrow switch, Ctrl-B q quits; it runs inside itself as tmux runs in tmux.
- `examples/host.php <command...>`: any command in a window, e.g. `php examples/host.php claude`.
- `examples/coroutine-pane.php`: a shell next to a PHP coroutine printing progress bars and
  colours (with `echo` under ext-phasync).
- `examples/dashboard.php`: graphs and boxes drawn with escape sequences from coroutines.
- `examples/inline-progress.php`: five progress bars and a total under the shell's output
  (inline mode), finished jobs printed above them; `--fullscreen` for the same full screen.
- `examples/markdown.php [file.md]`: a Markdown document streamed in chunks, followed as it
  arrives, scrolled with the keys and the wheel.
- `examples/form.php`: a form built from elements: one-line and multi-line editors, a pick list
  with filtering and marks, a confirmation dialog, the keys in a footer.

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
