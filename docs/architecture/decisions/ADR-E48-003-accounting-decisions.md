# ADR-E48-003: Payment Accounting Decisions and Open Items

**Status**: Draft — awaiting architect and finance reviewer sign-off

**Date**: 2026-10-21

**Epic**: P01 — Architecture Contracts and Accounting Decisions (Shared Payment Architecture)

**Prerequisite ADRs**: ADR-E48-001 (accepted), ADR-E48-002 (accepted)

---

## Context

The Shared Payment Architecture (E48) requires authoritative accounting decisions before the ledger acknowledgement module (P08) and payout lifecycle (P18) can be implemented. This ADR records all accounting decisions as either CONFIRMED (with specific mapping and evidence) or UNRESOLVED (with a named decision owner and blocking condition).

**Source document**: `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT in the repository. Sections FD05, FD06, FD10 from that document are referenced by the architecture but are unverifiable. Decisions that depend on those sections are recorded as `AWAITING_DOCUMENT` with a named owner.

No confirmed decision in this ADR may contradict ADR-E48-001 or ADR-E48-002.

---

## Confirmed Decisions

### AD-01: Wallet Balance Ownership

**Status**: CONFIRMED

**Decision**: The OleKard Wallet balance is owned exclusively by `App\Modules\Wallet` via `WalletModuleContract`. No module may read or write wallet balances except through this contract.

**Source evidence**:
- ADR-E48-002: `WalletPaymentAdapter` MUST call `WalletModuleContract::createHold()` — never manipulate wallet balance directly.
- `WalletPaymentAdapter.php`: only adapter in `App\Modules\Payment` that imports `WalletModuleContract`.

**Affected P-IDs**: P02, P03, P08

---

### AD-02: Ledger as Single Authoritative Source

**Status**: CONFIRMED

**Decision**: `LedgerModuleContract::postEntry()` is the sole mechanism for recording financial state. No module may maintain an alternative balance store. All reversals use compensating entries — historical journal entries are immutable.

**Source evidence**:
- Constitution Principle 4: "Financial truth is an immutable double-entry ledger."
- `LedgerModuleContract.php`: no `updateEntry()` or `deleteEntry()` method exists.
- ADR-E48-002: "All ledger postings MUST go through `LedgerModuleContract::postEntry()`."

**Affected P-IDs**: P05, P08, P15, P16, P17

---

### AD-03: Wallet Payment Fee is Zero

**Status**: CONFIRMED

**Decision**: OleKard Wallet payments have no transaction fee. `feeMinor = 0` for all wallet-to-wallet and wallet-to-organisation payments.

**Source evidence**:
- `WalletPaymentAdapter.php` line 150: `feeMinor: 0`

**Note**: This applies to the wallet adapter only. Bank payment fees are governed by AD-07 (UNRESOLVED).

**Affected P-IDs**: P08

---

### AD-04: Bank Purchase Does Not Top Up Consumer Wallet

**Status**: CONFIRMED

**Decision**: A bank payment capture does NOT increase the consumer's OleKard Wallet balance. Bank funds flow to OleKard's merchant bank settlement account. The consumer's wallet balance is unchanged by any bank payment.

**Source evidence**:
- Code inspection 2026-10-21: `AbstractBankPaymentAdapter` and `MockBankPaymentAdapter` do not import `WalletModuleContract`.
- Architectural principle: Wallet is a stored-value account; bank payments are a separate instrument.

**Affected P-IDs**: P08, P22, P23

---

### AD-05: Wallet Payment Debit Side

**Status**: PARTIALLY CONFIRMED

**Decision**: For a wallet payment, the debit side of the journal entry is the consumer's wallet pocket ledger account (`WalletModuleContract::getPocketLedgerAccountRef()`). The hold creation debits this account; on confirmation, the hold converts to a posted debit.

**Source evidence**:
- `WalletPaymentAdapter.php`: `createHold()` is called at initiation; ADR-E48-002 confirms hold → ledger debit pattern.
- `WalletModuleContract.php`: `getPocketLedgerAccountRef(walletId, currencyCode)` returns the ledger account reference for the pocket.

**Partially unresolved**: The credit side of this entry (which account receives the credit — see AD-08) is UNRESOLVED.

**Affected P-IDs**: P08

---

### AD-06: Refund Uses Compensating Entries

**Status**: CONFIRMED

**Decision**: Refunds post a new journal entry with opposite debit/credit directions. The original journal entry is NEVER modified. Partial refunds post a compensating entry for the refund amount only.

**Source evidence**:
- Constitution Principle 4: "Reversals use compensating entries."
- `WalletPaymentAdapter.php` comment (line 175): "NO modification to historical ledger entries."

**Affected P-IDs**: P08, P15

---

### AD-07: Dual ProviderCapabilityEnum Pattern

**Status**: CONFIRMED (existence); UNRESOLVED (resolution)

**Decision (confirmed)**: Two `ProviderCapabilityEnum` enums coexist: `App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum` (used by WalletPaymentAdapter: InitiatePayment, QueryTransactionStatus, ReversePriorPayment) and `App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum` (8 cases including Payout). This is the current state of the codebase.

**Decision (unresolved)**: Whether to merge these into one enum or keep them distinct is UNRESOLVED. The architect must decide before P12 (bank adapters).

**Decision owner**: Architect

**Risk if deferred**: Bank adapters (P12–P14) may implement different enums than WalletPaymentAdapter, creating inconsistent capability declarations.

**Blocking condition**: Architect review; no architecture document needed.

**Affected P-IDs**: P12, P13, P14

---

## Unresolved Decisions

### AD-08: Organisation Account on Wallet Payment Credit Side

**Status**: AWAITING_DOCUMENT

**Topic**: When a consumer pays via OleKard Wallet (e.g., funeral contribution), what ledger account receives the credit? Options: (a) org-owned suspense/liability account, (b) org-owned wallet pocket, (c) OleKard pooled escrow account.

**Decision owner**: Finance Lead

**Source reference**: FD10 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: P08 cannot post the credit side of any wallet payment journal entry. All ledger acknowledgements for wallet payments are incomplete.

**Blocking condition**: Architecture document v1.1 added to repository (DEC-07) OR finance lead provides explicit written decision.

**Affected P-IDs**: P08, P24, P25, P26, P27, P28

---

### AD-09: Bank Payment Chart of Accounts

**Status**: AWAITING_DOCUMENT

**Topic**: For a bank payment confirmation, what is the full debit/credit chain? The debit side is the bank settlement receivable account (OleKard's external bank account settlement). The credit side is unknown — same options as AD-08.

**Decision owner**: Finance Lead

**Source reference**: FD05, FD06 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: P08 cannot post bank payment journal entries. Bank payment confirmations will be captured but not acknowledged in the ledger.

**Blocking condition**: Architecture document v1.1 added to repository (DEC-07) OR finance lead written decision.

**Affected P-IDs**: P08, P12, P13, P14

---

### AD-10: Custody Account Type

**Status**: AWAITING_DOCUMENT

**Topic**: For community features (funeral contributions, event proceeds), who holds collected funds between collection and disbursement? What ledger account type is this (liability/suspense/trust)? Who is the legal custodian?

**Decision owner**: Finance Lead + Legal

**Source reference**: FD10 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: Cannot implement payout lifecycle (P18) without knowing which account balance is available to disburse. Regulatory risk if custody is not correctly modelled.

**Blocking condition**: Architecture document v1.1 + legal review of custody obligations.

**Affected P-IDs**: P18, P24, P25, P26, P27, P28

---

### AD-11: Merchant / Organisation Settlement Bank Account

**Status**: AWAITING_DOCUMENT

**Topic**: Does each organisation have its own settlement bank account at the provider, or does OleKard use a pooled merchant account? How does this map to the ledger — one ledger account per organisation, or one shared account with sub-ledger entries?

**Decision owner**: Finance Lead

**Source reference**: FD06 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: Reconciliation (P17) cannot match provider settlement reports to organisation-level ledger accounts.

**Blocking condition**: Architecture document v1.1 OR finance lead written decision + bank contract review.

**Affected P-IDs**: P17, P08

---

### AD-12: Fee Bearer for Bank Payments

**Status**: AWAITING_DOCUMENT

**Topic**: For bank payments (FNB, Standard Bank, Stanbic), who bears the payment processing fee — consumer, organisation, or OleKard? Is the fee added to the consumer's payment amount, deducted from the disbursement, or absorbed by OleKard?

**Decision owner**: Finance Lead + Commercial (for bank agreements)

**Note**: Wallet payments are confirmed at fee=0 (AD-03). This decision applies to bank adapters only.

**Source reference**: FD05 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: Bank adapter fee calculation (P12, P13, P14) will use incorrect or zero fee amounts.

**Blocking condition**: Architecture document v1.1 OR commercial agreement with each bank provider.

**Affected P-IDs**: P08, P12, P13, P14

---

### AD-13: Late-Payment Suspense Account

**Status**: AWAITING_DOCUMENT

**Topic**: If a payment is captured by the provider but `LedgerModuleContract::postEntry()` fails (e.g., ledger service unavailable), what suspense account holds the unacknowledged captured amount? What is the recovery path?

**Decision owner**: Finance Lead + Architect (P08 lead)

**Source reference**: FD05 — `OleKard_Shared_Payment_Architecture.docx v1.1` (not in repository)

**Risk if deferred**: A captured payment with no ledger acknowledgement represents unreconciled funds. Without a defined suspense account, recovery is manual and audit-unverifiable.

**Blocking condition**: Architect proposes suspense account design for finance lead approval; no architecture document strictly required, but FD05 may specify the account structure.

**Affected P-IDs**: P08, P17

---

### AD-14: Payout Contract Ownership

**Status**: UNRESOLVED (DEC-04 from P00)

**Topic**: `ProviderCapabilityEnum::Payout` exists in the Payment domain enum, but `PaymentAdapterInterface` has no `initiatePayout()` method. The payout lifecycle is missing from the current architecture. Decision required: (a) add `initiatePayout()` to `PaymentAdapterInterface`, (b) create a separate `PayoutAdapterInterface`, or (c) create a new `App\Modules\Payout` module.

**Decision owner**: Architect

**Risk if deferred**: P18 (Payout Lifecycle) cannot be implemented. All community features (P24–P28) that require fund disbursement are blocked.

**Blocking condition**: Architect decision + ADR (superseding or extending ADR-E48-001 if a new module is created).

**Affected P-IDs**: P18, P24, P25, P26, P27, P28

---

### AD-16: Partial Refund — Non-Refundable Fee Policy

**Status**: UNRESOLVED

**Topic**: When a partial refund is issued and the original payment fee is non-refundable (e.g., fixed processing charge absorbed by OleKard or the consumer), what is the correct accounting treatment? Specifically: should the compensating credit entry reflect the full original payment amount or the net-of-fee amount? Does the fee revenue account receive a compensating debit on refund, or is the fee retained as earned?

**Decision owner**: Finance Lead (with input from E21 Fees & Pricing lead to confirm whether current fee rules address this case)

**Note**: AD-06 confirms that refunds use compensating entries and original entries are immutable. AD-03 confirms wallet payments have zero fee. This decision applies specifically to bank payment refunds (P12–P15) where fee=non-zero and fee-bearer is confirmed. If AD-12 (fee bearer) remains AWAITING_DOCUMENT, AD-16 is also blocked.

**Risk if deferred**: Bank payment refund journal entries (P15) will be ambiguous — engineers may incorrectly reverse the fee entry, creating phantom revenue or leaving unreconciled fee amounts in the ledger.

**Blocking condition**: AD-12 resolved (fee bearer confirmed) AND Finance Lead confirms fee refundability policy per payment method.

**Affected P-IDs**: P08, P15

---

### AD-15: Multi-Currency FX Risk Bearer

**Status**: UNRESOLVED

**Topic**: For multi-currency payments where the provider settles in a different currency than the consumer paid, who bears the exchange-rate risk — consumer, organisation, or OleKard?

**Decision owner**: Finance Lead (E29 scope — deferred to E29 multi-currency epic)

**Note**: FX policy is in scope of E29 (Multi-Currency Wallet), which is COMPLETE/RELEASE READY. The E29 FX handling should be verified for consistency with bank payment settlement.

**Risk if deferred**: Multi-currency bank payments (e.g., consumer pays in USD, settlement in BWP) have undefined accounting treatment.

**Blocking condition**: E29 FX policy documentation reviewed and confirmed to cover bank payment FX.

**Affected P-IDs**: P12, P13, P14

---

## Sign-Off

| Role | Name | Date | Status |
|------|------|------|--------|
| Architect | PENDING | 2026-10-21 | PENDING — module boundaries, contract invariants, and vocabulary verified by P01 implement run; sign-off required before P02 starts |
| Finance Reviewer | PENDING — awaiting arch doc v1.1 | 2026-10-21 | PENDING — AD-08 through AD-13, AD-16 blocked by DEC-07; wallet payment journal entry (debit side) confirmed |

**Sign-off criteria**:
- Architect: "Module boundaries, contract invariants, and financial vocabulary are unambiguous. P02–P11 can proceed."
- Finance Reviewer: "All accounting decisions are recorded as CONFIRMED with evidence or UNRESOLVED with a named owner and risk. P08 can construct journal entries for Wallet payments from this register."
