# Craft License Env Spec

## Goal

Use one Craft license source across local and production:

```txt
CRAFT_LICENSE_KEY
```

The license must not be read from `craft/config/license.key`.

## Behavior

- `CRAFT_LICENSE_KEY` is optional.
- Paid projects set the quoted key directly in the private root `.env`.
- Local DDEV receives the value through its generated environment.
- `craft:push` writes the configured `CRAFT_LICENSE_KEY` to the hosted Craft
  `.env` as part of the explicitly confirmed full Craft synchronization.
- `craft:push:env` also propagates the configured value without synchronizing
  source, database content, or assets.

## Non-Goals

- Do not store license files in `craft/config`.
- Do not print license values in logs.
- Do not add a dedicated license command.
- Do not change Craft project config.
- Do not add dependencies.
