# ADR-002: Schema-Per-Module PostgreSQL Architecture

**Status**: Accepted
**Date**: 2026-08-01

## Context

The Wallet Ecosystem requires strict data isolation between business modules (Identity, Wallet, Ledger, KYC, Transaction, Risk, Agent, Notification) to support future extraction into independent services and to enforce clear ownership of data.

## Decision

Each module owns exactly one PostgreSQL schema. Module-specific tables live exclusively within that module's schema. No cross-schema `FOREIGN KEY` constraints are permitted. No cross-schema SQL `JOIN` operations are permitted in application code.

Cross-module data access is achieved via:
1. **Module Contract interfaces** (synchronous, within the same process)
2. **Integration Events over the Outbox** (asynchronous, at-least-once delivery)

## Consequences

**Positive**:
- Module can be extracted into a separate service by changing only the infrastructure wiring (DB host, connection string).
- Domain ownership is explicit and auditable.
- CI gate (`php artisan platform:check-scope`) detects violations automatically.

**Negative**:
- Queries that would be simple JOINs in a shared schema require cross-module calls or event-driven projections.
- Seeding test fixtures across modules requires separate connections.

## Alternatives Considered

- **Single shared schema**: Rejected — prevents future extraction and makes ownership boundaries ambiguous.
- **Table prefix per module**: Rejected — still allows accidental cross-module JOINs with no enforcement mechanism.
