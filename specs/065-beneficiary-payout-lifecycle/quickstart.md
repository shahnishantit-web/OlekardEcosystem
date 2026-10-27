# Quickstart: P18 — Beneficiary Verification and Payout Lifecycle

**Date**: 2026-10-25

**Scope**: Validation scenarios for US1 (beneficiary verification + payable balance) and US2 (funds reservation + maker/checker approval). US3 and US4 are BLOCKED pending AD-14 and AD-10 respectively — their scenarios are listed but cannot be executed until those decisions are resolved.

**Prerequisites**:
- Docker stack running: `cd wallet-ecosystem && docker compose up -d`
- API reachable at `http://localhost:8000`
- Seeded admin tokens: see `DevAdminSeeder` (admin@olekard.local / Admin123!)
- Existing fund with known `fund_reference_id` and `organisation_id` (use seed data from `DevFundSeeder` or substitute your own)
- Existing cleared custody balance ≥ 10,000 BWP minor units for the fund under test

---

## SC-001: Beneficiary Registration and Verification

**Goal**: Verify that a beneficiary can be registered in `pending_verification` state and then transitioned to `verified` after recording an external verification reference.

### Step 1 — Register beneficiary

```http
POST /api/v1/admin/payment/payout/beneficiaries
Authorization: Bearer {ADMIN_TOKEN}
Idempotency-Key: test-sc001-beneficiary-001
Content-Type: application/json

{
  "organisation_id": "{ORGANISATION_ID}",
  "account_type": "bank_account",
  "account_number": "62000123456",
  "bank_code": "FNB001",
  "bank_name": "First National Bank",
  "account_holder_name": "Test Beneficiary Name",
  "currency_code": "BWP"
}
```

**Expected**: `201 Created` — `data.status = "pending_verification"`

### Step 2 — Verify beneficiary

```http
POST /api/v1/admin/payment/payout/beneficiaries/{BENEFICIARY_ID}/verify
Authorization: Bearer {ADMIN_TOKEN}
Idempotency-Key: test-sc001-verify-001
Content-Type: application/json

{
  "verification_reference": "KYC-REF-20261025-001",
  "verification_note": "Account holder identity confirmed via KYC provider."
}
```

**Expected**: `200 OK` — `data.status = "verified"`, `data.verified_at` is set

### Step 3 — Verify idempotency

Re-send the registration request with the same `Idempotency-Key`.

**Expected**: `409 Conflict` — `existing_id` in response body matches the beneficiary ID from Step 1.

---

## SC-002: Unverified Beneficiary Blocked at Payout Initiation

**Goal**: Confirm that a payout cannot be initiated for a beneficiary in `pending_verification` state.

```http
POST /api/v1/admin/payment/payout/requests
Authorization: Bearer {ADMIN_TOKEN}
Idempotency-Key: test-sc002-request-001
Content-Type: application/json

{
  "fund_reference_id": "{FUND_REFERENCE_ID}",
  "purpose_type": "funeral_fund",
  "organisation_id": "{ORGANISATION_ID}",
  "beneficiary_id": "{UNVERIFIED_BENEFICIARY_ID}",
  "currency_code": "BWP"
}
```

**Expected**: `400 Bad Request` — error message references beneficiary not verified.

---

## SC-003: Payable Balance Computation

**Goal**: Verify that the server-derived payable balance correctly deducts refunds, dispute holds, and existing reservations.

### Step 1 — Initiate payout

```http
POST /api/v1/admin/payment/payout/requests
Authorization: Bearer {ADMIN_TOKEN}
Idempotency-Key: test-sc003-request-001
Content-Type: application/json

{
  "fund_reference_id": "{FUND_REFERENCE_ID}",
  "purpose_type": "funeral_fund",
  "organisation_id": "{ORGANISATION_ID}",
  "beneficiary_id": "{VERIFIED_BENEFICIARY_ID}",
  "currency_code": "BWP",
  "requested_amount_minor": 99999
}
```

**Expected**: `201 Created`
- `data.status = "pending_verification"` (or `"awaiting_approval"` if entitlement pre-granted)
- `data.computed_payable_minor` ≠ `requested_amount_minor` (server overrides the caller value)
- `data.computed_payable_minor` equals: `custody_balance - confirmed_refunds - open_dispute_holds - existing_reservations` (all ≥ 0; floored at 0)

### Step 2 — Query balance breakdown

```http
GET /api/v1/admin/payment/payout/requests/{PAYOUT_ID}/balance
Authorization: Bearer {ADMIN_TOKEN}
```

**Expected**: `200 OK` — `data` includes `custody_balance_minor`, `confirmed_refunds_minor`, `open_dispute_holds_minor`, `existing_reservations_minor`, `payable_minor`, `computed_at`.

---

## SC-004: Maker Creates Reservation

**Goal**: Verify that the maker can create a reservation up to the payable balance.

```http
POST /api/v1/admin/payment/payout/requests/{PAYOUT_ID}/reserve
Authorization: Bearer {MAKER_ADMIN_TOKEN}
Idempotency-Key: test-sc004-reserve-001
Content-Type: application/json

{
  "reserved_amount_minor": 5000
}
```

**Expected**: `201 Created` — `data.status = "awaiting_approval"`, `data.maker_id` = maker's admin ID, `data.approver_id = null`

---

## SC-005: Maker Self-Approval Blocked

**Goal**: Verify that the same actor who created the reservation cannot approve it.

```http
POST /api/v1/admin/payment/payout/requests/{PAYOUT_ID}/approve-reservation
Authorization: Bearer {MAKER_ADMIN_TOKEN}     # Same token as used in SC-004
Idempotency-Key: test-sc005-approve-001
```

**Expected**: `403 Forbidden` — error message indicates self-approval is not permitted.

---

## SC-006: Checker Approves Reservation

**Goal**: Verify that a different actor (checker) can approve the reservation.

```http
POST /api/v1/admin/payment/payout/requests/{PAYOUT_ID}/approve-reservation
Authorization: Bearer {CHECKER_ADMIN_TOKEN}   # Different admin from SC-004 maker
Idempotency-Key: test-sc006-approve-001
```

**Expected**: `200 OK` — `data.status = "approved"`, `data.approver_id` = checker's admin ID, `data.approved_at` is set.

---

## SC-007: Approval Invalidated After Amount Change

**Goal**: Verify that modifying the reservation amount after approval triggers invalidation.

### Step 1 — Set up an approved reservation

Use SC-006 to create an approved reservation.

### Step 2 — Modify the payout (change amount)

The reservation must be re-created with a different amount via the reserve endpoint.

*Implementation note: The re-reservation or amendment endpoint should detect that the `amount_hash` has changed and auto-invalidate the prior approval, setting status back to `awaiting_approval`.*

**Expected**: After amount change — `data.status = "awaiting_approval"`, `data.approver_id = null`, `data.approved_at = null`. Audit log shows an invalidation entry.

---

## SC-008: Concurrent Reservation Overspend Blocked

**Goal**: Confirm that two simultaneous reservation requests for the same fund cannot together exceed the available balance.

**Setup**: A fund with exactly 5,000 minor units available.

**Test**: Submit two reservation requests simultaneously — both requesting 5,000.

**Expected**: Exactly one succeeds with `201 Created`; the other receives `400 Bad Request` with an insufficient-balance error. The total reserved amount never exceeds 5,000.

*This scenario requires a Docker-based feature test with a test transaction that holds the lock. See `PayoutReservationAdminControllerTest::test_concurrent_reservations_cannot_overspend`.*

---

## SC-009 — SC-010: US3 and US4 (BLOCKED)

These scenarios cannot be executed until the following decisions are resolved:

- **SC-009** (Execution via adapter): Blocked pending **AD-14** — payout contract ownership. Once resolved, test with StubPayoutAdapter configured to return `'pending'` then `'succeeded'`.
- **SC-010** (Settlement confirmation and ledger disbursement): Blocked pending **AD-10** (custody account type) and **LC14** (disbursement ledger contract). Once resolved, test that a payout is not marked `settled` until `LedgerModuleContract::entryExists()` returns true.

---

## Pass Criteria

| SC | Description | Status |
|----|-------------|--------|
| SC-001 | Beneficiary registration and verification | Ready |
| SC-002 | Unverified beneficiary blocked | Ready |
| SC-003 | Payable balance computation | Ready |
| SC-004 | Maker creates reservation | Ready |
| SC-005 | Self-approval blocked | Ready |
| SC-006 | Checker approves reservation | Ready |
| SC-007 | Approval invalidated on change | Ready |
| SC-008 | Concurrent overspend blocked | Docker-gated |
| SC-009 | Execution via adapter | BLOCKED (AD-14) |
| SC-010 | Settlement confirmation + ledger | BLOCKED (AD-10, LC14) |

**Overall**: US1 + US2 fully testable. US3 + US4 remain BLOCKED.
