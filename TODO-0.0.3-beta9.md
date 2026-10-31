# 0.0.3-beta9: the last 0.0.3 round — what a person at the keyboard found, and housekeeping

Everything from 0.0.3-beta8 keeps working. This is a small round; if nothing new is found,
0.0.3 is final. Items 1-3 come from the author running the examples on a real terminal.

## 1. Every example says how to leave it, and leaves by itself when its program ends

- Every example shows its quit key in a `Help` line. The pane-hosting examples
  (`coroutine-pane.php`, `split-shells.php`) keep their Ctrl-B prefix but also quit on
  Ctrl-B Ctrl-Q, and their Help line names both.
- `coroutine-pane.php`: when the shell in the left pane exits (`exit`, Ctrl-D, killed), the
  program shows a goodbye for three seconds and ends by itself; today it waits forever and the
  user has no clue. `Pty::exited()` already exists; what is missing is a floating overlay on a bare
  Surface root (a `Layers`-like stack at the hosting layer, or `Dialog::open()` working on a root
  that is not a component) and a `Dialog(timeout:)` / `Confirm(timeout:)` that closes itself.
  `host.php` gets the same behaviour when the hosted command exits (status shown, then end).
- The pane's progress coroutine ends by cancellation when the program ends (it polls
  `$tty->isAttached()` every 30 ms today).

## 2. `Terminal` exit event, `Dialog` timeout, `fps:`

- `Terminal` emits an `exit` event with the process status (and `onExit:` on the element).
- `Dialog`/`Confirm` take `timeout:` seconds; the answer is `null` (or the default button) when it
  expires.
- `Tui::run/inline/headless(fps:)` caps the frame rate (default 60); the device honours it.

## 3. The parser is ours

`src/Surface/AnsiParser.php` is part of this MIT package. Remove every licence caveat about it
(README "Licence" section becomes "MIT."; the DEVNOTES note about GPL and the generator goes),
remove `charm/parsing` from `composer.json` entirely (it was `dev-master` in require-dev), keep
`src/Surface/ansi.cpeg` and the `grammar` script as the way to regenerate with a globally
installed `charm-compile` (say so in DEVNOTES), and make `tests/Surface/GrammarTest.php` skip
when the compiler is not installed instead of failing. The generated file's header keeps its
"generated from ansi.cpeg" provenance line.

## 4. Housekeeping for 0.0.3 final

- Delete the merged `widget/*` branches (local and remote) after checking each is merged.
- README: one screenshot (text) of the dashboard at the top, the install line, the three-minute
  tour; make sure every code block in README and docs runs (ReadmeTest already does this; extend
  it to docs/components.md and docs/widgets.md blocks that are whole programs).
- CHANGELOG: a `## 0.0.3` summary section above the beta sections listing what 0.0.3 brought
  over 0.0.2 in ten lines.
- CHANGELOG under `## 0.0.3-beta9`.
