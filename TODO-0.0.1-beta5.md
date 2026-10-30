# 0.0.1-beta5

## 1. Focus follows the surface, whatever `render()` rebuilds

The beta4 focus model still loses the focus in a common shape: `render()` builds a *new* layout
object on every call (a new `Stack` holding a new `Split` with a new plain `Surface`, plus an
existing child component's surface added directly with `->add($child->surface, w, h, title:)`),
the child was created and focused in a key handler, and `requestRender()` was called. Before the
frame the child gets the keys; after the frame that shows it, keys go to the parent component
while `hasFocus()` on the child says true. Required: the focus request made on a surface survives
*any* rebuild of the layouts above it (new layout objects, new sibling surfaces, a viewport with a
border/title around it), and `hasFocus()` and actual key delivery can never disagree: they read the
same path. Add this exact shape as a test (fresh layout objects per render; the dialog's surface
added directly, with `title:`; keys before and after the frame), and a property-style test that
rebuilds random layouts around a focused leaf and types after each.

## 2. `dispose()` waits again, through `fiber\current()`

`fiber-interop/api` 0.3.1 adds `fiber\current()` (the handle `go()` returned for the running
task; `await(current())` throws) and makes `fiber\Deferred` public (`resolve()`, `reject()`,
`isSettled()`). Require `fiber-interop/api ^0.3.1`. If a `fiber-interop/phasync` newer than 0.4.0
is on Packagist, require it too (it adds the adapter's `current()` for the stretch before a
coroutine's first suspension, and lets phasync 2.0.7 install); otherwise keep 0.4.0 and note the
limitation in DEVNOTES. Then `dispose()` waits for the component's coroutines *except the current
one* (`$handle === fiber\current()`), with the timeout, so `unmount()` has run when `dispose()`
returns in every case but the self-dispose, which is documented. Use `resolve()`/`reject()`
instead of `settle()`. Keep the beta4 behaviour as the fallback only where `current()` is null.

## 3. Small, from use

- `Log::setLines()` keeps the scroll place by line *position* when the line's text changed
  (re-styling the same lines must not jump to the end).
- `child($key)` without a factory returns null for a child that has been disposed, not the dead
  object until the next render.
- A component whose rendering depends on another component's state it reads in `render()` (a pane
  title showing the log's top line) must have a way to be told: `Log` (and the scrollable
  built-ins) emit a `scroll` event; document the pattern "listen and `requestRender()`".
- A component created with `child()` can be given to a `Box` (or any container built-in) without
  "already started": containers accept an existing child.
- `FakeTerminal::type()` is for text; make its docblock say that arrows and function keys go
  through `press()`, and sequences through `feed()`, so nobody tries.
- Keep the acceptance tests green.

Everything above goes into CHANGELOG.md under `## 0.0.1-beta5`, each entry stating what a user
sees differently, so someone who hit the problem recognises it.
