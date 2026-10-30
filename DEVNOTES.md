# DEVNOTES

Working notes for whoever continues this library (design decisions, what is left). Users read
README.md and docs/.

## Status (0.0.1-beta1)

Built bottom-up in the order of SPEC.md. Done layers are committed one by one.

- [x] Surface, VT parser (charm/parsing grammar), Ansi, tests incl. tmux reference + recorded programs
- [x] Pty, Surface::run() coroutine producer (echo capture via ext-phasync sapi())
- [x] Viewport (+ Text width utility)
- [x] Hosting (spec rev. 59802cf, self-similar): Surface::host() / Viewport, compositing by
      writing, dispatch() down the focus path, input decoder + encoder. The old Screen is gone.
- [x] Terminal devices: TerminalDevice (abstract) + Tty + FakeTerminal
- [x] Layout (Split, Stack); focus is on Surface (focus, focusNext, setFocusOnClick)
- [x] Component + built-ins (Terminal, Log, TextInput, StatusBar, Box)
- [x] examples (split-shells, host, coroutine-pane, dashboard), README, docs/, CHANGELOG
- [x] benchmark tests: split-shells nested 3 levels; Claude Code next to a shell
- [x] tag 0.0.1-beta1

## Left for later versions (known limits)

- Mouse: the device enables 1002 only; a program asking for 1003 (any motion) gets motion only
  while a button is held. Passing the union of the tree's mouse modes up would fix it.
- The Claude Code test stops at the folder-trust prompt (accepting it would write the user's
  Claude config); typing into its prompt, its bracketed paste and its redraw loop are covered
  only by the generic tests and by hand (`php examples/host.php claude`).
- No reflow on resize (non-goal); DECSLRM, double-width lines, OSC 4, DECRQSS not implemented.
- `fiber\Deferred` is `@internal` in fiber-interop/api 0.3.0 but SPEC names it; used for
  `Component::stopped()` and in `Internal\Signal`. Any then-able would do.
- charm/parsing is required as `dev-master` (no tagged release on Packagist yet).
- Compositing cost grows with nesting (each level re-parses its windows' rows); fine for
  normal sizes, measure before optimising.

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

## Hosting (the self-similar structure)

- One viewport per hosted surface per host (`host()` returns the existing one). Viewports are
  created only by `Surface::host()`; geometry is set by the caller or a layout, presentation
  (border, title, scroll, selection) lives on the viewport.
- Compositing is lazy: a child's `change` marks the host dirty and the host emits `change` (so
  the root's device schedules a frame); the host composes when its cells/damage/version are
  read or `compose()` is called. Compose = children first, then for each viewport in z-order
  the rows damaged in it, the host rows its own producer changed, and rows repainted by an
  overlapping window below; written to itself as `ESC 7 ESC[?6l SI ESC(B [ESC[4l] ... ESC[0m
  [ESC[4h] ESC 8` so the host's producer keeps cursor, pen, charsets, origin and insert mode.
- Moving/removing a window blanks its old rect at once (ECH, same wrapper); the host's own
  writes count as damage under windows, so what was below repaints at the next compose.
  Layouts draw separators after placing windows, so blanking cannot erase them.
- Mode 2026 on a hosted surface: the host skips that window (keeps it dirty) up to 0.5 s.
- Title: the host writes the focused child's title to itself (OSC 2) on change and on focus
  change; bell and OSC 52 are written up unconditionally. So they reach the root by writing.
- Cursor: not written up (it would break the host producer's cursor); `activeCursor()` walks
  the focus path. The device shows the root's activeCursor.
- Input: `dispatch()` = listeners (`on('key')` ... returning true consumes), then Host::route
  (focused child; mouse to the topmost window under the pointer with capture from press to
  release), then the leaf encodes. A host uses unwanted mouse events itself: wheel scrolls
  the viewport by 3, a left drag selects (copied on release with OSC 52 written to itself).
  Typing or pasting into a window clears its selection and makes it follow again.
- Tab cycling is not bound by default (shells need Tab): `focusNext()` is bound by the
  program. split-shells uses a prefix key (Ctrl-B) so it nests like tmux in tmux.

## Terminal device

- `TerminalDevice` is abstract (frame loop, differ, decode, probe); `Tty` and `FakeTerminal`
  implement open/close/send/read/size. Kitty: query `CSI ? u` + DA1 at attach, push `CSI > 1 u`
  if the answer has `CSI ? n u`; the restore string always pops (`CSI < u`), harmless.
- FakeTerminal pipes its surface's input straight to `receive()`, so input is synchronous;
  its read() only idles. `text()`/`dump()` refresh first; `waitFor()` for pty output.
- Mouse: 1002 (button events) only; programs asking for 1003 get motion only while a
  button is held. Not passing 1003 through is a known limit.

## Components

- `render(): ?Layout` returning a layout -> `setLayout()`; children are reconciled by key
  (`child()`), unused keys disposed only after a render that returned a layout.
- Event hooks are surface input listeners registered at `start()`, so they run on the way
  down (capture order): the root component sees keys before its focused child.
- `requestRender()` = one `go()` that `sleep(0)`s then renders: batching without a frame clock.
- Disposal waits (await_all, 1 s) for the component's go() tasks except the calling fiber
  (`Fiber::getCurrent()` comparison; plain PHP, no runtime type).
- Error boundary: a failing child unhosts its windows, draws the error (again on resize) and is
  disposed in a coroutine; the root rethrows (so `Tty` restores the terminal on the way out).
- `focus()` before the parent shows the child is remembered and applied after setLayout.
- Terminal: run() waits for the pty; a second go() sleeps until disposal, then SIGHUP, 0.5 s,
  SIGKILL. Log: its own content surface hosted with a Stack, so it scrolls by itself.

## Producers

- Pty: proc_open with three 'pty' descriptors gives no controlling terminal and no new session
  (checked: `ps` shows TT `?`), so Ctrl-C, job control and SIGWINCH would not work. On Linux the
  command is wrapped in `setsid -c` (same pid, pty becomes the controlling tty). Initial size is
  set by `stty cols C rows R; exec "$@"` before the program starts; later resizes by
  `stty -F <slave>` in a helper process, serialised (latest size wins). The slave path comes
  from /proc/<pid>/fd/0 (fallback `ps -o tty=`).
- proc_open() passes all descriptors of the PHP process to the child (other ptys' masters
  included), so a hung-up program would never see EOF/SIGHUP. The wrapper (bash when found,
  else sh which can only close 3-9) closes every inherited descriptor > 2 before exec.
- The wrapper prints `OSC 7701;phasync-tui-ready BEL` after its initial `stty`; the pump strips
  it and only then applies resizes (a resize before it was overwritten by the wrapper's stty).
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
