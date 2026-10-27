# Quickstart: P01 — Peer Review Verification Protocol

**Date**: 2026-10-21

**Purpose**: Prove P01 outputs are complete and correct before P02–P11 engineering begins.

**No Docker or server required** — verification is document review and code confirmation.

---

## Prerequisites

- Access to repository at `C:\projects\OleKardEcosystem`
- The four P01 output documents:
  - `docs/architecture/payment-module-boundary-register.md`
  - `docs/architecture/payment-contract-register.md`
  - `docs/architecture/payment-financial-vocabulary.md`
  - `docs/architecture/decisions/ADR-E48-003-accounting-decisions.md`

---

## Scenario 1 — Architect: Module Boundary Completeness (SC-01)

**Goal**: Confirm every module in the codebase has an entry in the register.

**Step 1**: Count modules from code:

```
ls wallet-ecosystem/services/api/app/Modules/ | wc -l
```

Expected output: `30`

**Step 2**: Count module entries in the register:

Open `docs/architecture/payment-module-boundary-register.md` and count the number of `###` section headers.

**Expected**: 30 entries matching the 30 module directory names exactly.

**Pass criterion**: Module count from code == module count in register, AND every module directory name appears in the register.

---

## Scenario 2 — Engineer: Contract Invariants Lookup (SC-02 + SC-06)

**Goal**: Confirm all 5 cross-module contracts have plain-language invariants — and that an engineer can find the answer to a specific question within 60 seconds.

**Test question 1**: "Does redirect arrival from a bank count as capture?"

Open `docs/architecture/payment-financial-vocabulary.md`, go to Term 1: Capture, read "Not to be confused with" section.

**Expected answer**: "Redirect arrival alone is NEVER accepted as capture evidence." (within 60 seconds)

**Test question 2**: "Can WalletPaymentAdapter call LedgerModuleContract directly?"

Open `docs/architecture/payment-contract-register.md`, go to Section 2 (PaymentAdapterInterface), initiatePayment method invariants.

**Expected answer**: "MUST NOT call LedgerModuleContract::postEntry() directly — ledger posting is done by the orchestration layer." (within 60 seconds)

**Test question 3**: "Does a confirmed payment mean funds have settled?"

Open `docs/architecture/payment-financial-vocabulary.md`, go to Term 6: Settlement.

**Expected answer**: "Settlement is SEPARATE from and LATER THAN capture and accounting acknowledgement." (within 60 seconds)

---

## Scenario 3 — Finance Reviewer: Accounting Decision Register (SC-03 + SC-04)

**Goal**: Confirm every accounting decision is recorded as CONFIRMED or UNRESOLVED.

**Step 1**: Open `docs/architecture/decisions/ADR-E48-003-accounting-decisions.md`.

**Step 2**: Verify all 5 FR-07 topics (a)–(e) appear as entries:
- (a) Custody account type
- (b) Merchant/org settlement bank account
- (c) Chart-of-accounts — Wallet payment, bank payment, refund
- (d) Fee bearer per payment method
- (e) Late-payment suspense account

**Step 3**: For each entry, verify one of these markers is present:
- `**Status**: CONFIRMED` with a `Source evidence` citation to a real file path or ADR
- `**Status**: UNRESOLVED` or `**Status**: AWAITING_DOCUMENT` with a named `Decision owner` and `Blocking condition`

**Pass criterion**: All 5 topics present; every entry has either CONFIRMED+evidence or UNRESOLVED+owner.

**Test — debit/credit chain (SC-04)**:

Read the Wallet payment chart-of-accounts entry in ADR-E48-003. An engineer should be able to write the journal entry:

```
Debit:  consumer_wallet_pocket_account   [amount_minor]
Credit: [organisation account — CONFIRMED or UNRESOLVED]
```

If the credit side is UNRESOLVED, the register must say so and name the owner.

---

## Scenario 4 — Architect: No ADR Contradictions (SC-05)

**Goal**: Confirm ADR-E48-003 does not contradict ADR-E48-001 or ADR-E48-002.

**Check 1**: ADR-E48-001 says only `PaymentModuleContract` may be called by business modules.

Open `docs/architecture/payment-module-boundary-register.md`, Payment section. Verify "Permitted callers" states business modules use `PaymentModuleContract` only.

**Check 2**: ADR-E48-002 says `WalletPaymentAdapter` MUST NOT manipulate wallet balance directly.

Open `docs/architecture/payment-contract-register.md`, Section 4 (WalletModuleContract), `createHold` invariants. Verify "MUST NOT be called outside WalletPaymentAdapter from within the Payment module" is stated.

**Check 3**: ADR-E48-003 accounting decisions do not introduce any direct wallet balance manipulation.

Open ADR-E48-003. Scan all CONFIRMED entries. Verify none describe a direct write to wallet tables.

**Pass criterion**: All 3 checks pass with no contradictions found.

---

## Scenario 5 — Sign-Off Criteria

P01 is complete when both of the following are recorded in ADR-E48-003:

1. **Architect sign-off**: "Module boundaries, contract invariants, and financial vocabulary are unambiguous. P02–P11 can proceed."
2. **Finance reviewer sign-off**: "All accounting decisions are recorded as CONFIRMED with evidence or UNRESOLVED with a named owner. P08 can construct journal entries for Wallet payments from this register."

If either sign-off is missing, P01 remains PARTIAL.
