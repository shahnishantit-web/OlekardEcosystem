# Data Model: P24 Funeral Assistance

All identifiers are UUIDv7. Tenant and actor identifiers are taken from authenticated server context. Monetary amounts use positive integer minor units. Funeral domain tables use a `funeral_` prefix in the community foundation schema; they do not duplicate Payment, Wallet, Ledger or P18 payout records.

## Community and household register

### Community

`id`, `organisation_id`, display name, approved participation/exemption policy reference and version, lifecycle status, actor/audit timestamps. Tenant ownership is immutable after creation.

### Household

`id`, `community_id`, private reference, status (`pending_review`, `eligible`, `exempt`, `suspended`), exemption decision reference/status, created/updated actors and timestamps. No public display name or non-contributor state.

### Household representative

`id`, `household_id`, `actor_id`, verification status, opaque restricted evidence reference, verified/revoked actors and timestamps. A uniqueness rule prevents duplicate active actor/household associations.

## Collection lifecycle

### Funeral request/collection

`id`, `community_id`, request status (`draft`, `under_review`, `approved`, `active`, `closing`, `closed`, `archived`), minimal private evidence reference, `payout_beneficiary_id`, terms version, contribution amount (default 2000), currency (`BWP`), eligibility snapshot version, opened/closed timestamps, approval and reviewer actor references, unresolved liability summary and audit timestamps. Collection close does not imply settlement or payout.

Beneficiary changes invalidate reviewer/approval references and force `under_review` before activation.

### Eligibility snapshot and member

Snapshot: `id`, collection, monotonically increasing version, frozen timestamp, terms version, counts, digest. Snapshot member: snapshot, household, inclusion decision (`eligible`/`exempt`), decision version/reference. Unique `(snapshot_id, household_id)`.

### Household obligation

`id`, collection, snapshot member, household, unique payable reference, server amount/currency/terms version, payment state (`unpaid`, `pending`, `unknown`, `confirmed`, `late_review`, `refunded`, `failed`), fulfilment state (`pending`, `fulfilled`, `held_for_review`), payment intent reference (nullable, unique), confirmation/review timestamps. Unique `(collection_id, household_id)` prevents two obligations for one household.

### Payment receipt reference

`payment_intent_id` (unique), obligation (unique), gross/fee/currency values copied from the confirmed shared Payment event, confirmation/correlation identifiers. It is an idempotent domain reference, not an account, balance, journal or alternative receipt.

### Refund reference and late review

Refund rows reference P15 refund IDs and statuses without executing refunds. Late review stores confirmed intent, event time, reason, evidence reference, disposition state (default `unresolved`), and reviewer/decision references. No disposition is available until policy approval.

### Closure record

Collection/cutoff, closed by/time, snapshot version, aggregate confirmed gross, fee total, confirmed refunds, net support, pending/unknown/late/reconciliation liabilities, settlement state, payout state, report reference, carry-forward approval and archive time.

## Independent state dimensions

| Dimension | Authority | P24 representation |
|---|---|---|
| Payment intent/attempt/capture/accounting | Shared Payments | Reference and read model; never write Payment rows |
| Domain fulfilment | CommunityFuneral | Obligation `fulfilment_state`; once-only transition |
| Refund | Shared P15 | Refund reference/status; no P24 execution |
| Fee | Payment confirmation/fee snapshot | Event amount for aggregation; no recalculation |
| Provider settlement | Shared P17 | Independent status/reference |
| Payout request/approval/execution | Shared P18 | P18 reference/state; actual execution currently blocked |
| Net held support/liability | Derived aggregate | Gross − confirmed fees − confirmed refunds; descriptive only, not wallet balance |

