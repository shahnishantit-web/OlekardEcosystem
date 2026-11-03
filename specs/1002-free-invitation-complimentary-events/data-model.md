# P26 Data Model

## Existing records reused

- `event_drafts`: authoritative event, organisation, lifecycle/version, capacity and event start/end.
- `event_draft_categories`: category inventory; gains explicit `allocation_type` (`paid`, `free`, `complimentary`) and optional `complimentary_quota`. Existing rows backfill as `paid`.
- `event_tickets`: shared credential, purchaser, event/category, status, attendance and audit anchor. Gains `entitlement_source` and optional `free_registration_id`; paid rows retain `order_id` and `order_line_id`, free rows have neither. A database constraint enforces mutually exclusive source links.
- `event_attendance` and its audit: unchanged; one online admission per ticket.

## New records

### `event_invitations`

`id`, `event_id`, `organisation_id`, `category_id`, `token_hash`, encrypted token for idempotent issuer recovery, idempotency key/request hash, required `recipient_actor_id`, `valid_from`, `expires_at`, `max_uses`, `uses_count`, `status`, nullable immutable `approval_snapshot` for complimentary allocations, `created_by_actor_id`, `revoked_by_actor_id`, `revoked_at`, timestamps. The token is never stored in plaintext or included in audit/analytics. Scope and audience are server-derived. `max_uses` is positive. The current production Identity verifier and complimentary-approval provider are deny-bound; active-recipient verification remains required before operational invitation issue.

### `event_free_registrations`

`id`, `event_id`, `organisation_id`, `category_id`, optional `invitation_id`, `attendee_actor_id`, `idempotency_key`, `request_hash`, nullable immutable `approval_snapshot` for complimentary allocations, status, `created_at`. Unique `(event_id, attendee_actor_id, idempotency_key)`. The category's explicit `max_per_person` controls repeat registrations; the authenticated actor ID is the only attendee field stored. Registration writes occur under event/category/invitation locks.

### Complimentary approval evidence

The Events port returns typed approval ID/version, organisation/event/category scope, approved quota, distinct requester/approver actor identifiers, validity interval, revocation time and provenance. Events rejects scope mismatch, missing provenance/identity, self-approval, insufficient quota, invalid/expired/revoked evidence. The immutable evidence snapshot is retained on invitation/registration and embedded in a versioned publication snapshot. These fields are evidence supplied by the port, not proof of Governance authority by themselves; production binding remains deny-by-default until an authoritative Governance verifier and operational evidence exist.

## Invariants

1. Existing and new capacity consumers share the event then category lock and compute available units against issued tickets and active paid inventory holds. Each free registration increments the existing `issued_quantity`, so it remains in the same capacity invariant. Invitation row is locked afterward.
2. `free`/`complimentary` ticket has a valid `free_registration_id`, null order/order-line and no Payment intent. `paid` ticket has non-null order/order-line and null free-registration.
3. A free registration creates exactly one ticket per admission entitlement, regardless of group registration quantity; each ticket receives its own opaque credential.
4. Invitation usage increments exactly once with the durable registration. Failed transaction rolls it back.
5. Free paths create no `event_orders`, Payment intents, Wallet changes, Ledger journals, settlement items, or refund requests.
6. Paid ticket authorization and Payment/refund checks remain unchanged.
7. Ticket admission remains exact event/gate scoped, online, once-only, credential-private, status/category-only, and audited.
8. No-show is computed only after the event's end time and is the count of active free/paid entitlements without an admission record; revoked tickets are excluded.

## Lock Order

`event_drafts` row → `event_draft_categories` row → `event_invitations` row → registration/ticket inserts. Invitation revocation must use the same event → category → invitation order to serialize against redemption. Gate scan continues to use gate/assignment/ticket ordering per P25; free registration does not acquire gate locks.
