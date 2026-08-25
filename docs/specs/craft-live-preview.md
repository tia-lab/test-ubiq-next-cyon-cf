# Craft Live Preview

Status: implemented

Scope: Craft preview targets and the Next preview boundary

## Goal

Allow authors to preview draft, unpublished, and live edits through the normal
Next templates without exposing the GraphQL bearer token or polluting published
published OpenNext/Cloudflare caches.

Craft owns preview-token generation. Next owns Draft Mode, token transport,
preview rendering, and cache bypass.

## Environment

```txt
NEXT_PUBLIC_SITE_URL
CRAFT_PREVIEW_SECRET
```

`NEXT_PUBLIC_SITE_URL` is the exact Worker origin used by Craft preview
targets and canonical metadata. It must be set to the workers.dev or custom
production origin; no hosting-provider system URL fallback is used.

`CRAFT_PREVIEW_SECRET` is optional hardening for `/api/preview`. When set, the
Craft preview target must send the same value through `secret`.

The Craft GraphQL bearer token remains server-only and is separate from Craft's
temporary preview token.

## Preview Targets

Local entry target:

```txt
http://localhost:3000/api/preview?uri={uri}&token={token}
```

Local homepage target:

```txt
http://localhost:3000/api/preview?uri=home&token={token}
```

Production target with optional hardening:

```txt
https://<next-domain>/api/preview?uri={uri}&token={token}&secret=<shared-secret>
```

Omit `secret` when `CRAFT_PREVIEW_SECRET` is empty.

## Request Flow

### Preview entry

1. Craft requests `/api/preview` with `uri` and its temporary preview token.
2. Next validates the optional shared secret and requires the Craft token.
3. Next normalizes the Craft URI.
4. Next enables Draft Mode.
5. Next stores the Craft token in an HTTP-only cookie.
6. Next redirects to the public frontend path and forwards the temporary token
   as `x-craft-preview-token`.
7. The request renders through the preview GraphQL client and
   `TemplateRouter`.

### Cookie path

When the browser accepts the Draft Mode and Craft token cookies, the public
route calls:

```txt
getRequestEntryByUri()
```

That wrapper detects Draft Mode, reads the HTTP-only Craft token cookie, and
calls `getPreviewEntryByUri()`.

Without Draft Mode it calls the published `getEntryByUri()` path.

### Query-token fallback

Craft preview can run in a cross-origin iframe where browser cookie policy may
restrict third-party cookies. The `x-craft-preview-token` query value remains
the fallback.

`app/next.config.ts` applies a conditional `beforeFiles` rewrite:

```txt
public URL + x-craft-preview-token
  -> /craft-preview/[[...slug]]
```

The rewrite is internal. The browser continues to show the public entry URL.
The public homepage, catch-all page, and news-detail routes never read
`searchParams`.

The reserved internal renderer lives at:

```txt
app/src/app/craft-preview/[[...slug]]/page.tsx
```

It:

- maps an empty slug to Craft URI `home`
- requires the explicit preview token
- calls `getPreviewEntryByUri()` directly
- generates entry-aware preview metadata
- renders through `TemplateRouter`
- returns 404 when the token or entry is missing

`craft-preview` is a reserved route prefix and must not be used as a public
Craft page URI.

## Cache Contract

Published queries:

```txt
craftQuery()
next: { revalidate: false, tags: [...] }
```

Preview queries:

```txt
craftPreviewQuery()
cache: "no-store"
```

Preview requests:

- do not attach cache tags
- do not write draft content into the published Data Cache
- do not depend on `/api/revalidate`
- remain dynamic and private on the Cloudflare Worker

Next Draft Mode bypasses the published ISR response when its Draft Mode
cookie is valid. The explicit token fallback is routed to the dynamic internal
preview renderer before the public ISR route is selected.

## GraphQL Transport

The preview GraphQL request keeps:

- the normal server-side bearer token
- the temporary Craft token query parameter
- the `X-Craft-Token` header
- `cache: "no-store"`

The shared Craft client serializes GraphQL requests because the current Cyon
endpoint returns HTTP 415 when different full GraphQL POST bodies arrive
concurrently. Serialization changes transport timing only; it does not change
preview cache policy.

## Exit

```txt
/api/preview/exit
```

The exit route:

- disables Draft Mode
- deletes the Craft preview-token cookie
- redirects to `/`

## Security

- Never expose `CRAFT_GRAPHQL_TOKEN` to browser code.
- Never explicitly log the temporary preview token.
- Reject a missing preview token.
- Reject an invalid `secret` when `CRAFT_PREVIEW_SECRET` is configured.
- Keep the preview token cookie HTTP-only.
- Keep preview GraphQL responses out of persistent caches.
- Treat query-token URLs as sensitive because infrastructure request logs may
  record query parameters.

## Validation

Run:

```sh
bun run lint
bun run build
```

Build acceptance:

```txt
/craft-preview/[[...slug]]   Dynamic
```

Local browser acceptance:

1. Open an existing entry from Craft Preview.
2. Change content without publishing.
3. Confirm the Next preview shows the draft.
4. Confirm the browser URL remains the public entry URL.
5. Confirm preview GraphQL is `no-store`.
6. Confirm the public route outside Draft Mode still shows published content.
7. Exit preview and confirm published rendering returns.
8. Block/remove the preview cookies and confirm the explicit token-query
   fallback still renders the draft.

Production acceptance:

- preview content is fresh
- response cache policy is private/no-store
- the published route remains cacheable without preview state
- preview data never appears in a later published response

## Rollback

Rollback is limited to Next:

1. remove the conditional preview rewrite
2. remove the internal `craft-preview` route
3. restore explicit preview-token reads in the public routes
4. keep the existing Draft Mode API routes and preview GraphQL client

No Craft database or content rollback is required.
