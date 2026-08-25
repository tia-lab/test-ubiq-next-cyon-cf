# Page Transition Runtime Spec

## Status

Review required before implementation.

## Goal

Add a minimal page transition runtime on top of the existing page lifecycle
store.

The runtime must support named transition effects with TypeScript autocomplete:

```tsx
<TransitionLink href="/about" transition="fade">
  About
</TransitionLink>
```

The runtime must preserve the current lifecycle sequence:

```txt
leaving -> navigating -> entering -> idle
```

Animation code must stay centralized under `app/src/animations/`. The lifecycle
store must remain serializable state only.

## Evidence

- Code-read evidence: `app/src/store/page-lifecycle.ts` stores lifecycle state
  and actions only. It currently defines `PageTransitionName` as a loose string.
- Code-read evidence: `app/src/hooks/use-page-transition.ts` currently starts
  the lifecycle, immediately marks `navigating`, and calls `router.push(...)`.
  It does not await an animation.
- Code-read evidence: `app/src/hooks/use-page-lifecycle.ts` currently observes
  `usePathname()`, marks `entering`, and completes on `requestAnimationFrame`.
  It does not await an enter animation.
- Code-read evidence: `app/src/Components/TransitionLink/index.tsx` already
  wraps internal links and calls `navigate(...)`.
- Code-read evidence: `app/src/Components/Button/index.tsx` already routes
  internal button links through `usePageTransition()`.
- Code-read evidence: `app/src/animations/transitions/index.ts` exists and is
  empty.
- Code-read evidence: `app/src/animations/gsap.config.ts` centralizes GSAP and
  is available through the `@/gsap` alias in `app/tsconfig.json`.

## Non-Goals

- Do not add dependencies.
- Do not move the existing lifecycle store.
- Do not put GSAP imports or animation functions in Zustand stores.
- Do not build a large event bus.
- Do not replace all `next/link` usages in the app in this pass. Only
  transition-aware UI should use `TransitionLink` or `usePageTransition()`.
- Do not implement page-specific section animations in the transition registry.
  Page and section animations must subscribe to lifecycle state separately.

## Implementation Scope

This implementation is limited to the transition runtime needed to make
controlled internal navigation await named leave and enter effects.

In scope:

- typed transition registry under `app/src/animations/transitions/`
- `none` no-op transition
- `fade` GSAP transition
- transition-name autocomplete through `PageTransitionName`
- `usePageTransition()` awaiting `leave`
- `usePageLifecycle()` awaiting `enter`
- stale navigation and double-click protection
- reduced-motion no-op behavior
- `transition` prop on `TransitionLink`
- `transition` prop on internal-link `Button`
- stable transition root attribute in `Layout`
- `usePageLifecycleEffect()` for later page/section animation hooks

Out of scope:

- visual design of real page transitions beyond the first `fade` effect
- route-specific transition selection from Craft
- replacing every raw `next/link` in the app
- page-specific hero or section animations
- ScrollTrigger lifecycle work
- Craft, GraphQL, SEO, image, or cache changes

## File Contract

```txt
app/src/animations/transitions/types.ts
  Shared transition types.

app/src/animations/transitions/none.ts
  No-op transition. Used as the safe fallback.

app/src/animations/transitions/fade.ts
  First GSAP-backed transition effect.

app/src/animations/transitions/registry.ts
  Named transition registry and derived PageTransitionName type.

app/src/animations/transitions/runtime.ts
  Small runtime helpers for active AbortController, stale navigation checks,
  root element lookup, and reduced-motion checks.

app/src/animations/transitions/index.ts
  Public animation transition exports.

app/src/hooks/use-page-transition.ts
  Orchestrates leave animation, lifecycle start, navigation, and stale guards.

app/src/hooks/use-page-lifecycle.ts
  Observes route changes, orchestrates enter animation, and completes lifecycle.

app/src/hooks/use-page-lifecycle-effect.ts
  Optional small hook for page and section animations to react to lifecycle
  statuses without duplicating store subscription logic.

app/src/hooks/index.ts
  Public hook exports.

app/src/store/page-lifecycle.ts
  Keep state-only. Import PageTransitionName as a type only if needed.

app/src/Components/TransitionLink/index.tsx
  Accept transition prop and pass it to navigate().

app/src/Components/Button/index.tsx
  Accept transition prop for internal link buttons.

app/src/Components/Layout/index.tsx
  Add a stable page transition root attribute.
```

## Transition Types

```ts
export type PageTransitionContext = {
  id: number
  from: string | null
  to: string
  root: HTMLElement | null
  signal: AbortSignal
}

export type PageTransition = {
  rootSelector?: string
  leave?: (context: PageTransitionContext) => Promise<void> | void
  enter?: (context: PageTransitionContext) => Promise<void> | void
}
```

Rules:

- `root` can be `null`. Transitions must safely no-op when it is missing.
- `signal.aborted` must be checked before and after awaited work.
- Transition functions must not mutate lifecycle state directly.
- Transition functions may import GSAP only through `@/gsap`.

## Registry Contract

```ts
export const pageTransitions = {
  none: noneTransition,
  fade: fadeTransition,
} as const

export type PageTransitionName = keyof typeof pageTransitions
```

This makes the public prop autocomplete:

```tsx
<TransitionLink href="/about" transition="fade" />
```

The `none` transition must be a no-op. If an unknown value reaches runtime, the
runner must fall back to `none`.

## Runtime Flow

### Controlled Navigation

`usePageTransition().navigate(href, options)` must run:

```txt
1. Ignore external URLs, hash URLs, modifier-key clicks, and same-path clicks.
2. Abort any active transition runtime.
3. lifecycle.start({ from, to, transition })
4. Build PageTransitionContext for the selected transition.
5. Await selectedTransition.leave(context).
6. If aborted or stale, stop.
7. lifecycle.markNavigating(id)
8. router.push(href) or router.replace(href)
```

### Route Confirmation

`usePageLifecycle()` must run when `usePathname()` changes:

```txt
1. If this is the first pathname, set current and stop.
2. If lifecycle.to matches the pathname, mark entering.
3. Build PageTransitionContext for the selected transition.
4. Await selectedTransition.enter(context).
5. If aborted or stale, stop.
6. lifecycle.complete(id)
7. Clear transition runtime state.
8. If no lifecycle transition is active, set current normally.
```

## Root Element

`Layout` must expose one stable fallback root and one content target:

```tsx
<div className="page-wrapper" data-page-transition-root>
  <main data-page-transition-content>
    ...
  </main>
</div>
```

The runtime helper should resolve transition-specific selectors first:

```ts
document.querySelector<HTMLElement>(
  transition.rootSelector ?? '[data-page-transition-root]'
)
```

The first `fade` transition targets `[data-page-transition-content]`, so
persistent chrome can stay visible. The fallback root remains available for
future transitions that need the full wrapper.

## Reduced Motion

The transition runner must skip GSAP work when:

```txt
window.matchMedia(config.context.reduceMotion).matches
```

In reduced-motion mode the lifecycle still emits the same statuses, but
`leave` and `enter` resolve immediately.

## Cancellation And Stale Work

The runtime must keep one active transition:

```ts
type ActivePageTransition = {
  id: number
  controller: AbortController
}
```

When a new navigation starts:

- abort the previous controller
- create a new controller for the new lifecycle id
- ignore old `leave` or `enter` completions if the id no longer matches the
  store id

This avoids old animations completing after a newer navigation.

## Performance Rules

- Use opacity and transform effects only in initial transitions.
- Do not animate layout properties.
- Do not query the DOM repeatedly during a single phase.
- Do not create long-lived observers.
- Do not store functions in Zustand.
- Do not keep large animation objects in React state.
- Kill or overwrite root tweens inside GSAP transitions before starting a new
  root tween.
- Respect reduced motion.

## Initial Effects

### `default`

No-op transition:

```txt
leave: immediate resolve
enter: immediate resolve
```

### `fade`

GSAP-backed transition:

```txt
leave: fade root opacity to 0
enter: set root opacity to 0, then fade root opacity to 1
```

Use `config.animation.short` or `config.animation.default`. The effect should be
small enough to verify the runtime without making navigation feel slow.

## Lifecycle Effects For Page Sections

Add:

```txt
app/src/hooks/use-page-lifecycle-effect.ts
```

Suggested API:

```ts
usePageLifecycleEffect('idle', ({ current, from, to }) => {
  // section animation can start after page enter completes
})
```

Rules:

- The hook subscribes to `usePageLifecycleStore`.
- It calls the callback only when entering the requested status.
- It does not run GSAP itself.
- It must return the unsubscribe cleanup.

This lets a hero animation wait until the page transition is finished by
subscribing to `idle`.

## Public Component API

### `TransitionLink`

Add:

```ts
transition?: PageTransitionName
replace?: boolean
```

It must pass:

```ts
navigate(String(href), { transition, replace })
```

### `Button`

Add:

```ts
transition?: PageTransitionName
```

Only internal links with `nextJs={true}` use this option. External anchors,
downloads, modal buttons, and sidebar buttons must keep their current behavior.

## Implementation Plan

### Phase 1: Transition Types And Registry

Create:

```txt
app/src/animations/transitions/types.ts
app/src/animations/transitions/none.ts
app/src/animations/transitions/fade.ts
app/src/animations/transitions/registry.ts
app/src/animations/transitions/index.ts
```

Work:

1. Define `PageTransitionContext` and `PageTransition` in `types.ts`.
2. Define `noneTransition` as a no-op leave/enter transition.
3. Define `fadeTransition` using `import { gsap } from '@/gsap'`.
4. Define `pageTransitions` in `registry.ts`.
5. Export `PageTransitionName = keyof typeof pageTransitions`.
6. Export all public transition types and registry values from `index.ts`.

Acceptance:

- transition names are derived from registry keys
- no direct `import gsap from 'gsap'`
- `default` is always available

### Phase 2: Runtime Helpers

Create:

```txt
app/src/animations/transitions/runtime.ts
```

Work:

1. Add one module-level active transition controller.
2. Add `startPageTransitionRuntime(id)`.
3. Add `getPageTransitionRoot()`.
4. Add `isReducedMotion()`.
5. Add `getPageTransition(name)`.
6. Add `isCurrentTransition(id, signal)`.
7. Add `clearPageTransitionRuntime(id)`.

Acceptance:

- new navigation aborts previous transition work
- stale animation completions can be ignored
- root lookup happens once per phase
- reduced motion can skip animation work without skipping lifecycle state

### Phase 3: Lifecycle Store Type Wiring

Edit:

```txt
app/src/store/page-lifecycle.ts
app/src/store/index.ts
```

Work:

1. Replace loose `PageTransitionName = 'none' | (string & {})` with a
   type-only import from `app/src/animations/transitions`.
2. Keep all store state and actions unchanged unless TypeScript requires a
   type-only adjustment.
3. Keep the store free of GSAP, runtime helpers, router code, and DOM access.

Acceptance:

- store remains serializable
- transition names autocomplete from registry
- no runtime dependency from store to animation code

### Phase 4: Leave Runner

Edit:

```txt
app/src/hooks/use-page-transition.ts
```

Work:

1. Keep current external URL, hash URL, same-path, and router replace/push
   behavior.
2. Start lifecycle and runtime controller.
3. Resolve selected transition by name.
4. Build `PageTransitionContext`.
5. Await `transition.leave(context)` unless reduced motion is active.
6. Stop if aborted or stale.
7. Mark `navigating`.
8. Push or replace route.

Acceptance:

- `leaving` lasts until leave animation resolves
- `navigating` happens after leave
- stale leave callbacks do not push older routes

### Phase 5: Enter Runner

Edit:

```txt
app/src/hooks/use-page-lifecycle.ts
```

Work:

1. Keep initial pathname behavior.
2. When pathname matches active lifecycle target, mark `entering`.
3. Resolve selected transition by name.
4. Build `PageTransitionContext`.
5. Await `transition.enter(context)` unless reduced motion is active.
6. Stop if aborted or stale.
7. Complete lifecycle.
8. Clear runtime controller.
9. Preserve debug logging while this feature is being verified.

Acceptance:

- `entering` lasts until enter animation resolves
- `idle` happens after enter
- non-transition route changes still update `current`

### Phase 6: Public Component APIs

Edit:

```txt
app/src/Components/TransitionLink/index.tsx
app/src/Components/Button/index.tsx
```

Work:

1. Add `transition?: PageTransitionName` to `TransitionLink`.
2. Add `replace?: boolean` to `TransitionLink`.
3. Pass `transition` and `replace` into `navigate(...)`.
4. Add `transition?: PageTransitionName` to `Button`.
5. Pass `transition` into `navigate(...)` only for internal `nextJs` links.
6. Leave external anchors, downloads, modal buttons, and sidebar buttons
   unchanged.

Acceptance:

- `<TransitionLink transition="fade" />` autocompletes
- `<Button href="/about" transition="fade" />` autocompletes
- non-navigation button behavior is unchanged

### Phase 7: Transition Root

Edit:

```txt
app/src/Components/Layout/index.tsx
```

Work:

1. Add `data-page-transition-root` to the existing page wrapper element.
2. Add `data-page-transition-content` around route content.
3. Do not move `Modal` or `Sidebar` into the transition root.

Acceptance:

- page content is animated
- modal and sidebar roots are not affected by page transition opacity

### Phase 8: Lifecycle Effect Hook

Create:

```txt
app/src/hooks/use-page-lifecycle-effect.ts
```

Edit:

```txt
app/src/hooks/index.ts
```

Work:

1. Add `usePageLifecycleEffect(status, callback)`.
2. Subscribe to `usePageLifecycleStore`.
3. Call the callback only when the status changes into the requested status.
4. Return unsubscribe cleanup.
5. Export the hook.

Acceptance:

- sections can subscribe to `idle` to start after page enter
- hook does not import GSAP
- hook does not duplicate transition-runner logic

### Phase 9: Validation

Run:

```sh
bun run lint
bun run build
```

Manual check:

1. Run the app.
2. Open browser console.
3. Click Header links.
4. Confirm lifecycle logs:

```txt
leaving -> navigating -> entering -> idle
```

5. Confirm `transition="fade"` produces a visible fade.
6. Confirm reduced-motion mode keeps lifecycle logs but skips visible animation
   delay.

## Validation Plan

Run:

```sh
bun run lint
bun run build
```

Manual browser check:

```txt
Route: /
Action: click Header links rendered with TransitionLink
Expected console sequence:
leaving -> navigating -> entering -> idle
```

Manual reduced-motion check:

```txt
Enable prefers-reduced-motion in browser tooling.
Click internal navigation.
Expected: same lifecycle sequence, no visible fade animation delay.
```

## Rollback Boundary

Rollback is limited to:

- files under `app/src/animations/transitions/`
- `app/src/hooks/use-page-transition.ts`
- `app/src/hooks/use-page-lifecycle.ts`
- `app/src/hooks/use-page-lifecycle-effect.ts`
- `app/src/Components/TransitionLink/index.tsx`
- `app/src/Components/Button/index.tsx`
- `app/src/Components/Layout/index.tsx`
- type-only transition name wiring in `app/src/store/page-lifecycle.ts`

No Craft files, GraphQL files, generated style files, or environment files are
part of this implementation.
