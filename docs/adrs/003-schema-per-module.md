# ADR-003: PostgreSQL Schema-Per-Module Isolation

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Platform Team

## Context

The Wallet Ecosystem backend requires strong domain isolation between modules (Identity, Wallet, Ledger, KYC, Transaction, Risk, Agent, Notification, Platform). Two viable options were considered:

1. **Separate database per module** — true physical isolation but requires multiple DB connections, connection pool overhead, and cross-database transaction complexity.
2. **Schema-per-module in a single database** — logical isolation within one PostgreSQL instance; extraction to separate databases is a configuration-only operation when needed.

The system must be extraction-ready (each module can become its own service later) while minimising operational overhead during Platform Foundation.

## Decision

Adopt **PostgreSQL schema-per-module**: each domain module uses a dedicated PostgreSQL schema (`identity.*`, `wallet.*`, `ledger.*`, `kyc.*`, `transaction.*`, `risk.*`, `agent.*`, `notification.*`, `platform.*`) within a single PostgreSQL database instance.

Each module has exactly one named Laravel database connection (see ADR-002 for connection naming convention). Cross-module data access is forbidden at the database level; all cross-module communication goes through PHP interface contracts.

## Consequences

### Positive

- Extraction-ready: changing a module to a separate database requires only changing the connection host environment variable — no code changes
- No cross-schema JOINs enforced by architecture tests (`InfrastructureIsolationTest`)
- Single DB instance simplifies local development (one Docker container)
- Schema-level visibility control available via PostgreSQL `GRANT` per schema

### Negative

- Cross-module queries that would be a single JOIN must instead become multiple application-level calls through contracts
- Migrations for each module must target the correct schema connection; misconfiguration can silently write to the wrong schema

### Neutral

- Laravel's named connections provide the abstraction layer: `DB::connection('wallet')->table('wallet.wallets')`

## Alternatives Considered

| Alternative | Reason Rejected |
|-------------|-----------------|
| Separate database per module from the start | Operational overhead too high for Platform Foundation; cross-module transactions not possible without distributed transaction protocol |
| Single schema for all modules | No isolation boundary; impossible to extract modules later without schema migration |
