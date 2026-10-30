# DEVNOTES

Working notes for whoever continues this library (design decisions, what is left). Users read
README.md and docs/.

## Status (0.0.1-beta1)

Built bottom-up in the order of SPEC.md. Done layers are committed one by one.

- [x] Surface, VT parser (charm/parsing grammar), Ansi, tests incl. tmux reference + recorded programs
- [x] Pty, Surface::run() coroutine producer (echo capture via ext-phasync sapi())
- [x] Viewport (+ Text width utility)
- [ ] Screen + FakeTerminal + Tty + input decoder + key/mouse encoding
- [ ] Layout (Split, Stack, focus)
- [ ] Component + built-ins (Terminal, Log, TextInput, StatusBar, Box)
- [ ] examples, README, docs/, CHANGELOG, tag

## Surface / emulator

- `src/Surface/ansi.cpeg` is the grammar; `AnsiParser.php` is generated (`composer grammar`),
  `tests/Surface/GrammarTest.php` fails when it is stale. charm/parsing is a dev dependency only:
  the generated class is standalone. (charm/parsing is GPL-3.0; the generated file contains the
  compiler's template code. Frode owns charm/parsing, so this is his call; flagged here.)
- The grammar runs on valid UTF-8: `Surface::write()` holds back a code point cut at the end of
  a chunk and scrubs malformed bytes to U+FFFD *before* parsing (compiled regexes always carry
  the `/u` flag, and PCRE refuses invalid UTF-8 subjects). C1 controls are U+0080-U+009F as in
  xterm's UTF-8 mode.
- Every sequence rule is total (complete, cancelled by CAN/SUB, or aborted by ESC/C1), so a
  rule fails only at end of input, and the last rule `Incomplete` keeps the tail
  (`Emulator::$pending`, capped at 1 MiB). Semantic actions only run for complete top-level
  items, so a retried tail never acts twice. Embedded C0 controls in sequences are collected and
  executed by the action before dispatch (VT500 behaviour).
- charm/parsing compiler findings (worked around, worth fixing upstream):
  - a snippet returning `false` does **not** fail the alternative in compiled parsers (the
    README says it should); the grammar is written so no action needs to refuse.
  - `...name:Clause` (spread + named capture) leaves `$name` undefined in compiled snippets.
  - string literals only know `\\ \" \' \n \r \t`; ESC must be written as the regex `/\x1b/`.
- Cell storage: `Line` = parallel arrays of graphemes and interned `Style` objects. A wide char
  is the grapheme followed by a '' continuation cell. Width table generated from ICU into
  `Surface/Width.php` (`tools/generate-widths.php`).
- Damage: per-row version numbers (`version()`, `damagedSince()`), so several readers can
  track one surface; `takeDamage()` is the single-reader convenience.
- xterm vs tmux differences found by the reference tests (Surface follows xterm): BS with
  wrap pending moves to cols-2; HPR/VPR exist; tmux capture shows raw ACS chars.
- Not implemented (on purpose, rare): left/right margins (DECSLRM), double-width lines,
  reverse wrap, 132-column mode, OSC 4 palette queries (no reply), DECRQSS.
- Speed: ~5 MB/s plain text, ~1.5-2 MB/s heavily coloured (PHP 8.5, no JIT). The ASCII fast path
  stores a row's share with array_splice.

## Producers

- Pty: proc_open with three 'pty' descriptors gives no controlling terminal and no new session
  (checked: `ps` shows TT `?`), so Ctrl-C, job control and SIGWINCH would not work. On Linux the
  command is wrapped in `setsid -c` (same pid, pty becomes the controlling tty). Initial size is
  set by `stty cols C rows R; exec "$@"` before the program starts; later resizes by
  `stty -F <slave>` in a helper process, serialised (latest size wins). The slave path comes
  from /proc/<pid>/fd/0 (fallback `ps -o tty=`).
- The master reads return EIO when the program exits; that is the end of the stream, read
  under a temporary error handler so it is not reported as a notice.
- Surface::run(): with ext-phasync, `phasync\ext\sapi($code, $sapi)` (the sapi object needs
  name(), output() returning a stream, send_headers()); the stream is a tiny userland wrapper
  writing to the Surface. Output of coroutines started inside sapi() is lost once sapi() returns,
  so the closure is wrapped in a nested `fiber\run()` (children finish inside the capture).

## fiber\ notes

- `fiber\Deferred` is marked `@internal` in fiber-interop/api 0.3.0 but SPEC.md names it; used
  only inside `Internal\Signal`. Any then-able would do, so replacing it is a 10-line change.
- `await_any([$work, $stopped])` starts one racer task per awaitable; a racer waiting on a
  long-lived `$stopped` stays suspended until it settles. In a loop (frame loop, component
  sleeps) that accumulates tasks. `Internal\Signal` (one fresh Deferred per wait, dropped after)
  is used instead everywhere a loop waits.
