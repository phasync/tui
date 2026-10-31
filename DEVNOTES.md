# DEVNOTES

Working notes for whoever continues this library (design decisions, what is left). Users read
README.md and docs/.

## Status (0.0.2-beta5; TODO-0.0.2-beta5.md) — in progress

- [ ] 1. Render cost: done so far (commits 624ba14, a9a05e6): Stylesheet memo, Tree item and
      layout reuse, ElementLayout keeps placed windows, measure by the built items, focus
      settled only when it may move, hasFocus cache, one frame coroutine, Host viewports by
      surface and only stale windows composed, grammar: one-terminal CSI, no item probes at
      the end; showsAgain() (same tree = no layout), pen written only when needed, shared
      clock for Signal timeouts, mouse-wanting surfaces registry, one-cell runs stored at once.
      Numbers (this machine, FakeTerminal 120x60): flat 50x Row[Text,Text] 6.5 ms -> 1.4 ms per
      frame; monitor of 50 row components at 10 Hz: 91% -> 55% of a core when every row changes
      every tick, 43% -> 17% when one row in 20 changes; key latency 55 ms -> < 5 ms.
      Left: tests (timing, work count), docs/surface.md "Performance".
- [ ] 2. Tabs focus into element content
- [ ] 3. Dialog taller than the terminal
- [ ] 4. small items

## Status (0.0.2-beta4; TODO-0.0.2-beta4.md)

All items of TODO-0.0.2-beta4.md done, in CHANGELOG.md; tests green with and without
ext-phasync (on fiber-interop/api and fiber-interop/phasync main, see the fiber\ notes).

- [x] 1. A failing starter ends the program: Internal\Runtime::watch() from Component::start()
      (non-device targets) and TerminalDevice::attach(); Tui::run()/inline()/headless() with
      quitOn('ctrl+q'); tests/Component/FailureTest.php (FakeTerminal and a forked pty process,
      tests/fixtures/programs/crash.php: stderr message, modes reset, non-zero exit within 1s,
      for the run closure, attach(), a run() coroutine, a go() coroutine and Tui::run())
- [x] 2. Overlays in a dialog across resizes: placement passes in renderFrame(), immediate
      arrangeOverlays(), the side kept per [anchor, root], Placement::for(keep:) for the
      dialog's buttons, Select's list width following the field, ScrollView onResize;
      tests/Widgets/DialogResizeTest.php (sizes, too small, nested instance, storm)
- [x] 3. widget/Tree and widget/Tabs merged (5e8b7e0, b6d1c3f) and aligned in 3f3bba8; README
      tour entries (Tabs with a Terminal, Tree of 100k), run in tests/Examples/ReadmeTest.php
- [x] 4. wantsPointer() and part :hover; keep() and public final stop(); 'first' bindings and
      the key order paragraph; parent/child reactivity rule; parts in the row state;
      awaitables convention; focusNext()/focusPrevious(); Column of row components (default
      measure() by the shown tree); tests/Elements/ContractTest.php
- [x] 5. Dialog accept/submit guard (Form::hold()), Form::values() after close, popup side
      stickiness, ProgressBar label + percent + placeholders + no overdraw, freeze();
      tests/Widgets/SmallFixesTest.php

## Design decisions (0.0.2-beta4)

- **The watcher is gated by version, not probed.** Awaiting `fiber\current()` from a task
  started inside it re-runs the run() closure on api 0.3.1 / adapter 0.4.1 (the beta2 finding),
  so `Internal\Runtime::canWatch()` reads InstalledVersions and refuses numeric versions at or
  below those; dev branches pass. A probe would have to run the broken path to find out.
  `Runtime::$watching` overrides it for tests. Tui::run() owns the run closure and so needs no
  watcher at all; it is the documented fallback. The watcher rethrows after disposing, so
  fiber\run() reports the original exception, not a wrapped one.
- **Tui is only the runner** (SPEC 9.1's runner part); the rest of SPEC 9 is still 0.0.3.
  The quit key is a property of the root (quitOn()), handled after every binding and onKey
  had their turn, so a component binding Ctrl-Q wins.
- **Default measure() measures the shown tree dry.** A component without measure() is measured
  by Tree::measure() of the element it rendered last, with `place: false`: nothing is mounted
  or placed, unmounted components measure as empty items. The first version placed components
  from a stale tree and disposed a Markdown child of a hidden tab.
- **Overlay placement in passes.** renderFrame() renders the dirty components, then places the
  overlays in opening order; an overlay whose rect changed is arranged at once (so an overlay
  opened from it anchors to the new position in the same pass) and, when that made something
  dirty, the frame renders again (at most 16 passes). The side an overlay opened on is kept
  while [anchor, root] are unchanged; a resize chooses again.
- **'first' bindings** are the 4th element of a normalized binding. On the way down, a
  component that is not where the key ends runs its first bindings before onKey(); after the
  focused component, the parents' walk skips first bindings of parents the key already passed.
  One flag rather than a second bindings() method, so Help and bind() need nothing new.
- **keep()** records keys in `$kept`; renderNow() does not dispose kept children it did not
  see. stop() unsets the key in the parent. Tabs uses it for component tabs and stops the
  children of tabs setTabs() dropped.
- **Form::hold()** is set by Dialog::choose() around accept(); a held submit() validates
  nothing new and returns whether the last one was valid. values() keeps the last submitted
  values for after unmount.
- **freeze()** inline pushes the row's cells (as rowData) into the printed queue, exactly
  what a scrolled-off row does; full screen it holds the row's text with SGR for detach, as
  println() does.

## Left for later versions (0.0.2-beta4)

- Tag-based requirement: once fiber-interop/api > 0.3.1 and fiber-interop/phasync > 0.4.1 are
  tagged, require them (`^0.3.2` / `^0.4.2`, whatever they are), drop the dev require-dev and
  the gate's version table can stay as is.
- ReadmeTest's first program (a real shell in a pty) failed twice in about ten full runs
  during beta4, both times inside its run closure (once the shell's echo took the whole 5 s);
  it passed on every rerun, and 8 isolated runs under 4 busy CPU cores did not reproduce it.
  Cause not found; suspect the shell's startup under the suite's other ptys.
- SPEC 9 apart from the runner (utility classes, markup, themes, the dashboard test): 0.0.3.
- Table and ScrollView still scroll with their own code.

## Status (0.0.2-beta3; TODO-0.0.2-beta3.md)

All items of TODO-0.0.2-beta3.md done, in CHANGELOG.md; tests green with and without
ext-phasync. SPEC section 9 (commit 5110407, "phase 3, from 0.0.3") arrived during this
version; it is for 0.0.3 and not started.

- [x] 1. Defects, tests in tests/Widgets/DefectsTest.php and tests/Elements/SizingTest.php:
      overlays measured every frame (the Editor pinned its completion's height; now
      max-height), sized Dialog (mount() measures the tree), container 'auto' from children
      (Tree makes containers 'auto'), Form in Dialog (Form::enter(), `submit` event,
      Dialog::choose() submits its forms)
- [x] 2. Inline mode: TerminalDevice::drawInline(), println(), Tty::inline(),
      FakeTerminal::inline()/frozen()/region(); tests/Terminal/InlineTest.php;
      docs/terminal.md; examples/inline-progress.php
- [x] 3. widget/Markdown merged (ff695b1) and moved onto Scrollable and composed parts; OSC 8
      in the cells; supportsHyperlinks(); Scrollable used by Log and Markdown; docs
- [x] 4. dialog(key:); overlays on the root documented; CHANGELOG; README tour (Markdown pane,
      inline progress; both run in tests/Examples/ReadmeTest.php)

## Design decisions (0.0.2-beta3)

- **Inline model.** The root is cols x (rows - 1): the row under the region is where the
  cursor parks, so a region as high as the terminal never scrolls the terminal by itself (the
  first try sized the root rows x cols and a height shrink duplicated a row). The region is the
  rows down to the last non-blank one, or the cursor's row when the cursor shows. Rows that
  leave the root's top (Surface::linesScrolled() deltas) and println() lines are written at the
  region's top with LF pushing the frame down, then the region is redrawn from there. Only
  CUU/CUD/CR/CHA/LF: no CUP, no DECSTBM, so the shell's output above is never touched. A width
  change redraws the region; a height change shifts it by what the terminal lost.
- **Inline restore after a crash** can not put the cursor back under the region (the device
  does not know where the terminal's cursor is); it restores modes and moves to column 0.
- **println() full screen** holds the lines until detach, then writes them on the normal
  screen; detached it writes at once.
- **Hyperlinks** are part of Style (interned with link and id) so the cell model needs no
  second array; SGR 0 does not end a link (as in terminals). DECSC/DECRC save it with the pen.
  A host's wrap() closes a link the producer left open at its window's edge. The device
  writes links only when supportsHyperlinks(); Tty decides from the environment (a table in
  Tty::hyperlinksFromEnvironment()) and XTVERSION, false inside tmux/screen even when the
  outer terminal could (tmux drops them unless configured).
- **Scrollable** keeps [top, following] in the trait by default; Log overrides
  scrollState()/setScrollState() to keep them in its Viewport (absolute = top() +
  scrollbackSize), so the emulator's own scrollback stays the storage. Moves return false at
  the edges so the keys go on (to the next field, to the parent).
- **Composed parts**: Stylesheet::compute() with a list folds resolve() over the parts, each
  inheriting from the one before, so "attributes add up, innermost colour wins" is the
  inheritance rule and nothing new. A part may turn an attribute off (`bold: false`).
- **Markdown review**: own scroll code replaced by Scrollable (j/k/Space dropped as defaults,
  bindable); combine() replaced by style([...]); `\e[0m` resets replaced by the Markdown's own
  style so a themed background fills; links through Ansi::link() (control characters
  stripped); key/id/class/style taken by the constructor like the other widgets.
- **A root's dispose renders pending frames first** (Component::dispose()), so the last state
  a program set before quitting is the frame an inline device leaves. Only from dispose(), not
  from the failure path, so an error in that render reaches the caller.
- **Form in a Dialog**: the Dialog subscribes to each Form's `submit` (found by walking its
  children at mount and every render) and answers its first button; its buttons submit the
  forms first unless the button is the cancel answer. Form's Enter is bound to enter(), which
  returns nothing: a failed validation must not let Enter go on to the Dialog.

## Left for later versions (0.0.2-beta3)

- SPEC 9 (Tui::run, utility classes, markup, themes, the dashboard test): 0.0.3.
- Table and ScrollView still scroll with their own code; they could use Scrollable.
- `echo` from coroutines is not captured into the inline scrollback (println() is the way).
- Markdown: reference links, footnotes, task boxes, raw HTML (shown as text).

## Status (0.0.2-beta2; TODO-0.0.2-beta2.md, SPEC 8.5)

All items of TODO-0.0.2-beta2.md done, in CHANGELOG.md; tests green with and without
ext-phasync (503 tests).

- [x] 1. Quitting with dialogs open (tests/Component/QuitWithDialogsTest.php: the exact shapes,
      a forked child so a hang fails, a randomized round of opens/typing/quit moments); the
      key rule under a modal (opener suspended except `always` bindings; parents' work)
- [x] 2. Overlays: src/Overlay.php, src/Placement.php (tests/Layout/PlacementTest.php),
      Component::overlay()/dialog()/inOverlay(), Internal/OverlayLayout, Internal/Floating;
      tests/Elements/OverlayTest.php; Dialog/Confirm as overlays; Select dropdown; Editor
      completion
- [x] 3. Size::distribute(): 'auto' = what it needs, capped at a 1fr share (tests/Elements/SizingTest.php)
- [x] 4. canFocus(); drags unclamped to the pressed window (tests/Component/FocusSelfTest.php)
- [x] 5. Editor: maxLines = rows shown, no joining; grows; history; complete; not final; insert()
- [x] 6. Dialog::accept(), Tab content-then-buttons (Buttons), content-sized, ScrollView;
      Select filter mode
- [x] 7. Button, Checkbox (toggle), ProgressBar, Spinner, Form + Field; README + examples/form.php
- [x] 8. setStyleState()/hasStyleState(); pen = component style before render(); Help one row, `show:`
- [x] 9. docs on `new` children not re-rendering; FakeTerminal::settle()/run(); children()
- [x] 10. docs, CHANGELOG

## Left for later versions (0.0.2)

- (done in beta3) SPEC 4.1 **inline mode** (`Tty::inline()`, `FakeTerminal::inline()`, the root's scrollback as
  the terminal's) was added to the spec while this version was being built (commit 3281ee8)
  and is not in TODO-0.0.2-beta2; not started. Nothing in this version is in its way: the
  device layer is unchanged, so it is a second painting mode of TerminalDevice (relative
  cursor motion, rows scrolled off the root's top written once above the region).
- Widgets of SPEC 8.6 not in this TODO: `Radio`, `Tabs`, `Tree`, draggable `Split`
  separators, `Terminal` `onOutput`, `Markdown`.
- Overlays are hosted on the root component, not on "the nearest `Layers` above" (SPEC 8.5):
  the root always has one and is the boundary; a nested instance's root is its pane. A
  `Layers` element in the middle of a tree does not host overlays of its own.
- `ScrollView` scrolls by whole rows of its content surface; a focused element taller than
  the view shows its top.
- `:hover` only for components; Stylesheet has no combinators (`Dialog Button`).
- Text elements do not scroll by themselves (put them in a `ScrollView`).
- Editor: no double-click word selection; a drag above or below it does not scroll it.

## Design decisions (0.0.2-beta2)

- **Overlays live on the root component.** `overlay()` makes the element a child of the opener
  (key "\0o:N"; a plain element is wrapped in Internal\Floating, which renders it in a
  ScrollView) and registers it with the root (`$floating`, in open order). The root's layout
  is wrapped in an OverlayLayout (inner layout, or none for a self-drawing root, then each
  overlay at its rect, raised in order). Placement runs at the end of every renderFrame for
  every root with overlays (`$floatingRoots`), i.e. after the layout pass and before the
  device paints; it computes the anchor from the opener's `area()` relative to the root's,
  the desired size from `width`/`height` attributes or `measureFor()` (so a content whose
  measure changes makes the opener render, and the next pass re-decides), and arranges only
  when a rect changed. OverlayLayout clamps the stored rect to the host, so a resize that
  arranges before the pass never puts anything outside. The same pass cancels overlays whose
  opener is gone, not shown (`isShownIn()` or empty area), or (non-modal) lost the focus after
  having had it (`wasFocused`).
- **Ownership = lifetime.** The overlay's content is the opener's child, so disposing the
  opener stops it; Component::stop() of a content cancels its overlay first (closes with the
  cancel value, unhosts at once, restores the focus if it was in the overlay or it was
  modal). That is what makes quitting with dialogs open end everything: the Dialog's unmount
  resolves its result with `$cancel`, the awaiting coroutine goes on, the opener's end follows.
- **Keys under a modal.** `suspended()` = an open modal overlay of this component. key(): the
  key end's own bindings/onKey unless suspended; then up the parent chain: each parent's
  bindings (only `always` ones when that parent is suspended), and Escape cancels a non-modal
  overlay when the walk passes its content. Overlay contents' parent is the opener, so a
  dialog's keys reach the opener's parents (the application) without the opener being on the
  surface focus path. The root's capture onKey is skipped while the root is suspended.
- **Mouse.** The root's mouse listener (`overlayMouse()`, after the hover pointer) takes
  everything outside the topmost modal overlay, and cancels non-modal ones on a press outside
  them and their opener. Captured drags: Host passes unclamped coordinates to the captured
  window; Surface::dispatch() clamps only when encoding for a program.
- **Focus.** `focus()` = `takeFocus()` for components that `canFocus()` (default: bindings in
  force, or an onKey/onPaste override, by reflection, cached per class), else `focus()` on
  the surface (keys go on to the latest request below). Dialog and Form override it (false).
  `hasFocus()` is also true while one of the component's overlays has the focus (a dropdown
  field looks focused while its list has the keys).
- **Sizes.** Size::distribute() treats ['auto', n] as weight 1 capped at n (water-filling:
  settle every auto that fits its current share, repeat, then split the rest by weight).
  Before, autos were fixed cells and swallowed the room. Table::measure() gives its natural
  width (fixed columns, the widest cell/title of the others).
- **Dialog** renders Box(Column[title, ScrollView(content), Row(Buttons)]); measure() is the
  tree's natural size via `measureElement()` (a message laid out at 60 columns). mount()
  measures once so the content's components exist and the first field can be focused before
  the first render. Buttons are children "button$i".
- **ScrollView** is a component: its render returns a ScrollLayout that hosts an inner surface
  (the view's width x the content's natural height, `setFit(false)`) and sets on it the layout
  `layoutElement()` built; `follow()` keeps the top and scrolls to the focused leaf when that
  changed. onKey (capture) marks it dirty so a focus move inside is followed.
- **Form** finds its fields with `children()` recursively (Field + `id`); validation sets the
  `invalid` style state; a Button without onPress walks up to the nearest Form.
- **Pen before render.** renderNow() writes `ESC[0m` + the component's style to its surface
  before render(); SGR-only writes change no cells.
- **Child keys** made by the Tree: "\0k:<key>" for `key:` elements, "\0p:<path>" for the rest;
  `children()` shows the former as `<key>`.
- **FakeTerminal::run()** instead of a watcher in attach(): see the fiber\ finding below.
  `settle()` loops `sleep(0)` + refresh until the output length and the root version were
  unchanged three times (1 s at most).

## Status (0.0.2-beta1; TODO-0.0.2-beta1.md, SPEC section 8)

All items of TODO-0.0.2-beta1.md done, in CHANGELOG.md; tests green with and without
ext-phasync (444 tests).

- [x] 1. Element tree (src/Element.php, src/Element/*, src/Internal/{Tree,ElementLayout,LayoutItem}.php);
      tests/Elements/ElementTreeTest.php (rebuilt tree keeps focus+scroll, keys, mixed with
      imperative drawing, random rebuild property test, device replay)
- [x] 2. Reactive state (src/State.php; Component::renderFrame()); tests/Elements/StateTest.php
- [x] 3. Stylesheet (src/Stylesheet.php, src/ComputedStyle.php), :hover; StyleTest.php
- [x] 4. Bindings + Help; BindingsTest.php
- [x] 5. Widgets Editor, Table, Select (extends Table), Dialog/Confirm; tests/Widgets/*
- [x] 6. Ansi::clearHome(), version check (Internal/Runtime, at start() and attach()),
      docs/components.md, docs/widgets.md, README tour (tests/Examples/ReadmeTest.php),
      examples/form.php (ExamplesTest), CHANGELOG

## Left for later versions (0.0.2)

- Widgets 5-8 of SPEC 8.5 (Form, Checkbox/Radio/Button, Tabs, ProgressBar, Spinner, Tree,
  ScrollView, draggable separators, Terminal onOutput, Markdown).
- `:hover` only for components (elements are values; a Text has no state of its own).
- Stylesheet has no combinators (descendant selectors); inheritance covers the common case.
- Text elements do not scroll when longer than their box (ScrollView is a later widget).
- Editor: no double-click word selection; a hanging space at a wrap point puts the cursor one
  cell past the text at the row's end (clamped to the last column).
- Table scrolls sideways by whole columns, not by cells.
- Dialog with element content needs a size (`with(width:, height:)`) or gets 60x12 at most.

## Design decisions (0.0.2-beta1)

- **Component extends Element.** Every component is an element; attributes (key, id, class,
  style, width, height, disabled) live in one private array of Element, set with `with()` (and
  by element constructors' named arguments). Public methods added to every component:
  `with()`, `attribute()`, `measure()`, `types()`, `style()`, `stylesheet()`,
  `setStylesheet()`, `bind()`, `bindingsInForce()`; protected `bindings()`, `call()`,
  `dialog()`, `propsChanged()`; property `protected State $state`. None clashed with the
  built-ins (checked).
- **No `Component::set()`**: StatusBar has a public `set(string, string)` with a signature a
  variadic `set(mixed ...)` in the base would break. `State::set(...)` instead
  (`$this->state->set(a: 1)`), one way to do it.
- **Element tree -> surfaces.** Each container element (Column/Row/Layers, not the root one) and
  each Box/Text/Help gets a Surface kept by path (positions + class names, or `key`) in
  Internal\Tree; the root container arranges the component surface itself. Each container is
  an ElementLayout (Layout) that sizes ('auto' measures: text by wrapped lines, components by
  `measure()`, null = '1fr'), hosts at the final geometry (hosting at 0,0 first and moving
  blanked the host's own drawing), sets the nested layouts and paints text only when its
  signature (size, style, content) changed. Component elements: started ones are placed as
  they are; a `new X()` element is the child "\0<path or key>"; a later fresh instance of the
  same class there gives its props (public non-readonly properties declared below Component)
  and attributes to the kept one (`propsChanged()`), else replaces it.
- **Focusable surfaces.** Text/Help surfaces and containers with no component below refuse the
  focus (`Surface::setFocusable(false)`): rankFocus treats a never-focused, unfocusable window
  as rank -1; focusNext/focusToward skip them. Without this the first Text of a Column took the
  keys.
- **Reactivity.** requestRender() puts the component in a static dirty set and spawns one
  go(sleep(0)); the first such task to run renders the whole set, shallowest first, each at
  most once (asked again during the frame -> next frame). Triggers: State writes (State moves
  declared props into an array at watchFor() so __set sees every write), handlers returning
  true, bound keys, `sleep()` (before waiting) and a go() task ending after it had suspended
  (flag set after go() returns: a task that finished synchronously renders nothing), emit()
  and call() (the closure's bound $this, by ReflectionFunction::getClosureThis(), renders).
  Writes during the component's own render are ignored (no loops).
- **Key order with bindings.** Capture as before for onKey() (root first) at components that
  are not the key end; at the key end (no component further on the key path): own bindings,
  own onKey(), then parents' bindings, nearest first. So a TextInput types 'q' that the root
  binds, and the root's ctrl+q works while a Terminal is focused (it declines all keys).
  Bound methods run synchronously (typing order), returning false = not taken.
- **Styles.** Stylesheet selectors without combinators (inheritance of colours/attributes
  covers it); parts `Type::part:state` for what widgets draw. Box focus border: Tree sets the
  same style as normal and focus style and re-renders on Focus events of its surfaces, so a
  Box in an unfocused pane is not drawn focused (the compositor's isFocused() is per host).
- **focus() inside render().** With reactive renders, a render that calls `$input->focus()`
  (README's first program) ran again after the key that moved the focus (Alt-Right) and took
  it back: a full-suite hang (the README test failing inside run()) found it. The old test
  LifecycleTest "focuses ... across renders" pins that an explicit requestRender() re-applies
  the render's focus. Rule now: Component records the focus() calls made during its render
  (static `$inRender`); a later render skips those it asked before, unless that render was
  asked for with requestRender() (`$asked`). All library-made renders go through
  `markDirty()` (@internal, final public: State and Tree call it); `requestRender()` = asked +
  markDirty().
- **Help** lists only bindings with a description (`[method, description]`): an Editor binds
  ~50 keys, a footer of all of them is noise.
- **Dialogs** (`Component::dialog()`): the dialog is a child "\0dialogN"; the render wraps the
  tree in `Layers([tree, ...dialogs])` (a Layout in a Stack; a null render hosts the dialog
  surface directly), the border comes from the dialog's style; the close event (a listener of
  the opener: renders it) unhosts at once and refocuses the surface focused before. Modality:
  the opener's own onKey/bindings/paste are skipped while a dialog is open, its mouse listener
  takes events outside the dialog. Bound methods run synchronously, so awaiting the answer is
  done in a go() (documented). A dialog disposed unanswered resolves with `$cancel` in
  unmount() (else the awaiting coroutine hung: phasync reported a deadlock).
- **measure() changes**: Tree measures components through `measureFor()`, which remembers the
  room and answer; after the component's own render, a different answer makes the parent
  render (an Editor with maxLines that grew a line).
- **:hover** only for components: any component's mouse listener records the pointer (root,
  x, y); components whose computed style considered a :hover rule are kept in a WeakMap and
  render again when their hover state flips. Such a component writes ?1003h to its surface
  once so the device asks for motion reports (it then takes its mouse events itself).

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

- **Finding (beta2): awaiting a handle from `fiber\current()` misbehaves under
  fiber-interop/phasync 0.4.1.** Item 9 of TODO-beta2 (a failing headless test must not hang)
  was first done in `TerminalDevice::attach()`: remember `current()` (the attaching task) and
  start a watcher `go(fn() => await($attacher))` that detaches the device when the attacher
  throws, then rethrows. Probed in a scratch script: (a) when the attacher is the closure of
  `fiber\run()`, `await()` of its `current()` handle runs that closure again (the closure
  printed its first line four times), and (b) when the attacher is a `go()` task and the
  watcher is started from inside that task, `await()` returns normally although the task
  throws. Awaiting the same task from a sibling started outside it works (rethrows). So the
  watcher was dropped and `FakeTerminal::run()` does the plain thing (detach in `finally`).
  The api README allows awaiting go() handles and says `current()` is such a handle; either
  the adapter should honour that for the run() task and for awaits from a child task, or the
  api should say `current()` is for `===` only. Nothing of the library depends on it now.
  **Status (beta4):** fixed on fiber-interop/api main (4dc3d41, conformance example 23) and
  fiber-interop/phasync main (8445790), not tagged yet. The library's watcher
  (Internal\Runtime::watch()) is used only on versions newer than 0.3.1 / 0.4.1 or dev
  branches; on the tagged ones start() documents the limit and Tui::run() is the way.

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
