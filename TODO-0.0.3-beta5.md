# 0.0.3-beta5: never hang silently on the tagged runtime, a `quit` hang, and the cost of a key

Everything from 0.0.3-beta4 keeps working. Items 1 and 2 are defects with the exact shapes from
use; 3 is performance; 4 is small. If this round finds nothing new, 0.0.3 is done.

## 1. On the tagged fiber-interop versions the program hangs silently; it must say so instead

The exact shape, installed from Packagist (fiber-interop/api 0.3.1, fiber-interop/phasync 0.4.1,
the latest tags; the library's own `require-dev` pins the dev branches, so the suite never sees
this):

```php
fiber\run(function () {
    $t = Tui::headless(fn() => new Text('hi'), 60, 20);
    throw new LogicException('test code failed');
});
```

hangs forever with no output. With the dev branches it ends with the exception at once, as the
CHANGELOG says. Every user who installs the library today gets the tagged versions, so this is
what they get. Required: the library never hangs silently because of the runtime underneath.
When `Runtime::canWatch()` is false, (a) `Tui::run()`, `Tui::inline()` and `Tui::headless()`
still see the failure of their own closure and of `Tui::go()` coroutines (they own them), (b) a
plain `fiber\run()` closure that started a program and then throws cannot be watched, so the
library prints one line to stderr at start-up naming the installed and the needed versions
(`fiber-interop/api >= 0.3.2, fiber-interop/phasync >= 0.4.2`, the first tags carrying the fix)
and docs/terminal.md and the README say the same in their testing sections. Tests run the
shape above under a FakeTerminal with `canWatch()` forced false and assert the warning and that
`Tui::headless()`-owned failures still end the program. Also check whether `composer.json` can
express it (`conflict` with the two tagged versions once the fixed ones exist; until then, the
warning).

## 2. `onKey:` with the `quit` action on an element hangs the program

Cards as `Text`s with `onKey: ['q' => ['quit', 'quit']]` (and the string form `['q' => 'quit']`):
pressing `q` while a card has the focus never returns, no CPU spin, the program hangs. The same
binding in `Tui::run(keys:)` works, and `onKey: ['q' => fn() => Tui::quit()]` works. Required:
the `quit` action quits from an element binding exactly as from `keys:`; find why the element
path waits (a dispatcher waiting on itself after the root is disposed is the likely shape) and
make every action name behave the same from both places. Test both forms on an element and on
the root, headless and in a forked pty.

## 3. A key costs O(rows)

Fifty `Row([Text, ProgressBar, Button, Button])` with `onKey:` and `onFocus:`, all paused (no
state changes): Tab to a settled screen is 90 ms median; 135-170 ms while 50 coroutines update
bars at 10 Hz. A plain list of 50 Rows with two Buttons is 33 ms per Tab, linear at 0.6 ms per
row (200 rows: 82 ms). Beta5 (0.0.2) promised key-to-screen under 20 ms for 50 rows, and the
monitor had it at 12-14 ms with class rows. The closure/element row is six times dearer: a focus
move re-measures or re-paints every row. Required: moving the focus between two rows repaints
those two rows and nothing else, and a key on a 50-row closure program is under 20 ms median
with the bars moving. Tests: count rows measured and painted on a Tab (two), and the timing with
headroom on the shape above, added to tools/monitor-bench.php as a mode.

## 4. Small, from use

- Quitting does not stop a closure program's coroutines, so `fiber\run()` waits (one program hung
  100 s after Ctrl-Q). `Tui::go()` coroutines are stopped (cancelled cooperatively: their next
  `fiber\sleep()`/wait throws a `Quit` exception they may catch) when the program ends; plain
  `fiber\go()` coroutines cannot be, and the docs' closure-program section says so in one line
  with the `$running` flag pattern.
- A `Column` of more rows than fit clips them and the clipped Buttons leave the Tab walk; the
  docs say when a `ScrollView` is needed, and a focusable component scrolled out of view inside a
  `ScrollView` is scrolled into view when it takes the focus (it is for Dialogs since beta4).
- `hasFocus()` on a throwaway instance made in `render()` reads false while the running one
  matched by place has the focus; `Component::of()`/the running instance is the documented way,
  and `hasFocus()` on an instance that was handed over says so (returns the running one's answer,
  or throws with a message).
- The `Help` line shows the focused component's hints also when it is a fresh instance matched by
  place (it showed the tab bar's instead).
- CHANGELOG under `## 0.0.3-beta5`, each entry stating what a user sees differently.
