# Preload Runtime Spec

## Goal

Provide a minimal global preload runtime that:

- renders one full-screen overlay when `config.preload.enabled` is true
- fades the overlay out with GSAP
- stores completion in `sessionStorage`
- exposes typed Zustand state and selector hooks for animation synchronization

## Behavior

- First tab session load starts with `status: idle`.
- `Preload` calls `start()`, runs its timeline, then calls `complete()`.
- `complete()` sets `status: done`, `hasCompleted: true`, and persists that
  state in `sessionStorage`.
- Subsequent client navigations and reloads in the same tab do not replay the
  preload.
- A new browser tab has a new `sessionStorage` session and can replay preload.

## Public API

```ts
usePreloadStore
usePreloadStatus()
usePreloadDone()
usePreloadRunning()
usePreloadIs(status)
```

Use selector hooks for animation dependencies. Use the store directly when
actions are needed.

## Constraints

- GSAP timelines must be held in refs.
- Do not add dependencies.
- Do not block rendering of page content.
- Do not change page transition behavior.
