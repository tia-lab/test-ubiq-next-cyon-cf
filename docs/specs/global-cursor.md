# Global Cursor Spec

## Status

Review required before implementation.

## Goal

Add a minimal global cursor system that can react to shared interaction state
from buttons, links, and future interactive components.

The cursor must be globally rendered, configurable, animation-ready, and safe to
disable completely:

```ts
cursor: {
  enabled: true
}
```

When disabled, the cursor system must not render, animate, or update store
state.

## Evidence

- Code-read evidence: `app/config.ts` already exposes `config.cursor.enabled`.
- Code-read evidence: `app/src/Components/Cursor/index.tsx` currently renders a
  static cursor element only.
- Code-read evidence: `app/src/Components/Cursor/style.module.scss` currently
  contains only a fixed circular cursor style.
- Code-read evidence: `app/src/Components/Layout/index.tsx` currently renders
  `<Cursor />` unconditionally.
- Code-read evidence: `app/src/Components/Button/index.tsx` owns the main
  reusable button and link-button surface.
- Code-read evidence: `app/src/Components/TransitionLink/index.tsx` owns
  transition-aware internal links.
- Code-read evidence: stores under `app/src/store/` use Zustand and keep UI
  state outside components.
- Code-read evidence: `app/src/hooks/use-gsap.ts` and
  `app/src/hooks/use-gsap-mm.ts` provide the project GSAP hook surfaces.
- Code-read evidence: GSAP imports must go through `@/gsap`, documented in
  `README.md`.

## Non-Goals

- Do not add dependencies.
- Do not replace native focus states.
- Do not hide or replace the browser pointer globally in this pass.
- Do not add a generic event bus.
- Do not add page-specific cursor designs.
- Do not add custom cursor behavior to every raw `<a>` in the app in this pass.
- Do not use React state for pointer position updates.
- Do not touch generated style files.

## Code Invariants

### Config Gate

When `config.cursor.enabled === false`:

- `<Cursor />` is not rendered.
- cursor hooks return no-op handlers.
- `Button` and `TransitionLink` do not update cursor state.
- no cursor GSAP animation runs.
- no cursor pointer listener is attached.

### Store

The cursor store is state-only:

- no DOM access
- no GSAP
- no router
- no timers
- no event listeners

The store must hold only serializable interaction state.

### Cursor Component

The cursor component owns animation:

- reads cursor store state
- tracks pointer position
- uses refs for pointer position and DOM nodes
- uses `useGSAP`
- imports GSAP only from `@/gsap`
- cleans up pointer listeners and GSAP work on unmount

### Interaction Surfaces

`Button` and `TransitionLink` must not animate the cursor.

They may only call or spread handlers from `useCursorInteraction(...)`.

Existing user-provided handlers must still run:

- `onPointerEnter`
- `onPointerLeave`
- `onPointerDown`
- `onPointerUp`
- existing click handlers

### Route Changes

Route changes must reset cursor interaction state so hover/active states cannot
stick after navigation.

### Reduced Motion

If `config.context.reduceMotion` matches, cursor animation must avoid animated
movement. It can use immediate setters or render the cursor in a static
interaction state.

### Accessibility

The cursor element must be:

- `aria-hidden`
- `pointer-events: none`
- non-focusable

The cursor must not be required for understanding or operating the UI.

### Performance

The cursor system must be minimal and high performance:

- one global cursor component
- one Zustand store
- one interaction hook
- no component re-render on every pointer move
- no per-button GSAP instances
- pointer movement handled with refs and GSAP quick setters or `quickTo`
- disabled path does no work

## File Contract

```txt
app/src/store/cursor.ts
  Cursor interaction state and actions only.

app/src/hooks/use-cursor-interaction.ts
  Small hook returning pointer handlers for interactive components.

app/src/hooks/index.ts
  Public hook export.

app/src/Components/Cursor/index.tsx
  Global cursor renderer and animation owner.

app/src/Components/Cursor/style.module.scss
  Cursor presentation styles only.

app/src/Components/Layout/index.tsx
  Config-gated cursor render and route reset wiring.

app/src/Components/Button/index.tsx
  Adds cursor interaction handlers while preserving existing props.

app/src/Components/TransitionLink/index.tsx
  Adds cursor interaction handlers while preserving existing props.

app/src/store/index.ts
  Public cursor store export.

README.md
  Cursor usage and architecture documentation after implementation.
```

## Cursor State

Initial state:

```ts
type CursorVariant = 'default' | 'hover' | 'active' | 'hidden'

type CursorState = {
  variant: CursorVariant
  label: string | null
  locked: boolean
}
```

Actions:

```ts
setVariant(variant: CursorVariant): void
setLabel(label: string | null): void
setState(input: Partial<CursorState>): void
reset(): void
lock(): void
unlock(): void
```

Rules:

- `reset()` restores `default`, `null`, and `false`.
- `locked` prevents low-priority hover changes from overriding an intentional
  cursor state.
- Store actions must not inspect DOM events.

## Interaction Hook

Hook API:

```ts
type CursorInteractionOptions = {
  variant?: CursorVariant
  label?: string | null
  disabled?: boolean
}

const handlers = useCursorInteraction({
  variant: 'hover',
  label: null
})
```

Returned handlers:

```ts
{
  onPointerEnter,
  onPointerLeave,
  onPointerDown,
  onPointerUp,
}
```

Rules:

- If cursor config is disabled, return stable no-op handlers or an empty object.
- If `options.disabled` is true, return no-op handlers.
- `pointerenter` sets requested variant and label.
- `pointerleave` resets cursor unless locked.
- `pointerdown` may set `active`.
- `pointerup` returns to the hover variant when still inside the element.
- The hook must not import GSAP.

## Handler Composition

Components must preserve external handlers.

Expected pattern:

```tsx
const cursorHandlers = useCursorInteraction({ variant: 'hover' })

<a
  onPointerEnter={(event) => {
    onPointerEnter?.(event)
    cursorHandlers.onPointerEnter?.(event)
  }}
/>
```

If repeated composition becomes noisy, add one tiny local helper in the component
file or a shared hook helper. Do not create a broad event utility layer.

## Cursor Rendering

`Cursor` must:

- return `null` if disabled
- render one fixed element when enabled
- track pointer position through a document-level `pointermove` listener
- use `useGSAP` for setup and cleanup
- use immediate setters for reduced motion
- use `quickTo` or equivalent GSAP setters for normal movement
- subscribe to cursor store state for variant changes

Base markup:

```tsx
<div className={$.cursor} aria-hidden="true" />
```

## Layout Behavior

`Layout` must:

- render `<Cursor />` only when enabled
- reset cursor state when `pathname` changes
- not move `Modal`, `Sidebar`, or page transition roots for this feature

## Styling

Cursor styles must:

- keep `position: fixed`
- keep `pointer-events: none`
- use generated Sass variables
- avoid layout-affecting properties during movement
- keep transform animation in JS, not CSS transitions

Variant styling can start minimal:

- default: small dot
- hover: larger dot
- active: slightly smaller or pressed state
- hidden: opacity 0

## Implementation Plan

### Phase 1: Store

Create:

```txt
app/src/store/cursor.ts
```

Work:

1. Define `CursorVariant`.
2. Define cursor state and actions.
3. Implement Zustand store.
4. Export store and types from `app/src/store/index.ts`.

Acceptance:

- store has no DOM, GSAP, timers, or router imports
- `reset()` restores the initial state

### Phase 2: Interaction Hook

Create:

```txt
app/src/hooks/use-cursor-interaction.ts
```

Work:

1. Read `config.cursor.enabled`.
2. Return no-op behavior when disabled.
3. Update cursor store on pointer enter/leave/down/up.
4. Export hook and types from `app/src/hooks/index.ts`.

Acceptance:

- hook has no GSAP import
- disabled path does not update the store
- handlers are typed for React pointer events

### Phase 3: Cursor Component

Edit:

```txt
app/src/Components/Cursor/index.tsx
app/src/Components/Cursor/style.module.scss
```

Work:

1. Make cursor a client component.
2. Return `null` when disabled.
3. Render `aria-hidden` cursor element.
4. Attach one document-level pointer listener.
5. Use GSAP quick movement.
6. Animate variant changes from store state.
7. Respect reduced motion.

Acceptance:

- no React state update on every pointer move
- cleanup removes pointer listener and GSAP context
- cursor cannot block clicks

### Phase 4: Layout Wiring

Edit:

```txt
app/src/Components/Layout/index.tsx
```

Work:

1. Render `<Cursor />` only when `config.cursor.enabled`.
2. Reset cursor on pathname changes.

Acceptance:

- disabled cursor path renders no cursor
- route changes clear hover/active states

### Phase 5: Interactive Components

Edit:

```txt
app/src/Components/Button/index.tsx
app/src/Components/TransitionLink/index.tsx
```

Work:

1. Add `useCursorInteraction({ variant: 'hover' })`.
2. Compose pointer handlers with existing props.
3. Apply handlers to internal links, external links, and buttons.

Acceptance:

- existing click and pointer props still run
- no cursor animation code enters these components
- internal transition behavior remains unchanged

### Phase 6: Documentation

Edit:

```txt
README.md
```

Work:

1. Add cursor config notes.
2. Document store/hook/component split.
3. Document usage for `Button`, `TransitionLink`, and future components.

## Validation

Run:

```sh
bun run lint
bun run build
```

Manual browser checks:

- cursor disabled: no cursor rendered, normal pointer works
- cursor enabled: cursor follows pointer smoothly
- button hover changes cursor state
- transition link hover changes cursor state
- route navigation resets cursor
- reduced motion does not animate cursor movement

## Rollback Boundary

Rollback is limited to:

- `app/src/store/cursor.ts`
- `app/src/hooks/use-cursor-interaction.ts`
- `app/src/Components/Cursor/`
- `app/src/Components/Layout/index.tsx`
- `app/src/Components/Button/index.tsx`
- `app/src/Components/TransitionLink/index.tsx`
- `README.md`

No Craft, GraphQL, cache, SEO, or generated style files are involved.
