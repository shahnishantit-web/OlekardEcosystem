# Wallet Ecosystem

Modular monolith backend + web + mobile platform for a wallet ecosystem.

## Quick Start

See [DEVELOPMENT.md](DEVELOPMENT.md) for onboarding instructions.

```bash
docker compose up -d --wait
```

## Architecture

See [specs/999-platform-foundation/](../specs/999-platform-foundation/) for the Platform Foundation specification and implementation plan.

## Structure

```
apps/           # Applications
  admin/        # Next.js 14 App Router (Admin/Enterprise)
  customer/     # Flutter Customer App
  agent/        # Flutter Agent App
packages/       # Shared packages
  design-system/  # Token-driven design system
  shared-utils/   # Cross-platform utilities
services/       # Backend services
  api/          # Laravel 11 API
docs/           # Architecture, ADRs, runbooks
scripts/        # Dev and CI scripts
```

## Technology Stack

- **Backend**: Laravel 11 / PHP 8.4+ / PostgreSQL 15 / Redis 7
- **Web Admin**: Next.js 14 / TypeScript
- **Mobile**: Flutter (Riverpod)
- **Architecture**: Modular monolith, schema-per-module, extraction-ready
