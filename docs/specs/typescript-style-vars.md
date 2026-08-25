# TypeScript Style Vars Spec

## Goal

Create a typed style-token source of truth in TypeScript and generate Sass
variables from it.

The first implementation is intentionally small: spacing tokens only.

## Evidence

- Code-read evidence: `src/styles/vars.scss` is the public Sass API and is
  forwarded by `src/styles/export.scss`.
- Code-read evidence: spacing utilities read `vars.$spacings` in
  `src/styles/utility/_spacings.scss`.
- Code-read evidence: `src/styles/utility/_spacings.scss` already emits CSS
  variables from the Sass spacing map.

## V1 Scope

- Define spacing tokens in `src/styles/vars.ts`.
- Define generation breakpoints in `src/styles/vars.ts`.
- Put token helper functions in `src/lib/styles/`.
- Generate a Sass partial from TypeScript.
- Generate spacing utility classes from TypeScript.
- Keep `src/styles/vars.scss` as the stable Sass import surface.
- Do not migrate colors, typography, layout, or existing Sass breakpoint
  mixins in v1.

## File Contract

```txt
src/styles/vars.ts
  Style token source of truth.

src/lib/styles/
  Helper functions and renderers used by the generator.

src/styles/maps.generated.scss
  Generated Sass maps. Do not edit manually.

src/styles/root.generated.scss
  Generated CSS custom properties. Do not edit manually.

src/styles/vars.generated.scss
  Generated Sass aliases to CSS custom properties. Do not edit manually.

src/styles/utilities.generated.scss
  Generated utility classes. Do not edit manually.

src/styles/vars.scss
  Stable Sass bridge used by existing styles.

app/scripts/generate-vars.ts
  Writes the generated Sass partial.
```

## Generated Sass Contract

The generated maps file must expose:

```scss
$spacings: (
  xs: (
    desktop: 1rem,
    mobile: 0.75rem,
  ),
);
```

CSS custom properties are still emitted by
`src/styles/_vars.generated.scss`, using the generated spacing tokens.

The generated vars file must expose CSS custom properties and Sass aliases.
CSS custom properties use desktop as the default and emit downward overrides
only when a token defines them:

```scss
:root {
  --spacing-xs: 1rem;
}

@media (max-width: 767px) {
  :root {
    --spacing-xs: 0.75rem;
  }
}

$spacing-xs: var(--spacing-xs);
```

If a tablet breakpoint exists but a spacing token does not define a tablet
value, tablet inherits the desktop value.

## Generated Utility Contract

Generated spacing utilities must use CSS custom properties:

```scss
.mb-xs {
  margin-bottom: var(--spacing-xs);
}

.py-xs {
  padding-top: var(--spacing-xs);
  padding-bottom: var(--spacing-xs);
}
```

Utilities are declared in `src/styles/vars.ts` with `createUtility()`. If a
utility is not declared there, it is not generated.

## Command

```txt
bun run styles:vars
```

## Expansion Path

After the spacing pipeline is stable, the same source can generate:

- color Sass maps and CSS custom properties
- layout maps
- breakpoint maps
- typography maps
- optional utility classes
