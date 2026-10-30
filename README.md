# phasync/tui

A terminal UI library for PHP built on a real terminal emulator. Think tmux as a library: every
window is a terminal, so a shell, `vim`, `htop` or Claude Code runs in a pane of your PHP program
exactly as in a terminal, next to widgets that are themselves programs writing to a terminal.

```php
use phasync\Tui\{Key, Pty, Split, Surface, Tty};

$tty = new Tty();
fiber\run(function () use ($tty) {
    $root = new Surface();
    $left = new Surface();
    $right = new Surface();
    $root->setLayout(Split::horizontal(separators: true)->add($left)->add($right));
    $root->setFocusOnClick(true);
    $root->on('key', function (Key $key) use ($tty) {
        if ($key->is('ctrl+q')) {
            $tty->detach();
            return true;
        }
    });

    $tty->attach($root);
    $shell = Pty::start(getenv('SHELL') ?: '/bin/sh', $left);
    $top = Pty::start('top', $right);
    $tty->wait();
    $shell->kill(1);
    $top->kill(1);
});
```

PHP 8.3+, MIT. Runs on any [fiber-interop](https://packagist.org/packages/fiber-interop/api)
runtime: phasync, Revolt/AMPHP, ReactPHP.

## Install

```bash
composer require phasync/tui fiber-interop/phasync     # or fiber-interop/revolt, /amp, /react
```

`fiber-interop/api` is the only runtime dependency; install one adapter to choose the event
loop. Optional: ext-phasync (bundled with `phasync/phasync`) captures `echo` of a PHP coroutine
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
   children and error boundaries; built-ins `Terminal`, `Log`, `TextInput`, `StatusBar`, `Box`.

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
$root->setLayout(Split::horizontal()->add($log));
$root->host($log)->setBorder(true)->setTitle('log');
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
        $tty->detach();
        return true;          // handled: it goes no further down
    }
});
$tty->wait();
```

**Components** give pieces of UI a lifecycle and a coroutine:

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

$app = (new App())->start();
$tty->attach($app->surface);
```

**Test it headless.** `FakeTerminal` is a terminal device in memory; what it shows must equal
the root's cells, and input is encoded as a real terminal would.

```php
$terminal = new FakeTerminal(80, 24);
$terminal->attach($app->surface);
$terminal->type("hello\n");
expect($terminal->text())->toContain('you said: hello');
```

## Examples

- `examples/split-shells.php`: two shells side by side with a status bar, a tiny tmux. Ctrl-B Tab
  switches, Ctrl-B q quits; it runs inside itself as tmux runs in tmux.
- `examples/host.php <command...>`: any command in a window, e.g. `php examples/host.php claude`.
- `examples/coroutine-pane.php`: a shell next to a PHP coroutine printing progress bars and
  colours (with `echo` under ext-phasync).
- `examples/dashboard.php`: graphs and boxes drawn with escape sequences from coroutines.

## How it compares

symfony/console, laravel/prompts and termwind print to the terminal. symfony/tui is a widget
toolkit that paints cells; it has no terminal emulator and cannot host a program in a window.
This library emulates: every window is a terminal, widgets are programs writing to one, and the
emulator is checked against xterm behaviour, tmux reference captures and recorded output of real
programs (`ls`, `vim`, `htop`, `less`, a symfony/console progress bar). The acceptance tests run
Claude Code in a pane next to a shell, and `split-shells.php` nested three levels deep with
resizes, focus, paste, scrollback and copy working at every level.

## Development

```bash
vendor/bin/pest                                           # the tests
php -d extension=vendor/phasync/phasync/ext/phasync-8.5-nts-x86_64-glibc.so vendor/bin/pest
composer grammar                                          # recompile src/Surface/ansi.cpeg
```

See [CHANGELOG.md](CHANGELOG.md) for the changes per version.
