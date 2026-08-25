# Declarative Element Animations Spec

## Status

Review required before implementation.

## Goal

Add a centralized, declarative animation primitive for normal HTML elements.

The public API should feel like native JSX with animation props added:

```tsx
<Anim.div type="fade">Content</Anim.div>
<Anim.section type="fade-up">Section</Anim.section>
<Anim.a href="/privacy" type="fade">Privacy</Anim.a>
<Anim.button type="fade-up" onClick={onClick}>Action</Anim.button>
```

The system must not reintroduce the old `Base` abstraction. Each `Anim.*`
component must render the actual requested HTML element and preserve native
element props.

## Evidence

- Code-read evidence: `app/src/animations/gsap.config.ts` centralizes GSAP and
  ScrollTrigger exports behind the `@/gsap` alias.
- Code-read evidence: `app/src/hooks/use-gsap-mm.ts` already wraps
  `gsap.matchMedia(...)` and accepts custom match-media conditions.
- Code-read evidence: `app/config.ts` defines media conditions under
  `config.context`, currently `isDesktop`, `isMobile`, and `reduceMotion`.
- Code-read evidence: `app/src/animations/transitions/` already separates page
  transition animation code from React components.
- Code-read evidence: `app/src/Components/index.ts` is the public component
  barrel used by app code.
- Code-read evidence: `/Users/tia/DEV/MISC/next-dato-starter/src/animations`
  used `animVars` and `animScrollVars` to allow GSAP and ScrollTrigger
  overrides per animation call.
- Code-read evidence:
  `/Users/tia/DEV/MISC/next-dato-starter/src/lib/animations/context-vars`
  allowed responsive animation overrides, but used string splitting. The new
  system should use typed media keys instead.

## Non-Goals

- Do not recreate `Base`, `BaseLink`, or a generic layout wrapper.
- Do not migrate existing components to `Anim.*` in this pass.
- Do not create every possible animation preset in v1.
- Do not add dependencies.
- Do not import GSAP directly from `gsap` outside the centralized `@/gsap`
  export.
- Do not put animation state in Zustand.
- Do not build a visual animation editor.
- Do not bind this to Craft fields yet.
- Do not change page transition runtime behavior.

## V1 Scope

Implement the architecture with only two animation presets:

```txt
fade
fade-up
```

These are enough to validate:

- `Anim.*` JSX API
- preset registry autocomplete
- GSAP vars overrides
- ScrollTrigger vars overrides
- media-specific overrides inferred from `config.context`
- reduced-motion behavior
- cleanup on unmount

Additional presets such as `fade-down`, `fade-left`, and `fade-right` should be
added later as separate files, not as direction branches inside `fade`.

## File Contract

```txt
app/src/Components/Anim/index.tsx
  Declarative JSX API. Exports Anim.

app/src/animations/elements/types.ts
  Shared element animation types.

app/src/animations/elements/fade.ts
  Fade preset implementation.

app/src/animations/elements/fade-up.ts
  Fade-up preset implementation.

app/src/animations/elements/registry.ts
  Preset registry and derived ElementAnimationName type.

app/src/animations/elements/index.ts
  Public element animation exports.

app/src/animations/index.ts
  Re-export element animations.

app/src/Components/index.ts
  Re-export Anim.

README.md
  Document usage after implementation.
```

## Public API

### Basic Usage

```tsx
<Anim.div type="fade">Content</Anim.div>
```

```tsx
<Anim.section type="fade-up">
  Section content
</Anim.section>
```

### Native Props

`Anim.*` must preserve native props for the rendered element:

```tsx
<Anim.a href="/about" type="fade">
  About
</Anim.a>
```

```tsx
<Anim.button type="fade-up" onClick={onClick}>
  Submit
</Anim.button>
```

### Overrides

Every animation can receive global overrides:

```tsx
<Anim.div
  type="fade-up"
  fromVars={{ y: "4rem" }}
  vars={{ duration: 0.8 }}
  scrollTrigger={{ start: "top 85%" }}
>
  Content
</Anim.div>
```

### Media Overrides

Media overrides must be inferred from `config.context`.

Current valid keys are:

```ts
keyof typeof config.context
```

With the current config, that means:

```txt
isDesktop
isMobile
reduceMotion
```

If `isTablet` or another media key is added to `config.context`, the `media`
prop must accept it automatically without changing the animation system.

Example:

```tsx
<Anim.div
  type="fade-up"
  media={{
    isDesktop: {
      fromVars: { y: "5rem" },
      vars: { duration: 1 },
      scrollTrigger: { start: "top 80%" },
    },
    isMobile: {
      fromVars: { y: "2rem" },
      vars: { duration: 0.45 },
      scrollTrigger: { start: "top 95%" },
    },
  }}
>
  Content
</Anim.div>
```

## Type Contract

Animation names must be derived from the registry:

```ts
export const elementAnimations = {
  fade,
  "fade-up": fadeUp,
} as const

export type ElementAnimationName = keyof typeof elementAnimations
```

Media keys must be derived from config:

```ts
export type AnimationMediaKey = keyof typeof config.context
```

The override shape:

```ts
export type ElementAnimationOverrides = {
  fromVars?: gsap.TweenVars
  vars?: gsap.TweenVars
  scrollTrigger?: ScrollTrigger.Vars
}
```

Media overrides:

```ts
export type ElementAnimationMediaOverrides = Partial<
  Record<AnimationMediaKey, ElementAnimationOverrides>
>
```

Preset context:

```ts
export type ElementAnimationContext = {
  element: HTMLElement
  context: gsap.Context
  conditions: Partial<Record<AnimationMediaKey, boolean>>
  fromVars?: gsap.TweenVars
  vars?: gsap.TweenVars
  scrollTrigger?: ScrollTrigger.Vars
  media?: ElementAnimationMediaOverrides
}
```

Preset signature:

```ts
export type ElementAnimation = (
  context: ElementAnimationContext
) => gsap.core.Tween | gsap.core.Timeline | ScrollTrigger | void
```

## `Anim.*` Component Type

`Anim` should expose all intrinsic JSX elements:

```ts
Anim.div
Anim.section
Anim.a
Anim.button
Anim.span
```

The type should be based on `keyof React.JSX.IntrinsicElements`, so adding an
HTML tag does not require a new handwritten component.

Implementation may use a typed `Proxy` or a generated object. The external API
must remain:

```tsx
<Anim.div type="fade" />
```

The animation props must be omitted from native props where necessary:

```ts
type AnimElementProps<Tag extends keyof React.JSX.IntrinsicElements> =
  Omit<React.ComponentPropsWithoutRef<Tag>, keyof ElementAnimationOwnProps> &
  ElementAnimationOwnProps
```

## Animation Runtime Contract

`Anim.*` must:

1. Create a ref for the rendered element.
2. Forward the real DOM element to any external ref.
3. Use `gsap.matchMedia(...)`.
4. Add `config.context` as match-media conditions.
5. Resolve active overrides.
6. Run the selected preset.
7. Revert all GSAP/ScrollTrigger work on unmount or dependency change.

The component must not own animation details. It only passes context to the
selected preset.

## Override Merge Order

Overrides must merge in this order:

```txt
preset defaults
-> component-level fromVars / vars / scrollTrigger
-> matching media fromVars / vars / scrollTrigger
```

Media overrides are additive. If multiple media conditions match, later keys in
`Object.keys(config.context)` may override earlier keys. This keeps behavior
deterministic and tied to config declaration order.

## Reduced Motion

Reduced motion must be handled through `config.context.reduceMotion`.

Default behavior:

- if `conditions.reduceMotion` is true, skip animated movement
- set the final visible state immediately
- still allow a user-provided `media.reduceMotion` override to customize the
  final vars

For `fade` and `fade-up`, reduced motion should not leave elements hidden.

## ScrollTrigger Contract

Element animations must use ScrollTrigger by default unless a preset explicitly
does not need it.

Default ScrollTrigger behavior for v1:

```ts
scrollTrigger: {
  trigger: element,
  start: "top 90%",
  once: true,
}
```

Rules:

- `trigger` defaults to the element.
- User `scrollTrigger.trigger` may override the trigger.
- User `scrollTrigger` values override preset defaults.
- Presets must safely no-op if the element is missing.
- ScrollTrigger must be imported from `@/gsap`.

## Preset Defaults

### `fade`

Initial:

```ts
{ autoAlpha: 0 }
```

Animate to:

```ts
{
  autoAlpha: 1,
  duration: config.animation.default,
  ease: config.animation.ease.out,
}
```

### `fade-up`

Initial:

```ts
{
  autoAlpha: 0,
  y: "2rem",
}
```

Animate to:

```ts
{
  autoAlpha: 1,
  y: 0,
  duration: config.animation.default,
  ease: config.animation.ease.out,
  clearProps: "transform",
}
```

## Code Invariants

### Separation

- `Anim` owns React rendering only.
- Presets own animation details.
- Registry owns names and autocomplete.
- GSAP configuration remains centralized in `@/gsap`.
- Page transitions remain under `app/src/animations/transitions`.
- Element animations live under `app/src/animations/elements`.

### Performance

- No React state for animation progress.
- No repeated ScrollTrigger registrations per render.
- No animation setup if the element ref is missing.
- All GSAP work must be scoped and reverted.

### Accessibility

- `Anim.*` must not change native semantics.
- `Anim.button` must still render a real button.
- `Anim.a` must still render a real anchor.
- Reduced motion must avoid motion while preserving visibility.

### Type Safety

- `type` must autocomplete from the registry.
- `media` keys must autocomplete from `config.context`.
- Native element props must remain typed per tag.
- Unknown animation names must not type-check.

## Implementation Plan

### Step 1: Element Animation Types

Create:

```txt
app/src/animations/elements/types.ts
```

Define:

- animation name support types
- override types
- media override types inferred from `config.context`
- preset context type
- preset function type

### Step 2: Preset Helpers

Add small internal helpers if needed:

- merge base overrides with media overrides
- create default ScrollTrigger vars
- handle reduced motion final state

Keep helpers inside `app/src/animations/elements/` unless they clearly become
generic.

### Step 3: Presets

Create:

```txt
app/src/animations/elements/fade.ts
app/src/animations/elements/fade-up.ts
```

Each preset must:

- import `config` from `$/config`
- import `gsap` and `ScrollTrigger` from `@/gsap`
- define defaults locally
- merge overrides
- return GSAP/ScrollTrigger work when useful

### Step 4: Registry

Create:

```txt
app/src/animations/elements/registry.ts
```

Register only:

```ts
fade
"fade-up"
```

Derive `ElementAnimationName`.

### Step 5: `Anim` Component

Create:

```txt
app/src/Components/Anim/index.tsx
```

Build the `Anim.*` API.

Required props:

- `type`
- `fromVars`
- `vars`
- `scrollTrigger`
- `media`

The component must use `gsap.matchMedia` with `config.context`.

### Step 6: Public Exports

Update:

```txt
app/src/animations/index.ts
app/src/Components/index.ts
```

### Step 7: Documentation

Update `README.md` with:

- basic usage
- override usage
- media override usage
- reduced-motion behavior
- rule to keep one preset per file

## Validation Plan

Run:

```sh
bun run lint
bun run build
```

Optional browser validation after implementation:

- start `bun dev`
- render one `Anim.div type="fade"`
- render one `Anim.div type="fade-up"`
- verify they animate on scroll
- verify reduced-motion media query leaves content visible

## Open Review Questions

1. Should `type` be required, or should `Anim.div` without `type` render with no
   animation?
2. Should v1 include a `disabled` prop for conditional no-op rendering?
3. Should `ScrollTrigger` be default for all v1 presets, or should `fade` run
   immediately by default?
4. Should `once` default to true for all scroll animations?
