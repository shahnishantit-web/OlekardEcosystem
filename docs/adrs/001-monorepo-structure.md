# ADR-001: Monorepo Structure

**Status**: Accepted

**Date**: 2026-08-16

**Deciders**: Platform Team

## Context

The OleKard ecosystem comprises multiple deployable units: a Laravel API backend, a Next.js admin web app, a Flutter customer mobile app, and a Flutter agent mobile app. These share design tokens, API contracts, and business logic types. A decision was needed on whether to manage these as separate repositories or a single monorepo.

## Decision

Use a single monorepo at the root `OleKardEcosystem/` directory with the following structure:

```
OleKardEcosystem/
├── wallet-ecosystem/
│   └── services/
│       └── api/          # Laravel API (PHP)
├── apps/
│   ├── admin/            # Next.js admin (TypeScript)
│   ├── customer/         # Flutter customer app (Dart)
│   └── agent/            # Flutter agent app (Dart)
├── packages/
│   └── design-system/    # Shared design tokens (W3C format)
├── docs/
│   └── adrs/             # Architecture Decision Records
└── scripts/
    └── dev/              # Developer workflow scripts
```

Module boundaries within the API are enforced at the PHP namespace level (`App\Modules\*`) with schema-per-module in PostgreSQL.

## Consequences

### Positive

- Single source of truth for API contracts and design tokens
- Atomic cross-platform changes (e.g., a new API field and its Flutter consumer) in one commit
- Shared CI/CD configuration and tooling
- Easier contract testing between API and consumers

### Negative

- Repository checkout size grows as all platforms are cloned together
- CI pipeline must selectively run platform-specific jobs (change detection required)
- Developers working on a single platform still pull the entire repo

### Neutral

- Each app retains its own build toolchain (`composer`, `npm`, `flutter`)
- Module boundaries are logical, not physical — discipline required to avoid coupling

## Alternatives Considered

| Alternative | Reason rejected |
|-------------|-----------------|
| Separate repositories per service | Harder to keep API contracts and consumers in sync; cross-repo PRs are operationally complex |
| Git submodules for shared packages | Fragile developer experience; submodule state divergence common in practice |
| Polyrepo with a shared npm package for tokens | Adds a publishing step for every design-token change; slows the token → component cycle |
