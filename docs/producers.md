# Producers: what writes into a Surface

Anything that writes bytes into a Surface is its *producer*, and every producer has the same
shape: output goes in with `write()`, input comes out of `$surface->input`. A shell in a pty, a
PHP closure that `echo`es and a widget drawing a graph are interchangeable.

Concurrency in this library goes through [fiber-interop/api](https://packagist.org/packages/fiber-interop/api)
(`fiber\run()`, `fiber\go()`, `fiber\sleep()` ...), so it runs unchanged on phasync, Revolt/AMPHP
and ReactPHP. Everything that waits must run inside `fiber\run()`; nothing blocks other coroutines.

## Pty: a program in a pseudo-terminal

```php
use phasync\Tui\{Pty, Surface};

fiber\run(function () {
    $surface = new Surface(80, 24);
    $pty = Pty::start(['ls', '--color=auto'], $surface);
    $code = $pty->wait();               // exit status, once all output is in the surface
    echo $surface->dump();
});
```

The program sees a real terminal of the surface's size: `TERM=xterm-256color`,
`COLORTERM=truecolor`, a controlling terminal (so Ctrl-C, job control and SIGWINCH work; on Linux
via `setsid -c`), and the size from its first instruction. Its output is written into the
surface as it arrives; what is written to the surface's `input` (keys from `dispatch()`, pastes,
replies to its queries) goes to the program. Resizing the surface resizes the terminal (with
`stty -F <slave>` in a helper process), which sends SIGWINCH to the program; a size set while
the program is still starting (before its first output) is announced once more with a second
SIGWINCH when it first writes, for programs that install their handler late (vim). Descriptors
of the PHP process are not passed on to the program.

**Ending a Pty ends everything it started.** The program leads a session of its own with the pty
as its controlling terminal, as a login shell does on a real terminal; what it starts (a shell's
jobs, an editor, a nested multiplexer) is in that session. `hangUp()` does to all of it what
closing a terminal window does, and `kill()` signals all of it: every process group of the
session, so a shell running `sleep`, `vim` or a build in the foreground ends with its pty, and
`fiber\run()` returns. A string command and an argument vector behave the same. Ptys still
running when the PHP process exits (`exit()`, an uncaught exception, a signal) are hung up on
the way out. A program that starts a session of its own (a daemon, or a nested program's own
ptys) is not part of it; a nested program built on this library ends its own ptys when it is
hung up.

| Method | What it does |
|---|---|
| `Pty::start(string\|array $command, Surface $surface, ?string $cwd = null, array $env = [], ?Closure $onExit = null): Pty` | Start a command: a string runs with `/bin/sh -c`, an array is an argument vector. `$env` is added to the inherited environment. `$onExit(int $code, Pty $pty)` is called once when the program has ended and its output is in the surface. Throws `RuntimeException` if it cannot start. |
| `pid(): int` | The program's process id. |
| `isRunning(): bool` | True until the program has exited and its terminal has closed. |
| `exitCode(): ?int` | The exit status once it has ended (128 + signal when killed by one), else null. |
| `wait(float $timeout = PHP_FLOAT_MAX): ?int` | Wait until the program has ended and all its output is in the surface; null if the timeout passed first. |
| `exited(): object` | An awaitable that resolves with the exit status at the same moment: `fiber\await($pty->exited())`, or race it against other work with `fiber\await_any()`. |
| `kill(int $signal = 15)` | Send a signal to the program and everything it started on the pty (every process group of its session). SIGKILL (9) ends all of it for certain. |
| `hangUp(float $grace = 0.5): ?int` | End the program the way closing its terminal window does: SIGHUP and SIGCONT to every process group of its session, SIGKILL after `$grace` seconds for what is left. Returns the exit status once the terminal has closed (at once if it had ended), or null if something holds it open even then. Call it in a coroutine. The usual way to end a Pty. |
| `write(string $bytes)` | Send bytes to the program as if typed, bypassing the surface's input. |
| `resize(int $cols, int $rows)` | Set the terminal size. Happens by itself when the surface is resized; call it only for a pty whose surface you size differently. |
| `device(): ?string` | The pty's slave device, such as `/dev/pts/3`. |

A Pty you start is yours: it runs (and keeps `fiber\run()` from returning) until the program
exits or you end it (`hangUp()`, `kill()`); detaching the terminal does not end it. The `Terminal`
[component](components.md) wraps a Pty with a lifecycle that does: disposing the component (and
so quitting the program) hangs its program up.

## A PHP coroutine: `Surface::run()`

```php
$task = $surface->run(function (Surface $surface) {
    echo "\e[32mready\e[0m\n";                 // captured with ext-phasync
    $surface->write("works everywhere\r\n");   // always works
    $name = $surface->input->read();           // what the user types into this surface
});
fiber\await($task);
```

`run(Closure $code): object` runs `$code` in a new coroutine as the producer of the surface and
returns its awaitable. The closure receives the surface.

- **With ext-phasync loaded** (`Surface::canCaptureOutput()` is true), the closure runs under
  `phasync\ext\sapi()`: everything it prints with `echo`, `print`, `printf` or `var_dump()` lands
  in the surface, including the output of coroutines it starts (they are awaited before it
  ends). Output made for a terminal (colours, `\r` progress bars, symfony/console and
  laravel/prompts-style ANSI) renders as it would in one. Note that `echo "\n"` is a line feed
  only, as in a raw terminal: write `"\r\n"` to start a line at the left.
- **Without it**, output is not captured (it goes to the real STDOUT). Write with
  `$surface->write()`, which works in both cases. `STDOUT` itself is never captured.

## Input: what the producer reads

`$surface->input` is an `Input`: the bytes a program in a terminal reads from its stdin.

| Method | What it does |
|---|---|
| `read(int $max = 8192, float $timeout = PHP_FLOAT_MAX): string` | Wait (cooperatively) for bytes and return up to `$max`; `''` when closed and empty or when the timeout passed. |
| `peek(): string` | The bytes waiting, without removing them. |
| `write(string $bytes)` | Queue bytes for the producer (the surface does this for events and replies). |
| `pipe(?Closure $sink)` | Deliver input straight to `$sink` instead of buffering (what a Pty does); null goes back to buffering. |
| `close()`, `isClosed()` | End the channel: readers get what remains, then `''`. |

Without a reader or a pipe, only the newest 1 MiB is kept.

## Decoding input: `InputDecoder`

A coroutine producer that wants events rather than bytes decodes its input with
`InputDecoder`, the same decoder the terminal devices use for the real terminal:

```php
$decoder = new InputDecoder();
while (true) {
    $bytes = $surface->input->read(timeout: $decoder->hasPending() ? 0.05 : PHP_FLOAT_MAX);
    $events = $bytes === '' ? $decoder->flush() : $decoder->feed($bytes);
    foreach ($events as $event) {
        if ($event instanceof Key && $event->is('q')) { return; }
    }
}
```

| Method | What it does |
|---|---|
| `feed(string $bytes): list<Event>` | Decode bytes; a sequence cut off at the end is kept for the next call. |
| `flush(): list<Event>` | Decide what is pending as if no more bytes follow: a lone ESC is the Escape key, ESC + character is Alt+character. Call it when no bytes came for about 50 ms. |
| `hasPending(): bool` | True when bytes are waiting that `flush()` would decide about. |

It understands legacy xterm keys (control characters, `CSI` and `SS3` sequences with modifiers),
Alt as an ESC prefix, UTF-8 text, the kitty keyboard protocol (`CSI ... u`), modifyOtherKeys
(`CSI 27 ; m ; code ~`), SGR (1006) and X10 mouse reports, bracketed paste and focus reports.
Replies to queries (cursor position, device attributes, colours) are dropped, never taken for keys.

## Events

- `Key`: `name` (a typed character as typed, or `enter`, `tab`, `backspace`, `escape`, `space`,
  `up`, `down`, `left`, `right`, `home`, `end`, `pageup`, `pagedown`, `insert`, `delete`,
  `f1`-`f12`), `ctrl`, `alt`, `shift`, `text` (what it types; empty with Ctrl or Alt).
  `Key::parse('ctrl+shift+up')` builds one, `$key->is('ctrl+c')` matches the same notation,
  `isText()` is true for a key that types, `(string) $key` gives the notation.
- `Mouse`: `action` (`Mouse::PRESS`, `RELEASE`, `DRAG`, `MOVE`, `WHEEL_UP`, `WHEEL_DOWN`,
  `WHEEL_LEFT`, `WHEEL_RIGHT`), `x`, `y` (0-based cells), `button` (`Mouse::LEFT`, `MIDDLE`,
  `RIGHT`, or null), `ctrl`, `alt`, `shift`; `at($x, $y)` copies it to another position,
  `isWheel()`.
- `Paste`: `text`, with line breaks as `"\n"`.
- `Focus`: `focused` (true when gained).
