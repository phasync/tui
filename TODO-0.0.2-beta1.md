# 0.0.2-beta1: the components layer, first cut

Phase 2 starts. SPEC.md section 8 is the specification; this version builds its foundations and
the first widgets. Everything from 0.0.1 keeps working unchanged (the whole suite stays green; the
examples and the critic-shaped acceptance tests still pass); the new layer is additive, with the
old `TextInput`, `ListView`, `Box`, `StatusBar`, `Log`, `Terminal` kept as they are (they may be
reimplemented on the new layer if that is cleaner, with the same public API).

## 1. The element tree (8.1)

`render()` may return an element tree. Elements: `Column`, `Row` (sizes as Split takes them),
`Layers`, `Box`, `Text` (styled runs, wrapping), and a child component placed as an element. The
library keys elements by position or `key:` so Surfaces, focus and scroll positions persist across
renders; it paints elements through `write()` only. Tests: a tree rebuilt every render keeps focus
and scroll; element and imperative renders coexist in one component; a randomized tree-rebuild test
in the style of the focus test.

## 2. Reactive state (8.2)

`$this->state` (typed public properties, writes mark dirty), `set()`, re-render after handlers,
after coroutine steps, after child events and prop changes; one frame per tick. Tests: a counter
incremented in a handler shows without `requestRender()`; a coroutine loop updating state renders
once per frame, not per write; two components sharing state through props.

## 3. Stylesheet (8.3)

`Stylesheet`, element `style:`/`class:`/`id:`, selectors by type/class/id/state, theme variables,
a default theme; `:focused` on an `Editor` visibly different. Tests: cascade order, state
selectors, and that styling goes through the same `write()` (no cell API appears).

## 4. Bindings (8.4)

`bindings()` on components, app overrides, dispatch, a `Help` element listing them.

## 5. Widgets 1–4 of 8.5

`Editor` (multi-line, word wrap, undo/redo, kill ring, selection by keyboard and mouse, bracketed
paste, single-line mode), `Select` (header row, sortable columns, filter, multi-select, selection
kept across `setItems()`), `Table`, `Dialog` with an awaitable result. Each with docs, every state
styled, and tests through `FakeTerminal` for keyboard and mouse.

## 6. Also

`Ansi::clearHome()`, and a clear exception at start when `fiber-interop/api` is older than
required. `docs/components.md` gains the element tree, state, styles and bindings; a new
`docs/widgets.md` describes each widget; the README's tour shows a form-like screen built from
elements. CHANGELOG under `## 0.0.2-beta1`.

Widgets 5–8 of section 8.5 are for the following versions, together with what the critics find.
