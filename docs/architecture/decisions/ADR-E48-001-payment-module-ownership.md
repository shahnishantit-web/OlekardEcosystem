# ADR-E48-001: Shared Payment Module Ownership and Boundaries

**Status**: Accepted

**Date**: 2026-10-18

**Epic**: E48 — Shared Payment Integration and Orchestration

---

## Context

OleKard is expanding into horizontal business modules (funeral contributions, event ticketing, raffles, fundraising). Each requires a payment flow. Without a shared module, every horizontal module would independently build provider integrations, callback handling, refund logic, and reconciliation — creating fragmentation and financial risk.

## Decision

Create `App\Modules\Payment` as the sole authoritative module for cross-module payment orchestration. No other module may own the PaymentIntent lifecycle, provider selection, attempt state, or refund orchestration.

**Boundaries:**

- `App\Modules\Payment` owns: `PaymentIntent`, `PaymentAttempt`, `PaymentRefund`, `PaymentProvider`, `PaymentProviderAccount`, `PaymentCallback`, `ReconciliationException`.
- Business modules (funeral, ticketing, raffle, etc.) call `PaymentModuleContract` — the ONLY interface they may depend on.
- Provider adapters live in `App\Modules\Payment\Infrastructure\Adapters\` — the orchestration layer never calls provider HTTP APIs directly.
- `App\Modules\Payment` MUST NOT duplicate wallet, ledger, or funding domain logic.

## Consequences

- New horizontal modules get payment capability with zero provider-specific code.
- Adding a new provider requires one adapter class and one admin config record — no business module changes.
- `PaymentModuleContract` is a stable, versionable boundary.
- Future extraction of the Payment module to a separate service requires only changing the contract binding — no consuming code changes.
