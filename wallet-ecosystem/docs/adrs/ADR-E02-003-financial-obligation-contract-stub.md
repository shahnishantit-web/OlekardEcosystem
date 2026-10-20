# ADR-E02-003: FinancialObligationContract Fail-Safe Stub Pattern

**Status**: Accepted  
**Date**: 2026-08-27  
**Epic**: E02 — User & Profile Management

---

## Context

Account closure must verify that a user has no outstanding financial obligations (wallet balance, pending transactions, active loans, open disputes) before transitioning to `closed`. E06 (Wallet Management) and E11 (Transaction Management) are not yet implemented and cannot provide a real obligation check.

## Decision

Introduce `FinancialObligationContract` interface with a single method:
- `checkObligations(string $userId): ObligationCheckResult`

`StubFinancialObligationService` implements the contract and always returns:
```
hasObligations: true, blockedBy: ['obligations_check_unavailable']
```

This is a **fail-safe** stub: when the gate is not wired, all closure attempts are blocked. This prevents accidental account closure in non-production environments where E06/E11 are absent.

## Rationale

- Fail-safe over fail-open: blocking closure is safer than allowing it when the check is unavailable
- Zero coupling: E02 depends on the contract, not E06/E11 internals
- Extraction-ready: when E06/E11 ship, they inject their concrete implementation

## Consequences

- All closure requests return `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` with `blocked_by: ['obligations_check_unavailable']` until E06/E11 provide real implementations
- E2E tests that need the gate to pass must mock `FinancialObligationContract` to return `hasObligations: false`
