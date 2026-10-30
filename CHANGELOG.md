# Changelog

## 0.0.1-beta1 (2026-10-30)

The first version: a terminal UI library built on a real terminal emulator.

- **Surface**, a terminal emulator in memory: xterm-compatible (`TERM=xterm-256color`), parsed
  character by character by a parser generated from a PEG grammar. Text with autowrap,
  grapheme clusters and wide characters, cursor movement, erasing, insert/delete, scroll regions,
  SGR with 16/256/truecolor, private modes (alternate screen, mouse, bracketed paste, focus
  events, synchronized output, application keys), the kitty keyboard protocol and
  modifyOtherKeys, tab stops, charsets including DEC line drawing, titles, OSC 52 clipboard,
  scrollback, and answers to the queries programs send (cursor position, device attributes,
  versions, modes, colours). Damage tracking for any number of readers.
- **Producers**: `Pty` runs a program in a pseudo-terminal with a controlling terminal and the
  surface's size, following resizes; `Surface::run()` runs a PHP closure as a producer, capturing
  its `echo` with ext-phasync. `Input`, `InputDecoder`, and the `Key`, `Mouse`, `Paste`, `Focus`
  events.
- **Hosting**: a Surface hosts Surfaces through `Viewport`s to any depth. Hosts composite by
  writing escape sequences to themselves, always as a diff: only the cells that changed, in runs,
  never those under a window above, so a busy pane costs its changed cells and an idle one nothing; input goes down the focus path and is encoded for each
  program's modes; mouse wheel scrolling and drag-to-select with copy (OSC 52) where a program did
  not ask for the mouse; titles, bells and clipboard requests go up.
- **Terminal devices**: `Tty` attaches the real terminal to a root Surface (minimal redraws at up
  to 60 frames a second, input decoding, resizes, raw mode and restore on exit, crashes and
  signals, kitty keyboard protocol when available); `FakeTerminal` does the same in memory for
  tests and headless programs.
- **Layout**: `Split` (side by side or stacked, sizes in cells, percentages or weights, optional
  separators) and `Stack` (overlays and dialogs).
- **Components**: `Component` with mount/render/run/unmount, batched renders, keyed children,
  coroutines that end on disposal, event handlers and an error boundary; built-ins `Terminal`,
  `Log`, `TextInput`, `StatusBar` and `Box`.
- **Examples**: `split-shells.php` (two shells, nestable like tmux in tmux), `host.php` (any
  command in a window, e.g. Claude Code), `coroutine-pane.php`, `dashboard.php`.
