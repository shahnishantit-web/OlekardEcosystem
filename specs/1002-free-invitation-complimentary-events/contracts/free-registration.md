# P26 Free Registration Contract

## Admin configuration

- Event manager supplies allocation type, event/category scope, quota, invite expiry, max uses, and a required recipient customer actor ID. Actor/event/organisation context is derived and checked server-side; Identity must verify the recipient is an active CUSTOMER before production issue.
- Invitation response returns the opaque token once to the authorized issuer. Persist only its SHA-256 digest; never return it through list/read APIs.
- Creation requires an editable event and the existing event-scoped authority. Production use additionally requires a complimentary-allocation approval contract that LC08 does not define; current production publication/sales gates stay closed.
- Complimentary invitation issue and registration call `ComplimentaryAllocationApprovalContract` for typed, exact organisation/event/category evidence, including distinct requester/approver, approval ID/version/provenance, quota, validity and revocation. Ordinary `free` allocations do not invoke T006. Evidence snapshots persist on complimentary invitations and registrations. The production implementation remains a deny-by-default 503 until an authoritative Governance approval source/verifier and operational authority evidence are supplied. Test-only fixtures do not satisfy that contract operationally.

## Redemption

`POST /v1/events/{eventId}/free-registrations` with `Idempotency-Key`; request carries category and an optional invitation token. The authenticated CUSTOMER actor is derived from Identity context and is the only attendee data stored. No name, contact, price, payment state, organisation or owner can be supplied by the client.

Success returns a registration ID and entitlement summary, not an authorization token or another attendee's information. The ticket credential is retrieved via existing purchaser-scoped My Tickets endpoints.

Failure returns a generic non-disclosing result for invalid, expired, exhausted, revoked, recipient-mismatched invitation, or sold-out allocation. No writes survive a failure.

## Admission and reporting

Gate validation reuses `POST /v1/events/{eventId}/gates/{gateId}/validate`. The ticket source comes only from the persisted row. `free` or `complimentary` source bypasses payment/refund lookup; `paid` still requires authoritative Payment/refund checks. Response remains status and category only.

Aggregate organizer reporting includes `free_registrations`, `paid_sales`, `unique_admissions`, compatibility alias `admitted_count`, `no_shows`, and `no_shows_finalized`; no identities or ticket identifiers. No-show counts are unavailable until event end. `paid_sales` is valid issued paid-ticket count, not revenue.

## Explicit non-contracts

- No Payment intent, capture, journal, refund, settlement, payout, zero-value accounting event, or synthetic “captured” state.
- No anonymous registration, transfer, re-entry, or offline validation.
- No automatic revocation of issued entitlements when an invitation is revoked.
- No cancellation notice claim until the shared cancellation lifecycle and notification template/delivery tracking are integrated.
