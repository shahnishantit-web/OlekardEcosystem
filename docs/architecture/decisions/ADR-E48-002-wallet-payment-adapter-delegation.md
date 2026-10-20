# ADR-E48-002: Wallet Payment Adapter Delegation Pattern

**Status**: Accepted

**Date**: 2026-10-18

**Epic**: E48 — Shared Payment Integration and Orchestration

---

## Context

OleKard Wallet must be exposed as a payment provider option through the shared Payment module. The wallet domain (E06/E07) already owns balance calculation, holds, reservations, and ledger postings via `WalletModuleContract` and `LedgerModuleContract`.

## Decision

`WalletPaymentAdapter` implements `PaymentAdapterInterface` and delegates ALL wallet operations to the existing `WalletModuleContract` and `LedgerModuleContract` interfaces. It does not introduce any wallet or ledger domain logic of its own.

**Constraints:**

- `WalletPaymentAdapter` MUST call `WalletModuleContract::createHold()` for balance reservation — never manipulate wallet balance directly.
- All ledger postings MUST go through `LedgerModuleContract::postEntry()` — no direct writes to ledger tables.
- `WalletModuleContract` MUST NOT be modified by E48. If a new method is required, it must be an additive extension with its own ADR.
- `WalletPaymentAdapter` is the ONLY place in `App\Modules\Payment` that calls `WalletModuleContract` or `LedgerModuleContract`.

## Consequences

- OleKard Wallet payments use the same, already-tested hold and ledger mechanisms as transfers and withdrawals.
- There is exactly one source of financial truth for wallet balances (the Wallet/Ledger domain).
- No risk of wallet balance duplication or inconsistency from the Payment module.
- The wallet adapter is synchronous (initiation → success in one request) while the Payment module supports both synchronous and asynchronous flows.
