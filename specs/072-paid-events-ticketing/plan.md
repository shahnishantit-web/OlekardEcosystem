# P25 Implementation Plan — PARTIAL / REVIEW GATES OPEN

**Source:** `spec.md`, `research.md`; architecture v1.1 LC07 and section 2.  
**Workflow gate:** the installed workflow's review/approval gates remain open for policy-dependent flows. The independent technical slice below is implemented; it is not authority to enable payment or admission journeys.

The user explicitly authorised proceeding past the unchecked-checklist confirmation for independent scope. Draft-only schema and validation now exist behind a deny-by-default authority, including positive order limit validation, Finance-owned currency assignment boundary, and strict zoned-time/sales-window validation (T017–T020). This does not approve unresolved decisions or complete the plan gate.

## Technical Context

Existing Laravel modular monolith, PostgreSQL, shared Payment/Wallet/Ledger/E25, Next.js and Flutter. Events is a separate business domain. Tenant and operator scope, admission and financial policies are unresolved; no new provider, role or accounting contract is inferred.

## Constitution Check

| Principle | Status | Evidence or gap |
|---|---|---|
| Source truth and TBD governance | PARTIAL | User-selected directions are recorded; approved numerical/business decisions and owner evidence are itemized in `research.md`. |
| Financial integrity and event reliability | BLOCKED | No live financial flow added; hold/confirmation/refund contracts and P18 payout stubs remain gates. |
| Identity, zero trust and audit | PARTIAL | Default authorizer denies all; runtime permission identifiers/contracts and independent security/retention evidence remain missing. |
| Module ownership and compatibility | DESIGN PASS | Shared Payments, Wallet, Ledger and E25 preserved; no duplicate integration. |
| Test, release and operations | PARTIAL | Focused draft tests and local migration pass. Last-ticket/scan concurrency, API/UI, integrated finance, and independent review remain open. |

## Dependency Order

1. Obtain versioned policy and security decisions listed in `spec.md`; review predecessor runtime contracts and legal/finance approvals. Complete requirements checklist and plan gate.
2. Specify Events authoring, publication and scoped authority; design event/category/hold/order state and a server-owned Payment purpose. Review transaction and idempotency boundaries.
3. Implement bounded inventory reservation and approved hold expiry with real concurrent PostgreSQL tests. Integrate the existing quote and checkout contract without duplicating Payments.
4. Implement durable post-accounting confirmation consumption, ticket issuance/retry and reconciliation; test crash, replay and event ordering using authoritative Payment evidence.
5. Implement online admission with scoped identity and durable once-only admission, auditing and concurrency tests. Offline remains separately gated.
6. Implement approved late-capture and refund decisions, event closure and shared payout request only after dependent contracts and financial approvals exist. Keep unknown outcomes unresolved.
7. Add admin/mobile/gate surfaces, accessibility, API contract, runbooks, migration and full regression/security/finance acceptance.

## Transaction and Recovery Design Questions

Reserve and admission need database-enforced concurrency with explicit isolation. Confirmation delivery and ticket issuance require durable inbox/outbox and replay under a stable payment/order reference. The exact hold-expiry and reacquire-or-refund transition, revocation behavior during refund, accounting threshold and payout posting require approved rules before a design can be finalised. No implementation assumes them here.

## Independent implementation completed

T020 is a narrow draft-validation implementation. UTC instants must be supplied with an explicit offset matching the chosen IANA zone; ambiguous/nonexistent daylight-saving times are rejected, event end follows start, and sales close by start. The service requires a positive proposed event-level order maximum and discards organizer-supplied currency (Finance/platform assignment remains a separate future capability). This does not implement the authoring UI/API, publication, currency assignment, inventory, quote, checkout or any ticketing flow. Details and verification are in `evidence.md`; remaining policy/contract gates are in `research.md`.
