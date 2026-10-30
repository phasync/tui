# DEVNOTES

Working notes for whoever continues this library (design decisions, what is left). Users read
README.md and docs/.

## Status (0.0.1-beta5; TODO-0.0.1-beta5.md)

All items of TODO-0.0.1-beta5.md done, in CHANGELOG.md; tests green with and without ext-phasync
(phasync 2.0.7, fiber-interop/api 0.3.1, fiber-interop/phasync 0.4.1).

- [x] 1. Focus survives any rebuild (tests/Component/FocusRebuildTest.php: the exact shape, a
      property test of random layouts around a focused leaf, component and plain surface)
- [x] 2. dispose() waits, except from the subtree's own coroutines (fiber\current());
      resolve()/reject() instead of settle() (DisposeTest.php)
- [x] 3. Log::setLines() by position; child($key) forgets disposed children; component events
      (Log/ListView `scroll`); containers accept an existing child; FakeTerminal::type() doc +
      guard; acceptance tests green

## Design decisions (0.0.1-beta5)

- **The beta5 focus bug was a lifetime bug.** A render disposed every child it had not asked
  for with `child()`; a dialog added with `->add($dialog->surface)` was disposed by the render
  that showed it (its listeners gone, its surface still hosted and focused). Rule now: after a
  render, a child is kept if it was asked for **or its surface shows in the component's
  surface** (`isShownIn()`, walking `parent()` up). Found two more defects with the property
  test: given-up nested surfaces kept their own nested surfaces hosting windows (orphan
  subtrees whose windows said hasFocus() true) -> `Host::giveUp()` recursive; a bordered
  window 1 cell wide painted 2 cells (compositor warning).
- **hasFocus() = the key path.** Walks from the root of the nearest shown ancestor's tree down
  exactly as dispatch() delivers: at a surface with a component (static WeakMap surface ->
  component) take its `unshownFocus()` child, else `focused()`. No second derivation.
- **dispose() waits** for `ended` unless `fiber\current()` is null or one of the subtree's
  task handles (own tasks, children's, and ending children's). Each go() wrapper records its
  handle with `current()` at task start (the adapter knows it before go() returns), falling
  back to go()'s return value. Handles are only compared with `current()` (as the api README
  0.3.1 documents: `$h === current()`), never awaited, so a failing root task is still
  rethrown by run(). Internal disposals (render drop, failure, close/detach) use `stop()`,
  the non-waiting half. The wait is bounded: the end runs `$timeout` after stop() at the
  latest (the beta4 background end).
- **Children vs. shown components.** `children` holds owned children and components of another
  owner a container shows (`child($key, fn() => $started)`); `parent` is the owner. Stop/end/
  runs() use owned ones only; focus routing (unshownFocus/focusRank) uses all. A disposed child
  leaves its parent's `children` at once and waits in `ending` until it ended (the parent's end
  waits for those).
- **Component events**: `on()`/`emit()`/`hasListeners()` on Component. Built-in callbacks given
  to constructors (onSubmit, onSelect, onActivate, onExit) stay as they are (the owner's
  reaction); events are for other components that show the state. Log computes
  [topLine, bottomLine, following] only with a listener; the line walk is a generator from the
  newest line that stops early (it was a full array per call). Log handles the wheel itself
  (was the host's) so every scroll goes through scrolled().
- **Compositor**: scrollback rows are not damage-tracked, so a pinned view (top < 0) has
  linesScrolled/scrollbackSize in its paint key.
- **ext-phasync 2.0.7** changed `sapi()`: the object needs `write(string): bool` (no more
  `output()` stream); SurfaceSapi passes output to Surface::write(), the stream wrapper is gone.
  Older ext versions count as absent (canCaptureOutput() checks the version).
- **SPEC vs TODO**: SPEC says go() handles are never compared; TODO-beta5 and the api README
  (0.3.1) prescribe `$handle === fiber\current()`. Followed the TODO; the comparison is the
  only use.

## Status (0.0.1-beta4; TODO-0.0.1-beta4.md)

All items of TODO-0.0.1-beta4.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] 1. One focus model before and after a component is shown (tests/Component/FocusModelTest.php)
- [x] 2. `area` event on Surface; a component renders again when its area changed (AreaTest.php)
- [x] 3. Log::scrollToLine(), topLine(), bottomLine(); scrollTo()/scrollBy() documented as rows
- [x] 4. child($key) lookup; FakeTerminal::feed() documented + tested; dispose() without
      debug_backtrace (DisposeTest.php); acceptance tests green (Benchmark/)

## Design decisions (0.0.1-beta4)

- **Focus, one rule for shown and unshown components.** The Surface tree's rule (a host focuses
  the window whose subtree holds the latest request, unless its own takeFocus() is later) is
  applied by Component to children not shown yet: `unshownFocus()` picks the unshown child whose
  subtree (`focusRank()`: its surface's `latestFocus()` plus its own unshown children) is later
  than everything the surface shows (`Surface::latestFocusBelow()`, keepStamp included). A
  component's key/paste listener (after its onKey) passes the event to that child's surface,
  where the same happens again, so keys reach the leaf the request names. `focus()` still stamps
  the nearest shown ancestor first, so the keys arrive there on the surface path. No per-request
  state (beta3's `focusPending` + `isLatestFocus()` are gone): when the render shows the
  component, the same stamps give the same path. Fallback: a component not rendered yet with
  nothing focused below passes keys to its first child (= the first window, as a host does);
  Box adopts its content in mount() for that. `Component::hasFocus()` walks up the component
  tree: on the surface path, no ancestor passing keys to another unshown child, and the top
  (an unshown ancestor) is its parent's `unshownFocus()`.
- **Area event.** `Surface::geometryChanged()` (static, @internal) is called by Host on
  host/unhost/moved/changed (border, scroll, title) and by Surface::resize(); the check runs at
  the end of the outermost `changeTree()` (or at once outside one): every surface with an `area`
  listener (static WeakMap) compares `area()` with the one told last. Re-entrant changes by
  listeners loop until quiet. Component listens and requestRender()s (batched). Cost: one area()
  walk per listening surface per tree change.
- **dispose() does not wait.** It settles stopped, wakes sleep(), disposes children (which
  return at once too), and the end (`end()`: unmount, then settle `ended`) runs when the
  component's coroutines are done (`idle` Deferred, settled by the last go() wrapper's finally)
  and every child's `ended` resolved: directly when nothing is pending, else in a fiber\go()
  that `await_all([idle, ...children ended], $timeout)`. Task handles are never awaited (that
  would mark a failing root task as awaited and keep fiber\run() from rethrowing it), stored or
  compared; only Deferreds of the library are awaited. So no self-wait is possible and no
  current-task identity is needed.

## Status (0.0.1-beta3; TODO-0.0.1-beta3.md)

All items of TODO-0.0.1-beta3.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] 1. Component::sized() (mount = props/state, sized = rectangle); Terminal starts its pty
      there; children are sized/rendered only by a parent render that shows them; a resize
      before the program's first output is followed by a second SIGWINCH at its first output
- [x] 2. Pty::hangUp()/kill() signal every process group of the session; shutdown hang-up
- [x] 3. Focus by stamps settled over the tree after every tree change
- [x] 4. Component::focus() on an unshown child routes keys through the nearest shown ancestor
- [x] 5. border/title on Split::add()/Stack::add(); Log::setLines() keeps the place; docs
- [x] acceptance: tests/Benchmark/PanesTest.php (vim/htop/less size + clean exit, quit with
      foreground jobs as string/vector, nested split-shells), BenchmarkTest still green

## Design decisions (0.0.1-beta3)

- **Focus = the latest request.** `Surface::focus()`/`takeFocus()` stamp the surface from a
  static clock. Every change to the tree (Surface::host/unhost/setLayout/resize-arrange and
  focus itself) runs through `Surface::changeTree()`; when the outermost change ends, the root
  is settled: `rankFocus()` walks the tree once, each host points at the child whose subtree
  has the highest stamp (ties keep the current one; all zero = the first window; a host's
  `keepStamp` from takeFocus() beats older children = focus on itself). The root remembers the
  path it announced (`announced`); the set difference old/new gets Focus(false)/(true), each
  surface once, the 1004 report only at a path end. A root that gets hosted (`joinTree()`)
  tells its old path it lost the focus. Host::setFocus is a plain pointer + title write.
  Replaces beta2's `focusWanted`/`Host::focusStamp`, which lost the focus when a render moved the
  focused window into another nested surface.
- **hasFocus() of an unhosted surface is true** (it is a root of its own). Unchanged; tests
  check routing instead.
- **Pending focus (item 4)** lives in Component, where batching happens: focus() on a child
  whose surface has no viewport sets `focusPending` on each ancestor up to the first shown one,
  focuses that one, then the child (so the child holds the latest stamp). The ancestor's
  key/paste listener (after its own onKey) forwards to the pending child while it is mounted,
  unshown and `isLatestFocus()`. Plain surfaces need no such thing: whoever hosts them does it
  synchronously.
- **Pty session.** The pid is the session leader (setsid -c, no fork). `processGroups()` walks
  /proc/<pid>/task/*/children recursively (a full /proc scan cost 40 ms per call with 1500
  processes and blocked the loop) and keeps every group seen in `$groups`, so the SIGKILL phase
  still reaches jobs whose shell died on SIGHUP (they are reparented and no longer below the
  pid). Children in another session (nested Ptys, daemons) are not descended into: a nested
  program ends its own ptys (Tty's signal handler exits; Pty's shutdown function hangs up).
- **Early resize**: `resizedEarly` when resize() comes before the program's first output; at the
  first output, after any stty in flight, SIGWINCH to the tty's foreground group (tpgid from
  /proc/<pid>/stat). The kernel sends no SIGWINCH for an unchanged size, hence the explicit one.
- **sized()**: firstRender() = sized(), render, go(run). A child's firstRender is only called by
  the parent's renderNow() for children whose surface has a viewport; the beta2 fallback that
  rendered unshown children at 80x24 is gone.
- **Log place**: rows of each line are computed back from the cursor row (the open line ends
  there) with the width last laid out (`laidCols`); the line at the view's top is found before
  the re-layout and its new first row scrolled to after. setLines() looks the old top line's text
  up in the new lines.

## Left for later versions

- Word wrap does not break between CJK ideographs (UAX #14).
- Log's open-line rewrite in word-wrap mode erases with the current pen (BCE).
- Ctrl-C forwarding into Claude's login screen did not exit it (also under tmux).
- Non-Linux: Pty signals only the program's own group (no /proc children walk).

## Status (0.0.1-beta2; TODO-0.0.1-beta2.md)

All items of TODO-0.0.1-beta2.md done, in CHANGELOG.md; tests green with and without ext-phasync.

- [x] Text: escape-aware width/truncate/pad, `wrap()` (char + word)
- [x] Surface tree: parent()/viewport()/root()/area() (Rect), focus() through any host,
      takeFocus(), hasFocus(), focusToward(), close(); click-to-focus inherited down the tree
- [x] Layout: Split/Stack nest layouts directly; items()/host()
- [x] Pty: onExit (start param) + exited() awaitable
- [x] Device: 1003 union of the tree; detach closes the root, closing the root detaches
- [x] Component: start(device), close -> dispose, root dispose closes, root failure disposes the
      tree + closes + rethrows, failing child disposes its children, deferred first render
- [x] Terminal exited()/restart(), Log model, TextInput history, ListView
- [x] docs, README (first program tested by tests/Examples/ReadmeTest.php), CHANGELOG, examples,
      Claude Code first-run test, tag 0.0.1-beta2

## Design decisions (0.0.1-beta2)

- **Focus by stamps.** `Surface::focus()` numbers each request (static clock). The chain from
  the top host down is set silently, then the one level where the path changed dispatches
  Focus(false) to the old branch and Focus(true) to the new one (each travels down its own focus
  path), so every leaf gets exactly one event. A surface without a viewport records the stamp
  (`focusWanted`); `Surface::host()` applies it when the host's `focusStamp` is older, so the
  latest request wins when several surfaces wait to be shown. The root records a stamp too
  (harmless: it is never hosted). Component::focusLater is gone.
- **Mirrors.** A surface may still be hosted by several hosts (the compose property test does
  this); `parent()` is the host that showed it last (`Surface::$views`, last entry).
- **Nested layouts** get their surface from `Host::nested($i)` by position among the nested items
  of the arranging layout (React-style, no keys): a component's render that builds new layout
  objects each time keeps the same intermediate surfaces, so windows, focus and geometry survive.
  Surfaces of nested layouts not placed by an arrange are given up (layout removed, windows
  unhosted). Custom layouts use the same `Surface::nestedSurface($i, $layout)`.
- **close() is a hang-up event**, not a state. Device detach -> root close (unless the detach came
  from the close); every component listens to its surface's close and disposes; root dispose
  closes first (terminal restored before children hang up). App-started Ptys do not listen.
- **Root failure** = guard() for a parent-less component: record error, dispose (which closes),
  rethrow. In start() that reaches the caller; in a coroutine fiber\run() rethrows it once all
  else ended (fiber-interop rule: unawaited task exceptions are rethrown by run()); in a key
  handler it reaches whoever delivered the key (FakeTerminal::press(), or the device's input
  coroutine and so run()).
- **First render of children** happens in the parent's renderNow() right after setLayout (sized
  by fit); a fallback go() renders children no render placed. requestRender() before the first
  render is ignored (the first render is coming).
- **Terminal** hangs its program up in unmount() (not in run(): run() never starts when the
  first render failed, which left ptys running for 30 s). onExit runs in a component go().
- **Log** keeps `lines` + `open` (the unfinished last line) and writes them into its content
  surface: character wrap is the emulator's own autowrap; word wrap writes `Text::wrap()` rows
  joined by CRLF, and the open line is erased (CR, CUU, ED) and rewritten as it grows (a full
  layout when it is taller than the window). A layout writes only the lines that fit in the
  content's rows + scrollback (rows, not lines), prefixed by the SGRs of the lines left out. On
  resize: the last QUICK_ROWS rows at once, the full layout 0.1 s later in a coroutine (a resize
  storm costs one full layout).
- **ListView** draws the selected row by re-applying the selected style (without its reset)
  after every SGR in the label, so the label's colours stay under the inverse.
- **1003**: the device walks the tree each frame (`mode(1003)` anywhere) and toggles `?1003h`;
  leaving 1003 must send `?1002h` again (mouse modes are one setting in xterm and here).
- **Claude Code test** runs it with HOME/CLAUDE_CONFIG_DIR/XDG_CONFIG_HOME in a temp dir (first
  run: theme picker, login method), never touching the user's config. Ctrl-C twice does not end
  its login screen (also under tmux), so the test disposes it.

## Left for later versions

- Word wrap does not break between CJK ideographs (UAX #14 line breaking); a run of CJK without
  spaces is one word.
- Log's open-line rewrite in word-wrap mode erases with the current pen (BCE): a background
  colour left on by an earlier line shows in the erased cells until rewritten.
- (fixed in beta3) A pinned Log kept its row index across a re-layout.
- Ctrl-C forwarding into Claude's login screen did not exit it under our emulator nor tmux;
  not investigated further.

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

- (fixed in beta2) Mouse 1003 passed through.
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
- Always a diff (spec 1e086d7): each Viewport keeps `frame` = the cells it last painted per host
  row (outer width). paint() diffs the rows to consider (source damage, all rows when the paint
  key changed) against it and writes only changed cells in runs (`diffAnsi`), skipping cells
  under windows above (`$covers`), so windows below never force a repaint above (the old
  "repaint rows painted below" pass is gone). The frame is reset on move/resize/raise
  (`invalidate`); rows the host's own producer wrote (incl. blanking) are forgotten
  (`forgetRows`) and rewritten whole. A property test checks incremental == fresh compose.
- PCRE2 < 10.43 `\X` joins consecutive pictographs ("👍👍" one cluster): `Text::graphemes()`
  splits again before each Extended_Pictographic not after ZWJ; the emulator uses it.
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
- Mouse: 1002 (button events), and 1003 while any surface in the tree has it (beta2).

## Components

- `render(): ?Layout` returning a layout -> `setLayout()`; children are reconciled by key
  (`child()`), unused keys disposed only after a render that returned a layout.
- Event hooks are surface input listeners registered at `start()`, so they run on the way
  down (capture order): the root component sees keys before its focused child.
- `requestRender()` = one `go()` that `sleep(0)`s then renders: batching without a frame clock.
- dispose() waits for the end (beta5) except from the subtree's own coroutines; the end
  itself is the beta4 mechanism: unmount() follows when the component's go() tasks have
  returned and its children have ended, at most $timeout later.
- Error boundary: a failing child unhosts its windows, draws the error (again on resize) and is
  disposed in a coroutine; the root rethrows (so `Tty` restores the terminal on the way out).
- `focus()` before the parent shows the child: see beta4 (one focus model).
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

- **Finding (beta3, resolved in api 0.3.1 by `fiber\current()`, used since beta5):** fiber-interop/api had no way to ask "which task am I" (a
  current-task handle comparable with what go() returned). A component disposing itself from
  one of its own coroutines must not wait for that coroutine. beta3 found the component's task
  wrapper on the call stack (debug_backtrace), which is going beneath the API like
  Fiber::getCurrent(); beta4 removed it: dispose() never waits, the component's end waits for
  its coroutines in a coroutine of its own (see beta4 decisions). The cost: dispose() can no
  longer promise that unmount() has run when it returns. The smallest addition that would let
  it: `fiber\current(): ?object` returning the handle go() gave for the running task,
  comparable by identity.

- `fiber\Deferred` is public since fiber-interop/api 0.3.1 (`resolve()`, `reject()`,
  `isSettled()`); the library uses those, no longer `settle()`.
- `await_any([$work, $stopped])` starts one racer task per awaitable; a racer waiting on a
  long-lived `$stopped` stays suspended until it settles. In a loop (frame loop, component
  sleeps) that accumulates tasks. `Internal\Signal` (one fresh Deferred per wait, dropped after)
  is used instead everywhere a loop waits.
