# ADR-002: Database Connection Naming Convention

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Platform Team

## Context

FR-186 was initially drafted requiring named Laravel database connections to follow the `{module}_db` suffix pattern (e.g., `platform_db`, `identity_db`). However, T010 implemented connections using the short module name without the `_db` suffix (e.g., `platform`, `identity`, `wallet`). By the time this discrepancy was identified (T144 convergence task), every repository, service, migration, and test in the codebase used the short-name convention across 50+ files.

## Decision

Adopt the **short module name** (no `_db` suffix) as the canonical connection naming convention, matching what T010 established and what the entire codebase already uses. Update FR-186 to reflect this.

The connection names are:

| Module | Connection Name |
|--------|----------------|
| Platform | `platform` |
| Identity | `identity` |
| Wallet | `wallet` |
| Ledger | `ledger` |
| KYC | `kyc` |
| Transaction | `transaction` |
| Risk | `risk` |
| Agent | `agent` |
| Notification | `notification` |

## Consequences

### Positive

- Zero code changes required — all existing repositories and tests already use this convention
- Shorter, readable connection strings (`DB::connection('wallet')` vs `DB::connection('wallet_db')`)
- Consistent with the module namespace and schema name (all three use the same word)

### Negative

- Breaks from the initial FR-186 draft intent — future readers must check this ADR to understand the history
- If a future service is named `wallet` (not `wallet_db`), there's no visual distinction between "this is a named Laravel connection" and "this is something else" — the `_db` suffix would have provided that disambiguation

### Neutral

- FR-186 was updated in spec.md to reflect the short-name convention

## Alternatives Considered

| Alternative | Reason rejected |
|-------------|-----------------|
| Rename all 9 connections to `{module}_db` | Required touching 50+ files across repositories, services, tests, and factories; high risk of missing one; no functional benefit |
| Introduce `_db` suffix only for new modules | Inconsistency within the same codebase — half modules would have the suffix and half would not |
