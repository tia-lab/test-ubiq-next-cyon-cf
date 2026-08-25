# Section Reference Spec

Status: implemented

## Purpose

Add a `Section Reference` block to the page `sections` builder so an editor can reuse a section without duplicating its content. The reference block may only override spacing. It must not duplicate section rendering logic, must not allow recursive references, and must keep Craft authoring and Next cache invalidation predictable.

## Evidence

- Code-read evidence: `craft/config/project/fields/sections--360652e6-00ff-4774-b5c7-d9ad1331e09b.yaml` defines `sections` as a Craft Matrix field containing `sectionHero`, `sectionAbout`, `sectionCta`, `sectionContent`, `sectionContact`, and `sectionNews`.
- Code-read evidence: implemented Craft project config under
  `craft/config/project/` defines the section entry types, shared spacing
  fields, `sections` Matrix field, reusable section channel, and GraphQL
  schema scope.
- Code-read evidence: `app/src/queries/fragments/section.ts` defines `SectionFragment` on `sections_MatrixField` and enumerates every current section type.
- Code-read evidence: `app/src/Sections/SectionRouter.ts` maps `__typename` to one React section component. A reference block should reuse this router path instead of creating duplicate render logic.
- Code-read evidence: `app/src/Sections/utils/section-spacing.ts` already centralizes spacing CSS variables from `customSpacing`, `spaceTop`, and `spaceBottom`.
- Code-read evidence: `craft/modules/headless/cache_revalidation/CacheTagBuilder.php` emits `craft:sections` and `craft:section-entry:{id}` for nested Matrix entries, and owner page tags for normal nested sections.
- Code-read evidence: `app/src/lib/craft/queries/get-entry-by-uri.ts` currently tags page fetches by entry URI and page tags, but not by all section-reference dependencies.
- Code-read evidence: Craft’s `Entries` field source model in `craft/vendor/craftcms/cms/src/fields/Entries.php` filters relation picker sources through Craft entry sources. A top-level reusable section channel is cleaner than asking editors to pick arbitrary Matrix children from other page owners.

## Decision

Use a dedicated Craft channel named `Reusable Sections`, handle `reusableSections`, and a Matrix block named `Section Reference`, handle `sectionReference`.

The `Reusable Sections` channel owns reusable section entries. Its entry types are the existing real section types:

- `sectionHero`
- `sectionAbout`
- `sectionCta`
- `sectionContent`
- `sectionContact`
- `sectionNews`

It must not include `sectionReference`.

The `Section Reference` Matrix block lives inside the normal page `sections` field. It has:

- title field for editor labeling
- `referencedSection`, an Entries field limited to one entry from `reusableSections`
- `customSpacing`
- `spaceTop`
- `spaceBottom`

Rendering uses the referenced section content. If the `Section Reference` has `customSpacing` enabled, its local spacing overrides the referenced section spacing. If not, the referenced section keeps its own spacing.

## Non-goals

- Do not allow references to other `sectionReference` entries.
- Do not allow editing referenced content from the page reference block.
- Do not support arbitrary nested Matrix entries from other pages in the first implementation.
- Do not add a new frontend visual component for referenced content.

## Craft Content Model

Implemented project config artifacts:

- `craft/config/project/sections/reusableSections--*.yaml`
- `craft/config/project/fields/referencedSection--*.yaml`
- `craft/config/project/entryTypes/sectionReference--*.yaml`
- updated `craft/config/project/fields/sections--*.yaml`
- updated `craft/config/project/graphql/schemas/*.yaml`
- updated `craft/config/project/project.yaml`

Authoring workflow:

1. Editor creates shared blocks under Entries -> Reusable Sections.
2. Editor adds `Section Reference` to any Page sections builder.
3. Editor selects one reusable section.
4. Editor optionally enables custom spacing on the reference block.

## GraphQL Contract

Update `app/src/queries/fragments/section.ts`:

- Add `sectionReference_Entry`.
- Query `id`, `title`, `typeHandle`, local spacing fields, and `referencedSection`.
- Inside `referencedSection`, include the same real section fields as the existing section union.

Implementation should avoid fragment recursion. Use a shared GraphQL selection string only if gql.tada accepts it cleanly; otherwise keep one explicit `ReferencedSectionFragment` for the reusable section union.

## Rendering Contract

Update `app/src/Sections/SectionRouter.ts`:

- Add a rendering path for `sectionReference_Entry`.
- Resolve the single referenced section.
- Render the referenced section through the same component map used by normal sections.
- Pass spacing override data to the rendered component.

Update section component prop shape:

- Current section components accept `{ section }`.
- Add optional `spacingOverride`.
- Components call `getSectionSpacingStyle(spacingOverride ?? data)`.

This keeps the actual section components as the only render owners.

## Cache Contract

Page queries must revalidate when any reusable section changes.

Minimum safe change:

- Add `craft:sections` to `entryTagsForUri()` in `app/src/lib/craft/queries/get-entry-by-uri.ts`.

Reason:

- Saving nested Matrix sections already emits `craft:sections`.
- Saving a reusable section as a top-level entry should also emit `craft:section:reusableSections`; the page query can additionally use that tag once the section exists.

Preferred change after content model exists:

- Add `craft:section:reusableSections` to page query tags.

This avoids stale pages when shared sections are updated.

## Failure Contract

- If `Section Reference` has no selected reusable section, render nothing.
- If the selected entry type is unknown, render nothing.
- If a reference somehow points to another reference, render nothing and do not recurse.
- If spacing override is enabled but no spacing value is valid, fall back to no override.

## Implementation Plan

1. Update Craft model script.
2. Run the model sync command used by the repo, expected `bun sync`.
3. Inspect generated project config.
4. Regenerate GraphQL schema/types, expected `bun gql`.
5. Update `SectionFragment`.
6. Update section component props and spacing usage.
7. Update `SectionRouter` to resolve references.
8. Update page cache tags for reusable sections.
9. Run `bun css`, `bun run lint`, `bun run build`.

## Review Questions

- Confirm the name `Section Reference` and handle `sectionReference`.
- Confirm the library name `Reusable Sections` and handle `reusableSections`.
- Confirm that reusable content must live in the library, not inside random page Matrix blocks.
