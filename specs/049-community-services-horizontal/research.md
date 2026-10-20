# Research: E49 — Community Services Platform Horizontal Foundation

**Date**: 2026-10-19
**Status**: Complete — all questions resolved

---

## Decision 1: Module namespace and schema isolation

**Decision**: New `App\Modules\CommunityServices\` namespace in Laravel; dedicated `community_services` PostgreSQL schema.

**Rationale**: Every existing mature module (Wallet, Notification, Support, Ledger) uses the `Application/Domain/Infrastructure/Providers` sub-structure. Consistent with schema-per-module pattern established in the constitution and used from E06 onwards. Isolates community services tables from wallet tables and enables independent extraction.

**Alternatives considered**:
- Flat module (no sub-structure): Rejected — does not match existing pattern; harder to extract.
- Separate microservice: Rejected — premature per constitution P14; modular-monolith baseline.

---

## Decision 2: Tenant isolation enforcement strategy

**Decision**: `ScopedToOrganisation` query scope interface applied to all CommunityServices Eloquent repositories; scope is set from the authenticated user's resolved membership context, not from request payload.

**Rationale**: Existing wallet modules use Eloquent global scopes for multi-tenancy. Codifying this as an interface (rather than a trait alone) makes it testable and ensures every repository is audited for compliance. Server-side resolution prevents privilege escalation via client-supplied tenant IDs.

**Alternatives considered**:
- Trust `organisation_id` from request body: Rejected — violates P5 (no trust in client-provided role or tenant values).
- Row-level security in PostgreSQL: Considered valid but not used elsewhere in the codebase; consistent with existing approach to avoid introducing a new mechanism.

---

## Decision 3: Order / payment orchestration pattern

**Decision**: `PaymentOrchestrator` service in `CommunityServices\Order\Application` wraps the existing `Payment` module's `PaymentGateway` contract. Order state machine is owned by CommunityServices; the Payment module owns payment attempt lifecycle.

**Rationale**: The existing `Payment` module (E08) already exposes a `PaymentGateway` contract that handles provider interactions, idempotency and webhook ingestion. Wrapping it avoids duplication and ensures that wallet/ledger posting continues to go through the approved path. The `Order` state machine is a CommunityServices concern (its states are different from wallet transactions).

**Alternatives considered**:
- Directly call wallet `FundingService`: Rejected — wallet funding is agent/bank-specific; E06/E07 contracts are for wallet-to-wallet operations.
- Build a standalone payment processor: Rejected — duplicates E08; violates P4 (only wallet/ledger owner posts entries).

---

## Decision 4: Idempotency guard implementation

**Decision**: `IdempotencyGuard` service stores `(actor_id, operation_type, idempotency_key, request_hash, result_payload, expires_at)` in a `community_services.idempotency_records` table. Guard is invoked at the top of every state-changing API controller action.

**Rationale**: Identical pattern to E08 (Wallet Funding) which uses an `IdempotencyRecord` model. Reusing the same approach ensures consistency. The `request_hash` (SHA-256 of canonical request body) allows detecting same-key/different-payload conflicts per spec FR-HZ06-003.

**Alternatives considered**:
- Redis-only idempotency: Rejected — Redis is not durable enough for financial idempotency records; database is authoritative.
- Middleware-only: Rejected — middleware cannot capture the result payload for replay; service-layer guard can.

---

## Decision 5: Maker-checker approval service

**Decision**: New `CommunityServices\Approval\Application\ApprovalService` manages `ApprovalRequest` records with a serialisation lock (pessimistic lock on `approval_requests` row during approval execution). Supports configurable approval chains (single approver, dual-approver) via `policy_type` configuration.

**Rationale**: No general-purpose maker-checker service exists in the codebase today. The `AdminApp` module has specific approval workflows but they are domain-specific to the admin portal. A general `ApprovalService` in CommunityServices can serve E50 (payout approval), E51 (event settlement) and E52 (draw execution) without duplication.

**Alternatives considered**:
- Reuse AdminApp approval logic: Rejected — AdminApp approval is tightly coupled to admin portal domain objects.
- Build per-domain approval in E50/E51/E52: Rejected — creates three implementations of maker-checker; violates DRY and makes the horizontal "backbone" incomplete.

---

## Decision 6: Webhook ingress and signature verification

**Decision**: `WebhookIngressService` in `CommunityServices\Partner\Application` handles all incoming partner webhooks. Steps: (1) verify HMAC-SHA256 signature against raw body + timestamp; (2) check timestamp freshness (configurable window, default 5 min); (3) write `WebhookReceipt` record for deduplication; (4) dispatch async job for domain processing.

**Rationale**: Existing `Integration` module (E25) has a similar webhook pattern. Aligning with that approach. The `WebhookReceipt` table provides the deduplication anchor (provider + event_id unique constraint) as required by FR-HZ12-004/005.

**Alternatives considered**:
- Reuse E25 Integration module webhook handling: Considered. E25 handles third-party API integrations at the platform level (BTC, etc.). CommunityServices partners may be different entities with different contracts. A dedicated ingress within CommunityServices avoids coupling to the Integration module's internals while still following the same pattern.

---

## Decision 7: Transactional outbox for domain events

**Decision**: Extend the existing `Notification` module's outbox infrastructure. CommunityServices domain events (`Payment.Confirmed`, `Ticket.Issued`, etc.) are written to the `notification_outbox` table (or a parallel `community_services.domain_events` table if the existing outbox is notification-specific).

**Rationale**: The existing E17 Notification module implements a transactional outbox. Checking the `Notification` module's `DeliverInAppNotificationJob` confirms the pattern. If the existing outbox is tightly coupled to notification templates, a parallel `community_services.domain_events` outbox table (same schema pattern) is used; if it's generic, we emit into it directly. Either way, the outbox pattern is followed per P9.

**Resolution**: Use a new `community_services.domain_events` outbox table to avoid coupling to notification template semantics, with the same `ProcessDomainEventJob` dispatch pattern. Notification delivery continues through E17's outbox.

---

## Decision 8: Admin panel module structure

**Decision**: New modules under `wallet-ecosystem/apps/admin/src/modules/community-services/` following the flat sub-folder pattern (no nested `components/pages/services` — each module folder contains all three). Consistent with existing module pattern observed in `agents/`, `audit/`, `fees/`.

**Rationale**: All existing admin modules are flat directories. Introducing a sub-structure would break the existing pattern and require updates to the module-loader conventions.

---

## Decision 9: Flutter navigation extension

**Decision**: Add `Community` and `Events` tabs to the existing bottom navigation bar. The current bar has Home, Wallet, Transactions, and More. Adding Community (index 2) and Events (index 3) and shifting Wallet/Transactions as needed.

**Rationale**: Spec HZ14 requires: Home, Community, Events, My Tickets, Activity and More. The existing navigation (E36 Unified Layout) will need extending. The spec explicitly names these 6 tabs; this is a required change, not a cosmetic preference.

**Alternatives considered**:
- Add Community/Events under the existing "More" menu: Rejected — spec explicitly names these as top-level navigation items.

---

## Decision 10: Migration numbering for E49

**Decision**: E49 migrations use prefix `2026_10_19_049NNN` where NNN is 000–999. The last wallet-ecosystem migration is `2026_10_05_000006`. The `049` infix identifies the epic.

**Rationale**: Consistent with existing migration naming convention where the epic number appears in the migration filename (e.g., `2026_10_01_017000` for E17 notifications).

---

## Decision 11: WCAG 2.2 AA tooling

**Decision**: axe-core via `@axe-core/playwright` for admin Next.js E2E tests; `flutter_accessibility_tests` package for Flutter. Both already available in the project's test dependencies (confirmed by E35 Unified Design System).

**Rationale**: E35 established WCAG testing tooling. E49 reuses the same setup rather than introducing new tools.

---

## Open Items (deferred to E50–E52 or business owners)

| Item | Owner | Why deferred |
|---|---|---|
| Statutory data retention durations | Business/Legal owner | Must not be invented per P2 |
| BWP step-up threshold for community services | Business owner | May differ from wallet step-up (BWP 10,000); reuse existing `auth.step_up_threshold` config pending approval |
| Raffle jurisdiction whitelist | Compliance owner | E52 scope; remains disabled until E52 |
| BTC partner API documentation and sandbox credentials | Integration owner | Required before live connector; E52+ scope |
| Offline gate mode risk acceptance | Event operations/security owners | E51 scope |
