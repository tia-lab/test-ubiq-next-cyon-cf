# Craft Headless Page Composer Spec

## Goal

Rebuild the Craft backend model for the Next headless starter around a small,
English, section-based page composer.

The new model must let editors compose pages from reusable sections, expose the
model cleanly through Craft GraphQL, and let Next render pages with a generic
section router instead of page-specific hardcoded section lists.

## Evidence

- Code-read evidence: `craft/config/project/sections/pages--9325d8bd-ffd0-45d1-ba80-a388a36b45f1.yaml`
  currently defines a `pages` structure, but it still allows old entry types
  `Trennzeichen`, `Page`, and `Channel`, uses `page/{slug}`, and points at a
  Twig template.
- Code-read evidence: `craft/config/project/sections/neuigkeiten--5de84b4a-c11a-4447-9527-f883886a0a36.yaml`
  currently defines a German `neuigkeiten` channel.
- Code-read evidence: `craft/config/project/globalSets/navigation--aaf8d3d1-22cc-49da-bf90-816170713bdf.yaml`
  currently defines one global `navigation` set backed by an old Matrix field.
- Code-read evidence: `craft/config/project/project.yaml` shows Freeform is
  installed and enabled as `freeform` with schema version `5.15.1`.
- Code-read evidence: `src/Sections/SectionRouter.ts` currently maps fixed page
  entry types to hardcoded section components. It is not yet driven by a Craft
  sections array.
- Code-read evidence: `src/queries/pages/entry-by-uri.ts` currently contains
  Degen-specific entry type fragments such as `gutscheine_Entry`,
  `menuekarten_Entry`, and `restaurant_Entry`.

## Principles

- Use English names and handles only for the new starter model.
- Model pages as content, navigation as navigation, and sections as reusable
  content blocks.
- Clean the old starter model before adding new sections. Do not build the new
  composer on top of legacy Frameworq/German fields, entry types, sections, or
  globals.
- Do not duplicate collection content inside page sections. Sections control
  display; channels own collection entries.
- Prefer one section block type per variant when variants have meaningfully
  different fields.
- Keep the first implementation small. Avoid advanced filtering and visual
  design fields until the base model is stable.
- SEO is part of the base model because Next needs global fallbacks and
  per-entry overrides to generate metadata.

## Target Craft Model

### Sections

Keep one primary `Pages` structure.

Handle:

```txt
pages
```

Entry type:

```txt
page
legalPage
```

Fields:

```txt
image
sections
richText
SEO tab
```

Notes:

- `pages` must be a structure so it can own page hierarchy.
- `page` entries use the section composer.
- `legalPage` entries are intentionally simpler and contain only title plus
  rich text for pages like Impressum or Privacy Policy.
- `image` is an optional entry-level content image that can be used as a
  metadata fallback when explicit SEO images are empty.
- Page URLs must resolve in Next by `uri`.
- Craft Twig templates are not part of the frontend contract for headless
  pages.

### News Channel

Create or rename the existing news channel to English.

Handle:

```txt
news
```

Entry type:

```txt
news
```

Fields:

```txt
image
excerpt
richText
SEO tab
```

Required:

- `title`
- `image`

Ordering:

- Use Craft `postDate` for default chronological ordering.

Notes:

- `excerpt` is for cards and sliders.
- `richText` is the article body for future detail pages.
- Detail-page routing can be added later; it is not required for the first
  section composer pass.

### Navigations

Use a navigation builder, not a lightswitch on every page.

Recommended model:

```txt
navigations
```

This can be a Global Set with a Matrix, or a Structure/Channel if multiple
navigation definitions are easier to manage in Craft. The implementation should
prefer the simplest Craft-native setup that supports multiple named
navigations.

Navigation definition fields:

```txt
handle
label
maxDepth
items
```

Options:

```txt
maxDepth: 1 | 2 | 3 | 4
```

Default main navigation:

```txt
handle: main
maxDepth: 1
```

Manual item fields:

```txt
label
page
url
openInNewTab
children
```

Rules:

- `page` is used for internal links.
- `url` is used for external links.
- If both are empty, the item is invalid.
- Order follows the authored item order.
- `children` contains nested navigation items.
- `maxDepth` limits rendering of the authored tree; it does not create nesting.
- GraphQL and frontend rendering are bounded to four levels.

### Footer Global

Keep a dedicated global for footer/contact data.

Handle:

```txt
footer
```

Fields:

```txt
companyName
address
email
phone
socialLinks
links
footerNavigation
```

This replaces the old German `kontakt` global.

Footer/legal links use the same reusable `links` Matrix field. There is no
separate legal link model.

`footerNavigation` is optional and points to a navigation definition from the
`navigations` section. This lets the footer use a custom navigation without
hardcoding it in Next.

### Legal Global

Keep a minimal global for site-wide legal UX copy.

Handle:

```txt
legal
```

Fields:

```txt
cookieTitle
cookieText
cookieConsentLabel
cookieDeclineLabel
cookieAcceptLabel
cookieRetentionDays
links
```

Purpose:

- Provide cookie consent banner/modal copy.
- Keep privacy/legal/cookie links configurable without hardcoding them in Next.
- Let Next decide the consent storage expiration.

Rules:

- `cookieText` uses `richText`.
- `cookieRetentionDays` is optional.
- Empty `cookieRetentionDays` means no explicit custom expiration.
- `links` uses the reusable `links` Matrix.

### SEO Global

Keep a dedicated global for site-wide metadata defaults and root metadata
assets.

Handle:

```txt
seo
```

Fields:

```txt
siteName
siteDescription
defaultSeoTitle
defaultSeoDescription
defaultSeoImage
defaultOgTitle
defaultOgDescription
defaultOgImage
llmsText
faviconSvg
favicon96
appleTouchIcon
webAppManifest192
webAppManifest512
```

Purpose:

- Provide fallback metadata when a page or news entry does not define its own
  SEO fields.
- Provide the icon assets required by Next metadata and manifest generation.
- Provide the Markdown/plain text source for `/llms.txt`.

Rules:

- Page/news SEO fields win over SEO global defaults.
- SEO global defaults are used when entry-specific fields are empty.
- If both are empty, Next may fall back to the entry title and site name.
- `llmsText` should be stored as plain multiline text so Next can return it as
  `text/plain` at `/llms.txt`.
- SEO text fields must enforce character limits in Craft. True word-count
  validation is not a native Craft plain-text field feature, so word counts are
  documented as authoring guidance.

Limits:

```txt
siteName: 70 characters, about 4-8 words
siteDescription: 160 characters, about 18-25 words
defaultSeoTitle: 60 characters, about 6-10 words
defaultSeoDescription: 160 characters, about 18-25 words
defaultOgTitle: 95 characters, about 10-16 words
defaultOgDescription: 200 characters, about 25-35 words
```

### Entry SEO Tab

Every public entry type that can render a URL must have an `SEO` tab.

Affected entry types:

```txt
page
legalPage
news
```

Fields:

```txt
seoTitle
seoDescription
seoImage
ogTitle
ogDescription
ogImage
noIndex
noFollow
```

Rules:

- Entry SEO fields are optional.
- Empty entry SEO fields fall back to the `seo` global.
- `seoImage` is the generic metadata image.
- `ogImage` is the Open Graph-specific override.
- `noIndex` and `noFollow` are explicit per-entry crawl controls.
- SEO text fields must enforce the same limits as the global defaults:
  `seoTitle` 60 characters, `seoDescription` 160 characters, `ogTitle` 95
  characters, and `ogDescription` 200 characters.

### Reusable Link Field

Use one reusable Matrix field for buttons and text links.

Handle:

```txt
links
```

Entry type:

```txt
link
```

Fields:

```txt
title
isExternal
linkTarget
pageLink
externalUrl
```

Options:

```txt
linkTarget: self | blank
```

Rules:

- `title` is the visible link or button text.
- `isExternal = false` uses `pageLink`.
- `isExternal = true` uses `externalUrl`.
- `pageLink` must allow internal pages and future entry channels such as news.
- `linkTarget` defaults to `self`.
- Link presentation is controlled by the frontend section/component, not by
  CMS link data.

## Section Composer

Create one composer field:

```txt
sections
```

The field must be available on the `page` entry type.

Each section block shares:

```txt
customSpacing
spaceTop
spaceBottom
```

Allowed values:

```txt
0
1
2
3
```

These values are design tokens, not raw CSS units. Next maps them to spacing
classes or component props.

Spacing rules:

- `customSpacing` defaults to false.
- If `customSpacing` is false, Next ignores `spaceTop` and `spaceBottom`.
- If `customSpacing` is true, Next applies the selected spacing tokens.

### Hero

Handle:

```txt
sectionHero
```

Fields:

```txt
title
subtitle
image
spaceTop
spaceBottom
```

Required:

- `title`
- `image`

### About

Handle:

```txt
sectionAbout
```

Fields:

```txt
variant
image
title
text
links
spaceTop
spaceBottom
```

Variant options:

```txt
left
right
full
```

Required:

- `variant`
- `title`
- `text`

Links:

- Optional.
- Uses the reusable `links` Matrix field.

### CTA

Handle:

```txt
sectionCta
```

Fields:

```txt
title
text
links
spaceTop
spaceBottom
```

Required:

- `title`

Links:

- Optional.
- Uses the reusable `links` Matrix field.

### Content

Handle:

```txt
sectionContent
```

Fields:

```txt
title
richText
links
customSpacing
spaceTop
spaceBottom
```

Required:

- `richText`

Purpose:

- Basic reusable rich-text section.
- Use this for normal editorial content that does not need a custom layout.

### Contact

Handle:

```txt
sectionContact
```

Fields:

```txt
title
text
form
spaceTop
spaceBottom
```

Required:

- `title`
- `form`

Form:

- Must use Freeform's native form picker field.
- Authors select an existing Freeform form directly in the Contact section.
- GraphQL exposes the selected form through Freeform's form interface.

### News

Handle:

```txt
sectionNews
```

Fields:

```txt
title
variant
limit
orderBy
selectedNews
spaceTop
spaceBottom
```

Variant options:

```txt
slider
grid
```

Order options:

```txt
newest
oldest
manual
```

Rules:

- If `selectedNews` has entries, render those entries.
- If `selectedNews` is empty, query `news` entries using `limit` and `orderBy`.
- If `orderBy = manual`, `selectedNews` should be required in the UI or
  treated as `newest` by Next.

## Cleanup Scope

Cleanup is phase 1 and must happen before creating the section composer.

Remove or replace old model elements that are not part of the headless starter.

Candidate cleanup list from current project config:

```txt
home
examplePage
frameworqButtons
frameworqInhalt
impressum
datenschutz
veranstaltungen
neuigkeiten
navigation global
kontakt global
German fields such as inhalt, heroTitel, heroBild, einleitungstext, kontaktAdresse
old Matrix entry types such as Trennzeichen, Inhalt, Channel, Accordion, Tabs
```

Keep or rebuild:

```txt
uploads volume
image transforms if still useful
ckeditor config
freeform plugin
graphql schema
pages structure, after converting it to the new entry type and fields
```

Risk:

- Deleting fields and entry types can destroy existing starter DB content.
- This is acceptable for the new starter only if the DB is considered disposable
  seed data.
- Before cleanup, export or preserve a DB dump if any content should survive.

Gate:

- After cleanup, stop.
- The user must verify Craft admin and approve continuing.
- Do not create `sectionHero` or any other new section before this approval.

## GraphQL Schema

Create or update a public frontend schema.

Required query permissions:

```txt
Query entries in Pages
Query entries in News
Query assets in Uploads
Query global set Footer
Query global set Legal
Query global set SEO
Query navigation model
View Freeform form used by Contact section
```

Required mutation permissions:

```txt
Create Freeform submissions for the Contact form
```

Do not enable broad mutations for entries, assets, users, or globals in the
public frontend schema.

After GraphQL schema changes:

```bash
bun gql
```

## Next Implementation Contract

Replace page-specific hardcoded section rendering with a generic router.

Page query must fetch:

```txt
entry by URI
title
uri
sections
section __typename
section fields per block type
```

Section router behavior:

```txt
sectionHero -> SectionHero
sectionAbout -> SectionAbout
sectionCta -> SectionCta
sectionContact -> SectionContact
sectionNews -> SectionNews
unknown section -> null or development placeholder
```

Navigation query:

```txt
navigation by handle, usually main
```

Footer/site query:

```txt
footer
legal
seo
```

SEO behavior:

- Query the `seo` global once per request or through a shared metadata helper.
- Entry-specific SEO fields override the `seo` global.
- `/llms.txt` renders from `seo.llmsText`.
- Next manifest and icon metadata render from the SEO icon asset fields.

News section behavior:

- The page query may fetch `selectedNews` directly.
- If the section has no selected entries, Next must issue a news query based on
  `limit` and `orderBy`.

Form section behavior:

- Query Freeform form layout for rendering.
- Submit through Freeform GraphQL mutation.
- Handle server validation errors without page reload.

## Implementation Plan

### Phase 1: Hard Cleanup

1. Create a backup DB dump before destructive cleanup.
2. Remove old sections, globals, fields, entry types, and category groups that
   are not part of the headless starter.
3. Keep infrastructure only:
   `uploads`, image transforms, CKEditor config, Freeform, DDEV/env workflow,
   and a minimal GraphQL schema shell.
4. Export/rebuild project config.
5. Run Craft validation commands.
6. Stop for user verification.

Approval gate:

```txt
User verifies cleanup in Craft admin.
Only after approval, proceed to Phase 2.
```

### Phase 2: Hero Section Pilot

1. Create the minimal English `pages` model if it was removed/rebuilt during
   cleanup.
2. Create the `sections` Matrix field.
3. Create only the `sectionHero` entry type.
4. Attach `sectionHero` to `sections`.
5. Attach `sections` to `pages.page`.
6. Configure GraphQL for `pages`, `sectionHero`, and assets.
7. Regenerate GraphQL types.
8. Wire the minimal Next `SectionHero` query/render path.
9. Stop for user verification.

Approval gate:

```txt
User verifies that Hero can be authored in Craft and rendered by Next.
Only after approval, proceed to Phase 3.
```

### Phase 3: Remaining Base Sections

After Hero is approved, add the remaining sections:

```txt
sectionAbout
sectionCta
sectionContact
sectionNews
```

Then add:

```txt
news
navigations
footer
```

Finish with GraphQL type generation, Next section router updates, validation,
and manual checks.

## Validation

Craft validation:

```bash
cd craft
ddev exec php craft project-config/apply --dry-run
ddev exec php craft install/check
```

GraphQL validation:

```bash
bun gql
```

Frontend validation:

```bash
bun run lint
bun run build
```

Manual checks:

```txt
Craft admin opens
Pages entry can add Hero, About, CTA, Contact, News sections
Next home page renders sections from GraphQL
Navigation renders from the navigation builder
Contact form can be queried through GraphQL
SEO global can be queried through GraphQL
Page/news SEO tab fields can be queried through GraphQL
```

## Open Decisions

- Use Matrix field for `sections`.
- Use a dedicated `navigations` section/channel for multiple navigation
  definitions.
- Use Freeform's native form picker field for Contact sections.
- Decide whether legal pages are ordinary `pages` entries or separate system
  pages.
