# 0.0.3-beta4: two focus defects, and the last rough edges of the one-file model

Everything from 0.0.3-beta3 keeps working. Items 1 and 2 are defects with the exact shapes from
use (item 1 was reported fixed in beta3 but the shape below still fails; reproduce this shape
before changing anything, and keep the beta3 variant's test); 3 is small. If this round finds
nothing new, 0.0.3 is done.

## 1. Tab is trapped after a hidden `child()` Terminal tab was shown and left

The exact shape. An owner component whose `render()` does, on every render:

```php
$this->table = $this->child('table', fn() => new Table(columns: ['A' => 'auto'], rows: [1 => ['x'], 2 => ['y']]));
$this->tree  = new Tree(items: [['label' => 'g', 'key' => 'g']]);          // a fresh instance each render
$this->tabs  = $this->child('tabs', fn() => new Tabs());
$this->tabs->setTabs([
    'a' => ['label' => 'Table', 'content' => new Row([$this->table, new Box($this->tree, 't')])],
    'b' => ['label' => 'Shell', 'content' => $this->child('term', fn() => new Terminal(['sh']))],
]);
return new Column([$this->tabs->with(class: 'grow'), new Help()]);
```

under `Tui::headless($app, 60, 8)`, with 0.1 s and a `settle()` between steps so every tab has
rendered: Tab moves table → tree, Shift-Tab back; Alt-2 shows the shell (it has the focus); Alt-1
back (the table has it). Now Tab leaves the focus on the table on every press; Shift-Tab still
moves (to nobody: table 0, tree 0). Required: Tab walks table → tree → tab bar → table as before
the visit. Note the two things the beta3 variant may not have had: a fresh `Tree` instance each
render inside a fresh `Row`, and `setTabs()` called on every render. Test this shape, and the
same with the Terminal as a plain prop and with `keep()`.

## 2. Tab skips the first Button of every row once the Row has `onKey:`

Three `Row([Text, Button('Pause'), Button('Cancel')])`, each with `onKey: ['p' => ..., 'x' =>
...]`. Without `onKey:` Tab visits P1 C1 P2 C2 P3 C3 P1 … With it, the first round is right and
from the second round on every Pause is skipped: C1 C2 C3 C1 … The Row itself is never a stop
either, so it is unclear when its `onKey:` applies. Required, decided: an element with `onKey:`
that contains focusable components is **not** a stop itself; its keys apply while the focus is
inside it (the way an ancestor's `onKey()` runs before the focused child), and `onFocus:` on it
fires when the focus enters it from outside. An element with `onKey:` and no focusable content
is a stop, as beta3 says. The Tab walk is the same with and without `onKey:`. Tests: the three
rows, two full rounds forward and back, with `onFocus:` counts; `p` pressed while a Button of
row 2 has the focus pauses row 2.

## 3. Small, from use

- A closure program has no way to end itself: `Tui::quit()` (or the `keys:` action `'quit'`) ends
  the running root from any handler or coroutine, as Ctrl-Q does.
- After a dialog closes the focus returns to where it was before the dialog opened, not to the
  first focusable thing on screen.
- `Select` takes an initial value (`current:` or `value:`), like `Checkbox`.
- `Editor` with `complete:` swallows Tab even when the closure returns no candidates; Tab then
  walks the focus as elsewhere.
- Alt-1..n switches tabs when the focus is anywhere inside the `Tabs` content, not only in its
  bar; document the rule in widgets.md.
- `Tui::go()` failure: the message is printed after the terminal is restored, as for the run
  closure (class, message, file:line; the trace only with an environment flag), not a raw fatal
  with a stack trace over the restored screen.
- `println()` takes markup like every other string; `Text::raw()` style opt-out applies.
- `State`: an array property can be changed by index (`$s->p[$i] = 0.5`) and that counts as a
  change; document the rule, or make `$s->p[$i]` work through ArrayAccess on a state array.
- `FakeTerminal::press()` followed by `text()` reads the frame before the key was handled; either
  `press()` returns after the next frame, or the docs say `settle()` first in one line at the top
  of the testing section.
- Under FakeTerminal, an exception thrown in test code inside `fiber\run()` after `Tui::headless()`
  started still hung silently in one program; the beta4 watcher covers it on a Tty, make sure the
  same path covers `Tui::headless()` and test it.
- CHANGELOG under `## 0.0.3-beta4`, each entry stating what a user sees differently.
