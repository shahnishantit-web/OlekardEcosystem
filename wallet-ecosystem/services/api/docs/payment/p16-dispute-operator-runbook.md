# P16 Dispute Management — Operator Runbook

## Overview

P16 handles reversals, disputes, and chargebacks received from bank/payment providers. All financial adjustments use double-entry journals (P07 LedgerService). No wallet balance is mutated directly.

---

## Dispute Lifecycle (LC12)

```
received → under_review → won | lost | closed_reversed | investigation_required
```

| Transition | Trigger | Financial Effect |
|---|---|---|
| `received → under_review` | Admin authorises hold | DisputeHold created; hold_amount_minor recorded |
| `under_review → won` | Provider decision = won | Hold released; no chargeback journal |
| `under_review → lost` | Provider decision = lost | Correcting journal: `dispute:{caseId}:chargeback` + optional fee journal |
| `under_review → closed_reversed` | Provider reversal confirmed | Hold released; PaymentAttempt::outcome = 'reversed' |
| `any terminal → investigation_required` | Contradictory outcome received | DisputeLateContradictoryOutcomeEvent fired; manual review required |

---

## Financial Integrity Notes

- **Double-compensation guard**: `net_chargeback = max(0, disputed_amount - confirmed_prior_refunds)`. If net = 0, `accounting_status = no_journal_required`.
- **Correcting journals are idempotent**: keys `dispute:{caseId}:chargeback` and `dispute:{caseId}:fee` — safe to retry.
- **Journals posted OUTSIDE DB transactions** — consistent with P15 refund pattern.
- **Never clawback consumer wallet** — P16 records the accounting obligation; settlement/payout teams action it.

---

## Key Monitoring

### Approaching Deadlines

`MonitorDisputeDeadlinesJob` runs hourly and emits:

```
log.warning: dispute.deadline.approaching
  case_id, provider_code, case_type, disputed_amount_minor, response_deadline, hours_remaining
```

**Action**: Review cases in `status=under_review` with `response_deadline < now() + 24h` and ensure evidence has been submitted.

### Investigation Required

Cases in `status=investigation_required` have received a contradictory outcome (e.g., "won" received after a prior "lost"). These require manual finance review.

**Action**:
1. `GET /api/v1/admin/payment/dispute-cases?status=investigation_required`
2. Review the event timeline for both outcomes.
3. Contact provider with `provider_dispute_reference` to resolve.
4. Use `POST /dispute-cases/{id}/outcome` with the confirmed final outcome.

### PostDrawLiability (Finance Review Gate)

Cases with `requires_finance_review=true` involve raffle/draw entitlements where organiser/beneficiary liability is unclear.

**Action**:
1. `GET /api/v1/admin/payment/dispute-cases?requires_finance_review=true`
2. Coordinate with finance team to determine liability allocation.
3. Once confirmed: `POST /api/v1/admin/payment/dispute-cases/{id}/finance-review` with `actor_id`.

---

## Admin Endpoints Reference

| Endpoint | Permission | Purpose |
|---|---|---|
| `GET /admin/payment/dispute-cases` | `payment.disputes.view` | List/filter cases |
| `GET /admin/payment/dispute-cases/{id}` | `payment.disputes.view` | Case detail with event timeline |
| `POST /admin/payment/dispute-cases/{id}/hold` | `payment.disputes.manage` | Authorise hold (idempotency key required) |
| `POST /admin/payment/dispute-cases/{id}/outcome` | `payment.disputes.manage` | Record won/lost/reversed (idempotency key required) |
| `POST /admin/payment/dispute-cases/{id}/evidence` | `payment.disputes.manage` | Submit evidence reference |
| `POST /admin/payment/dispute-cases/{id}/finance-review` | `payment.disputes.finance_review` | Acknowledge PostDrawLiability review |

---

## Reconciliation Exceptions

If a bank dispute callback references an unknown `payment_attempt_id`, a `ReconciliationException` of type `unmatched_dispute_event` is raised. These appear in:

`GET /api/v1/admin/payment/reconciliation/exceptions?type=unmatched_dispute_event`

**Action**: Match the provider dispute reference to the correct attempt manually; update the reconciliation exception with resolution notes.

---

## Seeded Permissions

| Permission | Role |
|---|---|
| `payment.disputes.view` | admin, customer (own disputes only) |
| `payment.disputes.manage` | admin |
| `payment.disputes.finance_review` | admin (finance-review role) |

---

## Restart Notes

- All correcting journals have idempotency keys — safe to re-run `recordLostOutcome` after a failure.
- `MonitorDisputeDeadlinesJob` is read-only — no state mutation, safe to re-run.
- `DisputeCaseEventModel` is append-only — no updates ever.
- If `acknowledgeFinanceReview` is called on an already-acknowledged case, the service will check status and may throw `DisputeCaseClosedException` if terminal.
