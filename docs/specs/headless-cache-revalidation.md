# Headless Cache Revalidation

Status: implemented

Scope: Craft change events, Next Data Cache tags, and OpenNext/Cloudflare ISR

## Goal

Invalidate published frontend content deterministically when Craft entries,
globals, navigation, sections, or assets change.

The system uses:

- query-owned Next cache tags
- Craft element lifecycle events
- a signed `/api/revalidate` callback
- OpenNext ISR backed by R2 and D1 for complete page responses

It intentionally does not use Redis, a Durable Object queue, zone cache purge,
or a custom dependency graph.

## Ownership

Craft owns:

- content mutation events
- tag generation for changed elements
- the revalidation POST

Next owns:

- tag normalization
- tagged GraphQL requests
- the revalidation route
- Data Cache invalidation
- dependent ISR regeneration

Components and templates do not invent cache tags.

## Environment

```txt
REVALIDATE_SECRET
CRAFT_REVALIDATE_URL
```

Craft and Next use the same `REVALIDATE_SECRET`.

Craft sends events to:

```txt
https://<next-domain>/api/revalidate
```

Hosted revalidation is inactive when the callback URL or secret is empty.
Local DDEV derives its callback from `DEV_PORT`:

```txt
http://host.docker.internal:<DEV_PORT>/api/revalidate
```

This keeps the local Craft container aligned with the host Next dev server
without putting a Docker-only URL in the hosted environment contract.

## Published Query Contract

The GraphQL client lives at:

```txt
app/src/lib/craft/client.ts
```

Published fetches use:

```ts
next: {
  revalidate: false,
  tags: normalizeCraftCacheTags(tags),
}
```

Query wrappers under:

```txt
app/src/lib/craft/queries/
```

own the tags for their data.

Current examples:

| Wrapper | Tags |
| --- | --- |
| `getEntryByUri` | `craft`, `craft:entries`, `craft:entry-uri:{uri}` |
| homepage entry | previous tags plus `craft:pages`, `craft:page-uri:home` |
| `getGlobals` | `craft`, `craft:globals`, global-handle tags |
| `getNavigation` | `craft`, `craft:navigation`, `craft:navigation:{handle}` |
| `getNews` | `craft`, `craft:entries`, `craft:news`, `craft:section:news` |

Tags are:

- trimmed
- lowercase
- URL-safe
- deduplicated
- limited to 256 characters each
- limited to 128 per request

## Craft Event Contract

The Craft module lives under:

```txt
craft/modules/headless/
```

It listens to element save/delete events and ignores drafts, revisions, and
unsupported element types.

Current emitted tags include:

| Element change | Tags |
| --- | --- |
| supported element | `craft` |
| entry | entry id, URI, section, and type tags |
| page | page id and URI tags |
| news | news id and URI tags |
| navigation | navigation handle tags |
| nested section | section-entry and owner tags |
| global | global handle tags |
| asset | asset id, volume, and broad entry tags |

Craft save/delete must still complete if Next is unavailable. Revalidation
failures are warnings and must not throw into the authoring request.

## Revalidation API

Endpoint:

```txt
POST /api/revalidate
```

Payload:

```json
{
  "secret": "shared-secret",
  "tags": ["craft", "craft:entry-uri:about"]
}
```

The route:

- requires configured `REVALIDATE_SECRET`
- rejects invalid JSON
- rejects an invalid secret
- normalizes and deduplicates tags
- rejects an empty tag list
- rejects more than 128 tags
- never echoes or logs the secret
- calls `revalidateTag(tag, "max")`

Successful response:

```json
{
  "revalidated": true,
  "tags": ["craft", "craft:entry-uri:about"],
  "now": "2026-08-03T00:00:00.000Z"
}
```

## Cache Layers

### GraphQL Data Cache

This stores the tagged responses returned by published Craft GraphQL queries.
Next development logging reports this layer as fetch `cache hit` or
`cache skip`.

### Full-route and CDN cache

This stores rendered HTML and RSC output.

Current route classification:

```txt
/                                  Static
/news                              Static
/[...slug]                         SSG
/news/[slug]                       SSG
/craft-preview/[[...slug]]         Dynamic
```

The two parameterized public routes return an empty
`generateStaticParams()` list. Valid Craft URIs are generated on first access
and then participate in ISR.

A GraphQL Data Cache hit does not prove that the complete page response is a
regional Worker cache hit. Only deployed response evidence can prove the latter.

### OpenNext cache ownership

`NEXT_INC_CACHE_R2_BUCKET` persists incremental/data cache entries.
`NEXT_TAG_CACHE_D1` persists the Next-mode `revalidations` table used by
on-demand tag invalidation. The long-lived regional cache keeps
`bypassTagCacheOnCacheHit: false`, so correctness does not depend on
Cloudflare zone purge. This repository has no time-based revalidation and does
not configure a Durable Object queue.

### Revalidation behavior

`revalidateTag(tag, "max")` uses stale-while-revalidate semantics:

1. Craft marks matching data stale.
2. A later request may receive the stale response while regeneration starts.
3. Next refreshes the tagged data and dependent route output.
4. Later requests receive the fresh cached response.

Therefore the first post-event response can be `STALE`; an immediate `MISS` is
not required.

Route Handler tag invalidation does not immediately clear a browser's existing
client Router Cache. Validate server/CDN state with a clean request and use a
hard navigation when checking a browser session.

## Preview Boundary

Preview does not use published tags:

```txt
craftPreviewQuery()
cache: "no-store"
```

Draft Mode and explicit token-query preview requests remain dynamic. Draft
content never enters the published Data Cache or ISR output.

## Hosted Craft Transport

Run evidence against the current Cyon endpoint:

- each exact application GraphQL document succeeds sequentially
- concurrent identical small probes can succeed
- concurrent different full application GraphQL POST bodies return
  `415 Unsupported Media Type`
- a serialized request sequence succeeds

The application therefore uses two bounded controls:

- `app/next.config.ts` sets one static-generation worker
- `app/src/lib/craft/client.ts` serializes published and preview GraphQL
  transport through a Node process-wide queue

These controls do not change query documents, tags, cache lifetimes, or preview
policy.

## Failure Behavior

If Craft cannot reach Next:

- the Craft save/delete succeeds
- Craft logs a warning
- published frontend data remains stale until a later successful event,
  redeployment, or manual broad revalidation

If Next cannot reach Craft during regeneration:

- the build or regeneration reports the exact GraphQL failure
- stale-while-revalidate may continue serving the last successful cached value
- the failure must not be hidden by disabling TLS verification

Manual broad recovery payload:

```json
{
  "secret": "shared-secret",
  "tags": ["craft"]
}
```

## Validation

Static validation:

```sh
bun run lint
bun run build
```

The build must complete GraphQL generation and report the route
classifications listed above.

Production cache validation:

```sh
curl -sS -D - -o /dev/null https://<next-domain>/sections
curl -sS -D - -o /dev/null https://<next-domain>/sections
```

Run without Draft Mode cookies or request `cache-control: no-cache`.

Record:

- `cache-control`
- cache and age response headers exposed by the Worker
- rendered content
- observed Worker endpoint

Expected normal sequence after the regional cache warms:

```txt
MISS -> HIT
```

Revalidation validation:

1. Publish one controlled Craft change.
2. Confirm `/api/revalidate` accepts the expected tags.
3. Request the affected public route.
4. Allow `STALE` during background regeneration.
5. Confirm a later `HIT` contains the published change.
6. Confirm preview remains independently fresh and no-store.

## Rollback

The cache boundary can be rolled back without changing Craft content:

1. restore dynamic public route behavior
2. remove on-demand static generation exports
3. remove the internal preview rewrite and route
4. keep the existing query tags and `/api/revalidate` contract

No database, asset, or Craft project-config rollback is required.
