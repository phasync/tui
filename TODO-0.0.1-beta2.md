# 0.0.1-beta2

What this version must change or add, each item for the general case. Items are requirements, not
designs: where an item shows a design flaw, fix the design, and keep every layer usable alone.

## 1. Text components keep their text and lay it out at the current width

A component whose content is text (Log, and anything built like it) must own its content as text
and lay it out for the width it has *now*. Today the content is laid out once, at whatever width the
Surface happened to have, and a resize of the Surface truncates columns: text is lost for good when
the terminal narrows, lines appended before the first real size arrive wrapped for the wrong width
and show cropped, and the only way an application can survive a resize is to keep its own copy and
re-append everything. For a *terminal* pane that is right (a terminal does not reflow); for a text
component it is wrong. Required:
- Log (and the pattern for text components in docs) keeps its lines; on resize, and on the first
  size it ever gets, it lays them out again at that width. `lines()` returns the lines as given.
- Wrapping is by display width and grapheme (wide characters and emoji count two cells), with
  word wrapping as an option; a `Text::wrap()` helper does this for applications too.
- The first paint is right: a component attached after `start()` must not render for a guessed
  width; render when the size is known, or re-render when it arrives.

## 2. Ending must end everything, and a failure must surface

- When the root component's start or render throws, the exception reaches the caller and
  `fiber\run()` returns: children that were already started (a Terminal with a running program)
  are disposed and their programs hung up, so nothing keeps the run alive. Today the exception is
  swallowed and the process hangs silently, which turns every typo in a root `render()` into a
  hang. The same for a component inside an error boundary: its children end with it.
- Detaching the terminal device (quitting) disposes the component tree and ends every Pty it
  owns (hang up, then kill after a grace period), so `fiber\run()` returns without the application
  having to kill its programs by hand. A Pty an application started itself is the application's.
- Document plainly what keeps `run()` alive and what ends it.

## 3. Focus follows the surface tree, not the component tree

`Component::focus()` and `hasFocus()` work only when every host between the root and the component
is a component. With a plain Surface in between (the documented way to nest layouts), `focus()`
does nothing and `hasFocus()` stays false, silently. Required:
- Focus and the focus path are a property of the Surface tree: every Surface knows its host
  (`parent()`), its viewport, and its absolute rectangle on the root; `focus()` focuses the way
  down from the root through whatever hosts there are; `hasFocus()` reads the real path.
- Directional focus (`focusLeft/Right/Up/Down`, or one method with a direction) from viewport
  geometry, next to `focusNext/Previous`: every multiplexer-like program needs it.
- Nesting layouts must not need a hand-held intermediate Surface: a Split or Stack may hold
  another layout directly, owning the Surface in between.

## 4. Programs and components announce when they end

A Terminal component and a Pty expose their program's end as an event and an awaitable (exit
status included), so a host can close the pane, restart the program or show the status. Today the
only way is a coroutine that `wait()`s.

## 5. Built-ins: what their first users reach for at once

- TextInput: a submit event with the text, and optional history (Up/Down), without the application
  having to intercept keys on its surface.
- Log: word wrap option (item 1), and a way to re-style or clear and re-fill efficiently.
- A simple list/select component (rows, selection, scrolling, selection event): the member list,
  the file tree, the process list and the pick list are all this.

## 6. Emulator

- Mouse motion reporting without a button held (mode 1003), which the spec lists and beta1 skips.
- Keep the acceptance tests (nested split-shells, Claude Code) green; extend the Claude Code test
  as far as it can go without writing to the user's configuration.

## 7. Documentation

- The README's first program must be right for a resized terminal (item 1) and must quit cleanly
  (item 2); every documented nesting pattern must work with focus (item 3).
- A short "how a program ends" section; a "text components" section describing the model/layout
  split; the directional focus and exit events where they belong.

Everything above goes into CHANGELOG.md under `## 0.0.1-beta2`, each entry stating what a user
sees differently, so someone who hit the problem recognises it.
