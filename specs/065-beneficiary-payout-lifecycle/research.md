# Research: P18 — Beneficiary Verification and Payout Lifecycle

**Date**: 2026-10-25

---

## Decision 1: Payout Contract Ownership (AD-14 — UNRESOLVED)

**Decision**: PENDING — awaiting architect decision. P18 scaffolds Option B as the working assumption; actual implementation of the adapter interface is BLOCKED until the architect confirms.

**Option A**: Add `initiatePayout()` to `PaymentAdapterInterface`
- Pro: No new interface; existing adapter infrastructure reused as-is
- Con: Conflates incoming payment adapters with outgoing payout adapters; a provider that supports incoming but not outgoing payments would have an unused `initiatePayout()` method

**Option B (working assumption)**: Create `PayoutAdapterInterface` within the Payment module (`App\Modules\Payment\Domain\Contracts\PayoutAdapterInterface`)
- Pro: Clean separation of concerns; incoming vs outgoing adapter capability types remain distinct; minimal new surface area; no new module boundary
- Con: Two adapter interfaces to maintain (not a significant burden)

**Option C**: Create `App\Modules\Payout` module
- Pro: Maximum isolation; can evolve independently
- Con: New module boundary requires ADR (Principle 14); adds service provider, schema, inter-module contracts; high overhead for P18 scope; the Payout domain is tightly coupled to Payment data (clearing records, provider accounts) making extraction premature

**Rationale for Option B working assumption**: The Payment module already has all the relevant infrastructure (provider accounts, `ProviderCapabilityEnum::Payout`, orchestration service). A separate interface within the same module is the least-invasive path and avoids premature boundary extraction.

**Blocking condition**: Architect confirmation required before `PayoutAdapterInterface` methods (initiatePayout, queryPayoutStatus) can be finalised. File created as empty stub with `@todo AD-14` until then.

---

## Decision 2: Stable Payout Operation ID

**Decision**: `PayoutOperationId::fromReservation(string $reservationId): string` returns `bin2hex(hash('sha256', "payout:{$reservationId}", true))` — 64-character lowercase hex string.

**Rationale**: Deterministic from the reservation ID; no random component; same reservation always generates the same operation ID. This satisfies FR-013 (operation ID does not change across retries). The `payout:` prefix namespaces the hash to prevent collision with other payment operation IDs in the same provider system.

**Alternatives considered**:
- UUID v4 (random): Rejected — not deterministic; a new UUID on each retry breaks idempotency at the adapter.
- UUID v5 (name-based SHA-1): Rejected — SHA-1 is deprecated; the hex approach using SHA-256 is consistent with the P17 content hash approach.

---

## Decision 3: Payable Balance Computation

**Decision**: `PayoutBalanceService::computeForFund(string $fundReferenceId, string $currencyCode): PayableBalance` executes:

```
payable_minor = max(0,
    custody_balance_minor           -- from WalletModuleContract::availableBalance()
  - confirmed_refunds_minor         -- SUM(payment_refunds.amount_minor) WHERE fund_reference = $id AND status IN ('confirmed','settled')
  - open_dispute_holds_minor        -- SUM(payment_dispute_holds.held_amount_minor) WHERE fund_reference = $id AND status IN ('open','under_review','awaiting_evidence')
  - existing_reservations_minor     -- SUM(payout_reservations.reserved_amount_minor) WHERE payout_request.fund_reference_id = $id AND status IN ('awaiting_approval','approved')
)
```

**Rationale**: All deductions use server-side queries; no caller input accepted. The `max(0, ...)` floor prevents negative available balance from being returned. Existing reservations are included in the deduction so two simultaneous reservation requests cannot both succeed if the combined total would exceed custody.

**Alternatives considered**:
- Derive balance from `ClearingRecord` totals (P17): This would double-compute what P17 already qualified. Instead we read from `WalletModuleContract::availableBalance()` on the custody pocket, which is the authoritative balance source (Principle 7 — Wallet owns balances).
- Using `ClearingRecord.net_qualified_minor` from `PayoutQualifiedEvent`: This is an async signal that may lag by minutes. For synchronous reservation checks, the real-time custody balance is required.

---

## Decision 4: Reservation Pessimistic Locking Strategy

**Decision**: `PayoutReservationService::createReservation()` opens a `DB::connection('payment')->transaction()` and executes `SELECT ... FOR UPDATE` on the custody pocket record (via WalletModuleContract) before inserting the reservation. If the available balance (recomputed inside the lock) is less than the requested amount, the transaction rolls back and `InsufficientPayableBalanceException` is thrown.

**Rationale**: `availableBalance()` is explicitly documented as "optimistic only" (see payment-contract-register.md §4). Reservation creation must use pessimistic locking for the definitive check. The recomputation inside the transaction prevents TOCTOU (time-of-check-time-of-use) race conditions.

**Constraint**: This means `createReservation()` takes a brief exclusive lock on the custody pocket row. The lock is held only for the duration of the INSERT + balance check — typically < 5 ms. Concurrent reservations for different funds are unaffected.

---

## Decision 5: Maker/Checker Approval Invalidation Trigger

**Decision**: The `PayoutReservationModel` stores an `amount_hash` column: `hash('sha256', "{reserved_amount_minor}|{beneficiary_id}")`. When the maker modifies amount or destination, `PayoutReservationService::invalidateApproval()` recomputes the hash and compares it to the stored value. If different, the reservation status is set to `awaiting_approval` and the `approver_id` + `approved_at` are cleared. An audit record is written in the same transaction.

**Rationale**: Storing the hash at approval time provides tamper-evidence for the approved terms. Any change to amount or destination regenerates a different hash, which is the detection signal. This avoids complex change-tracking logic — the hash is a simple, reliable invariant.

**Alternatives considered**:
- Track changes with a separate audit/history table: Overkill for P18 scope; the existing audit service already captures all state transitions.
- Version number increment: Less informative — a version bump doesn't specify which field changed. The hash includes both amount and destination so either change triggers invalidation.

---

## Decision 6: Reservation Expiry Strategy

**Decision**: `payout_reservations.expires_at = NOW() + INTERVAL config('payment.payout.reservation_expiry_hours', 48) HOUR`. A scheduled `PayoutReservationExpiryJob` runs hourly (not daily — to keep expiry granularity reasonable) and sets `status = 'expired'` for all reservations where `expires_at < NOW()` and `status IN ('awaiting_approval')`. Approved reservations do NOT auto-expire — they require explicit cancellation by an admin to prevent race conditions with in-flight execution.

**Rationale**: Constitution Principle 3 prohibits hard-coding the threshold. Default of 48 hours allows reasonable approval workflows without leaving funds locked indefinitely. Approved reservations are protected from auto-expiry because expiring an approved-but-not-yet-executed reservation could cause a payout to fail mid-execution.

---

## Decision 7: Stub Payout Adapter (DEV MOCK for US3 testing)

**Decision**: `StubPayoutAdapter` (in `Infrastructure/Adapters/`) implements `PayoutAdapterInterface` and returns a configurable response controlled by `config('payment.payout.stub_adapter_response')`. Available responses: `'succeeded'`, `'pending'`, `'failed'`. Default: `'succeeded'`.

**Labelling constraint**: Every file in the stub adapter must carry a `// DEV MOCK — NOT FOR PRODUCTION` header comment. The `PaymentServiceProvider` binds this adapter ONLY when `app()->environment('local', 'testing')`. Production bindings are left as BLOCKED stubs until AD-14 is resolved.

---

## Decision 8: Reconciliation Exception Type Extension for Payouts

**Decision**: Two new `ReconciliationExceptionType` values are added for payout settlement discrepancies:
- `payout_amount_discrepancy` — provider confirms a different amount than the submitted payout amount
- `payout_missing_evidence` — payout attempt reached `pending_settlement` state but no provider evidence was received within the configured evidence timeout

These extend the existing DB CHECK constraint on `payment.reconciliation_exceptions`. Migration `2026_10_25_000023` (or the settlement table migration) must include an ALTER to add these two values to the constraint.

**Rationale**: Reuses the existing reconciliation exception infrastructure established in P07–P17. No separate payout exception table needed — the exception type discriminates which domain raised the exception.
