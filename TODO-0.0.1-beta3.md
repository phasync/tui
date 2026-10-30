# 0.0.1-beta3

Three defects in how a program lives in a pane, each for the general case, plus small things.

## 1. A program starts at its pane's size

A `Terminal` component starts its program when it mounts, in a pty of a default size, and the pane's
real size arrives moments later as a resize. Full-screen programs read their size once at start
(and vim installs its SIGWINCH handler late), so a program in any pane larger than the default
stays at the default size for good. Required: a program starts in a pty that already has its
pane's size. A component that needs its size to start its work starts it when it has been sized
(the first real layout), not at mount; document the rule for component authors (mount = props and
state; sized = things that depend on the rectangle), and make `Terminal` follow it. A resize that
arrives before the program has set up must still take effect afterwards (a second SIGWINCH once
the program is reading, or the size re-asserted when its first output arrives).

## 2. Hanging up a program hangs up the whole process group

A program given as a string runs through a shell wrapper, and hang-up signals only the wrapper.
If the interactive shell behind it runs a foreground program (anything: sleep, vim, a nested
multiplexer), the shell and that program survive as orphans holding the pty open, the output pump
never reaches EOF, and `fiber\run()` never returns. Required: ending a `Pty` ends every process it
started: run the program as a session leader on the pty (the pty's controlling process, so that
closing the master delivers SIGHUP to the whole foreground group, as a real terminal closing does),
signal the process group rather than one pid, and after the grace period kill the group. The string
and array forms of a command must behave the same; a string command needs no wrapper if the shell
is what runs it (`exec`), and the test for "quit with a foreground program running" must pass for
both forms, nested one level.

## 3. Focus survives a re-render that swaps layouts

When a component's `render()` returns a different layout than before (a `Stack` with a dialog, then
the plain layout again), focus requests made during that render are lost when the layout contains
a nested layout: the focused surface is re-hosted or re-created, and the focus path is left
pointing nowhere; the application is dead to the keyboard afterwards. Required: focus is attached
to the Surface (or the component) and follows it through any re-arrangement; a `focus()` call during
`render()` wins over whatever the re-arrangement does; removing the focused surface moves the focus
to a sensible neighbour (the previously focused one if it is still there, else the first focusable).
A test opens and closes a modal over a nested layout and types afterwards.

## 4. Input and renders

Keys that arrive right after a key that changed the focus, before the batched render has applied
it, go to the old focus. Focus changes must take effect immediately for input routing, with only
the drawing deferred to the frame.

## 5. Small

- A `Stack` item that is a dialog typically wants a border and title; make it a documented one-liner
  on the `Stack::add()` call (or the Viewport it returns), not a detour through `host()`.
- `Log::setLines()` is the way to refilter; make sure the docs say so where filtering is
  discussed, and that it keeps the scroll position sensibly (same line if present, else the end).
- Keep the acceptance tests green: nested split-shells and the Claude Code run. Verify that vim,
  htop and less start at the right size inside a pane and exit cleanly with the pane.

Everything above goes into CHANGELOG.md under `## 0.0.1-beta3`, each entry stating what a user
sees differently, so someone who hit the problem recognises it.
