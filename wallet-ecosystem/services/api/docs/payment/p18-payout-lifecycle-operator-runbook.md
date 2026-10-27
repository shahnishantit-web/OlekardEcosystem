# P18 Payout Lifecycle — Operator Runbook

## Overview

Payout requests are raised by domain operators (e.g., fund administrators) to disburse funds from a custody wallet to a registered, verified beneficiary. The Payment module owns the payout lifecycle state machine; the Wallet module owns balances; the Ledger module owns disbursement entries.

Execution (US3) and settlement confirmation (US4) are **BLOCKED** pending AD-14 and AD-10/LC14 resolution. This runbook covers the unblocked scope: beneficiary management, payable balance queries, and funds reservation (US1 + US2).

---

## State Machine

```
PayoutRequest statuses
├── pending_verification      ← initiation gate: beneficiary not yet verified
├── awaiting_approval         ← beneficiary verified; no active reservation
├── reserved                  ← reservation created (awaiting_approval)
├── approved                  ← reservation approved; operation_id minted
├── executing                 ← [STUB] sent to payout adapter
├── pending_settlement        ← [STUB] adapter result unknown/pending
├── settled                   ← [STUB] ledger disbursed; provider evidence matched
├── failed                    ← terminal
├── manual_review             ← operator intervention required
└── cancelled                 ← terminal

PayoutReservation statuses
├── awaiting_approval         ← maker created; not yet approved
├── approved                  ← approver confirmed; amount_hash locked
├── invalidated               ← amount or destination changed after reservation
├── rejected                  ← approver rejected
├── expired                   ← TTL elapsed without approval
└── released                  ← [future use]
```

---

## Payable Balance Formula

The server derives payable balance — never trust client-supplied amounts:

```
payable = max(0,
    custody_wallet.available_balance
    - SUM(confirmed/settled refunds via payment_intents.purpose_reference)
    - SUM(open dispute holds via dispute_holds → clearing_records → payment_intents)
    - SUM(active reservations for this fund)
)
```

**Critical**: confirmed refunds are joined through `payment_intents.purpose_reference`, NOT via a direct `fund_reference_id` column on `payment_refunds` (that column does not exist).

---

## Maker / Approver Separation

Reservations enforce a **maker ≠ approver** rule. The service throws `PayoutSelfApprovalException` (HTTP 403) if the approver is the same user who created the reservation. Do not attempt to bypass this in tooling.

---

## Reservation Expiry

Reservations expire after `payment.payout.reservation_expiry_hours` config hours (default: 48h). The `PayoutReservationExpiryJob` runs hourly (no overlap) and marks stale reservations as `expired`, returning the payout request to `awaiting_approval`. No ledger entries are made on expiry.

### Manual expiry (emergency only)

```sql
-- Payment DB only. Read-only query to check stale reservations:
SELECT r.id, r.status, r.expires_at, r.maker_id, rq.fund_reference_id
FROM payment.payout_reservations r
JOIN payment.payout_requests rq ON rq.id = r.payout_request_id
WHERE r.status = 'awaiting_approval'
  AND r.expires_at < NOW();
```

Never update reservation rows directly in production — trigger the job or use the admin API.

---

## Approval Invalidation

If the requested amount or beneficiary is changed after a reservation is created, the reservation is automatically invalidated (status → `invalidated`, payout request → `awaiting_approval`). The `amount_hash` is `sha256("{reserved_amount_minor}|{beneficiary_id}")`.

---

## Domain Entitlement Gateway

The `PayoutDomainEntitlementGatewayInterface` defaults to `PermissiveEntitlementGateway` (logs a warning, always allows). Domain modules (e.g., Fund Management) must bind their own implementation to enforce fund-level payout rules. A warning log entry `[P18] PermissiveEntitlementGateway in use` indicates no domain override is registered.

---

## Permissions Required

| Permission             | Description                              |
|------------------------|------------------------------------------|
| `payment.payout.beneficiary.read`   | View beneficiaries          |
| `payment.payout.beneficiary.write`  | Register/verify beneficiaries |
| `payment.payout.request.read`       | View requests and balances  |
| `payment.payout.request.write`      | Initiate and manage requests |
| `payment.payout.reservation.write`  | Create/approve/reject reservations |

---

## Audit Trail

Every mutation emits an `AuditEventData` record via `AuditServiceInterface`. Search Audit Log for:

| Action                                    | Trigger                               |
|-------------------------------------------|---------------------------------------|
| `payment.payout.beneficiary_registered`   | Register beneficiary                  |
| `payment.payout.beneficiary_verified`     | Record verification                   |
| `payment.payout.entitlement_denied`       | Domain gateway blocks payout          |
| `payment.payout.entitlement_checked`      | Domain gateway grants payout          |
| `payment.payout.reservation_created`      | Maker creates reservation             |
| `payment.payout.reservation_approved`     | Approver approves reservation         |
| `payment.payout.reservation_rejected`     | Approver rejects reservation          |
| `payment.payout.reservation_invalidated`  | Amount/destination changed            |
| `payment.payout.reservation_expired`      | Hourly expiry job fires               |

---

## Idempotency

All state-changing routes require an `Idempotency-Key` header (UUID). The middleware returns `422` if absent and replays the stored response if the key was already used. Do not reuse idempotency keys across logically distinct requests.

---

## Common Failure Scenarios

### Beneficiary stuck in `pending_verification`

1. Check the audit log for `payment.payout.beneficiary_registered`.
2. Call `POST /api/v1/admin/payment/payout/beneficiaries/{id}/verify` with the external `verification_reference`.
3. If verification evidence is unavailable, suspend the beneficiary (`status → suspended`) rather than leaving it in `pending_verification`.

### Reservation stuck in `awaiting_approval`

1. Check `expires_at` — if expired, the hourly job will clean it up. Force-trigger via `php artisan schedule:run` if needed.
2. Check if the approver is the same as the maker (self-approval forbidden).
3. If the amount was edited after reservation, the reservation is `invalidated` — create a new one.

### Payout request stuck in `approved` (execution BLOCKED)

US3 (execution via payout adapter) is pending AD-14. Payout requests will remain in `approved` status until the payout adapter contract is resolved and the `PayoutExecutionService` stub is replaced. Escalate to the Architecture team for AD-14.

### Payable balance returns 0 unexpectedly

1. Check `custody_wallet.available_balance` directly via `GET /api/v1/admin/wallets/{id}/balance`.
2. Check for open dispute holds: query `payment.payment_dispute_holds` with `status IN ('open', 'under_review', 'awaiting_evidence')`.
3. Check for existing active reservations: query `payment.payout_reservations` with `status IN ('awaiting_approval', 'approved')`.
4. Refunds are deducted via `payment_intents.purpose_reference` join — verify that intents are associated to the correct fund reference.

---

## Monitoring

| Signal                               | Meaning                                            |
|--------------------------------------|----------------------------------------------------|
| `[P18] PermissiveEntitlementGateway` | No domain entitlement override registered (warn)   |
| Reservation TTL nearing             | Configure `payment.payout.reservation_expiry_hours` |
| `PayoutReservationExpiryJob` missing | Check scheduler is running (`schedule:run`)        |
| Audit gap on `reservation_approved`  | Cross-reference `PayoutReservationApprovedEvent` outbox |

---

## Migration History

| Migration | Description |
|-----------|-------------|
| `2026_10_25_000019` | `payout_beneficiaries` table + 5 payout permissions |
| `2026_10_25_000020` | `payout_requests` table |
| `2026_10_25_000021` | `payout_reservations` table |
| `2026_10_25_000022` | `payout_attempts` table (STUB — AD-14) |
| `2026_10_25_000023` | `payout_settlement_records` table + reconciliation_exceptions type extension |

---

## Blocked Work

| Blocked Item                 | Blocker   | Owner                        |
|------------------------------|-----------|------------------------------|
| Payout execution adapter     | AD-14     | Architecture + Finance team  |
| Settlement confirmation       | AD-10     | Architecture team            |
| Ledger disbursement handoff   | LC14      | Finance / Ledger team        |
