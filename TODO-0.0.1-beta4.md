# 0.0.1-beta4

## 1. Focus given before a component is shown holds after it is shown

A component created in a key handler and focused there (the documented dialog pattern) receives
keys at once through its nearest shown ancestor, but once the render shows it, keys go to its
parent instead, while `hasFocus()` still says true. And in the pre-show period, keys reach only the
component's own handler, not the child it focused (a TextInput inside the dialog). Required: one
focus model for both periods. A focus request names a component (and transitively the descendant it
focused); before the component is shown, input is delivered to that focused leaf as if it were on
the path; when the render shows it, the Surface path is set to the same leaf, with nothing for the
application to repeat in `render()`. Test: dialog with a TextInput focused in `mount()`, created and
focused in a key handler; type before the frame and after it; the input holds all of it.

## 2. A component's rendering depends on its rectangle: re-render when it changes

A component whose `render()` reads its own area (a status line showing the size, anything that
lays itself out) shows stale output after the layout resized it, unless the application calls
`requestRender()` from a resize handler of its own. Required: a component is rendered again when
its area changed since its last render, by the library. Document `sized()`, the area, and this rule
together.

## 3. Text components address lines, not laid-out rows

`Log::scrollTo()` takes a row of the wrapped layout, which an application cannot compute without
replicating the component's private layout; a "go to line N" or a "line X of Y" indicator is
therefore impossible. Required: public addressing in lines (`scrollToLine(n)`, `topLine()`,
`visibleLines()` or equivalent), with rows kept internal; `scrollTo(row)` may stay for
row-based use but is documented as such.

## 4. Small

- `child($key)` without a factory returns the existing child of that key, or null; today it throws.
- `FakeTerminal` has one documented way to feed raw bytes as the terminal would send them
  (escape sequences included), next to `type()` for text and `press()` for keys.
- `Component::dispose()` must not inspect the call stack (`debug_backtrace`) to find out whether
  it runs inside one of its own coroutines: that is the same kind of going-beneath-the-API as
  `Fiber::getCurrent()` was. Until `fiber-interop/api` offers a current-task handle (reported;
  keep the finding in DEVNOTES), `dispose()` signals the component's coroutines and does not wait
  for them; `unmount()` runs when the last of them has ended; a parent's disposal awaits the
  child's end with the timeout; the structured `fiber\run()` guarantees completion. Document what
  that means for code that disposes its own component from one of its coroutines.
- Keep the acceptance tests green (nested split-shells, vim/htop/less at pane size, quit with
  foreground programs, Claude Code's first screens).

Everything above goes into CHANGELOG.md under `## 0.0.1-beta4`, each entry stating what a user
sees differently, so someone who hit the problem recognises it.
