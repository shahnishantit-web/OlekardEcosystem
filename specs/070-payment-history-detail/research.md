# Research: P23 — Payment History, Detail and Transaction Management

**Date**: 2026-10-26
**Branch**: `070-payment-history-detail`

---

## R-001: Upgrade vs New Shared Package

**Decision**: Upgrade existing `transactions/` modules in-place in each app. No new shared package.

**Rationale**: Customer and agent transactions differ semantically — customer sees own wallet history; agent sees agent-scoped view (float, commission, assisted cash-in). `packages/shared-payment` (E69) owns checkout; this feature is read-only consumption of a separate API. A third shared package would require pubspec workspace changes and cross-app coordination unrelated to this spec.

**Alternatives Considered**:
- `packages/shared-payment-history/` — rejected: app-scoped domain entities; maintenance overhead exceeds benefit for a read-only module with app-specific display logic.

---

## R-002: Refund Eligibility Source

**Decision**: `refund_eligible: bool` comes directly from `PaymentHistoryItem` in the P19 `GET /v1/payment/history` response. No separate eligibility endpoint.

**Rationale**: Confirmed in `specs/066-payment-receipts-history/contracts/payment-history-api.yaml` schema — `PaymentHistoryItem` includes `refund_eligible: {type: boolean, description: "Whether a refund can be initiated (domain-dependent, for mobile US5)"}`. The flag is server-authoritative; the mobile app must not locally override it.

---

## R-003: Refund Request API

**Decision**: Refund requests route to E62 `POST /v1/payment/refunds` (`requestRefund` operationId).

**Rationale**: E62 Shared Refund Engine (COMPLETE/RELEASE READY, E62) exposes `requestRefund`. Mobile app posts `{payment_intent_id, reason, amount_minor?}`. E62 owns the state machine; mobile receives resolution via push notification (E17) or polls detail endpoint. The pending-vs-confirmed distinction is enforced by E62 state — not locally.

---

## R-004: Support Ticket API

**Decision**: Support tickets use E22 `POST /v1/support/tickets` (`createSupportTicket`) with `payment_intent_id` as stable reference. Attachments use E22 `attachEvidence` (signed URL upload). Permitted types: PDF, PNG, JPEG.

**Rationale**: E22 Support & Dispute Management (COMPLETE/RELEASE READY) has both operations. Pre-filling the subject from the payment description provides continuity. Attachment MIME type is enforced both client-side (picker filter) and server-side — client check is UX-only.

---

## R-005: Encrypted Cache Strategy

**Decision**: `flutter_secure_storage` ^11.2.0 (already in customer + agent app pubspec) with JSON-serialised `PaymentCacheEntry` records. Keys: `payment_history_{userId}` (list) and `payment_detail_{intentId}` (detail). Full eviction: delete all keys matching `payment_` prefix on logout or account switch.

**Rationale**: `flutter_secure_storage` uses Android Keystore / iOS Keychain (AES-256 at rest). E03 session management emits a logout event bus signal; this module subscribes and calls `clearPaymentCache()`. Stale cache is shown with a banner (`isStale: true`) and a timestamp — not silently served as fresh.

**Alternatives Considered**:
- `shared_preferences` — rejected: not encrypted, violates constitution P6.
- SQLite + sqlcipher — rejected: over-engineering for a cache; no relational queries needed.

---

## R-006: Receipt Download Token Handling

**Decision**: The `download_url` from `POST /v1/payment/receipts/{intentId}/download-token` is single-use and must never be stored in cache or logged. The app opens the URL via `url_launcher` immediately; it does not store the token beyond the HTTP response lifetime.

**Rationale**: P19 contract confirms `GET /v1/payment/receipts/download/{token}` requires no auth header — the token IS the credential. Storing it creates a time-window credential exposure window. Token expires in ~60 s (`expires_at` field). PIN/step-up token must never appear in any log line (applies here too).

---

## R-007: Domain Deep Link Rendering

**Decision**: `domain_deep_link` in `PaymentHistoryItem` may be a URL string or `{status: "unavailable", reason: "domain_retired"}` object. App renders a tappable tile for a URL string; a greyed-out "unavailable" label for the object form.

**Rationale**: P19 API documents both forms. GoRouter `go()` is used for in-app deep links (same-app scheme); `url_launcher` for external https URLs. Unknown formats are treated as unavailable — no crash.

---

## R-008: Screenshot Protection (Receipt Screen)

**Decision**: Add `flutter_windowmanager` ^0.2.0 to customer and agent app pubspec for `FLAG_SECURE` (Android) / `SecureScreen` (iOS) on `ReceiptScreen`. This package is not yet in the dependency tree.

**Rationale**: Receipt screen shows full transaction amounts and bank references — sensitive financial data. FR-012 mandates screenshot guard. `flutter_windowmanager` is the standard package for this purpose.

---

## R-009: GoRouter Route Registration

**Decision**: History, detail, and receipt routes are registered as shell routes in each app's top-level router config. The transactions module exports route path constants (`PaymentRoutes.historyPath`, etc.) only.

**Rationale**: GoRouter requires centralised registration; modules cannot self-register routes. Constants are exported to avoid string duplication between the router config and any `context.go()` call-sites.

---

## R-010: Agent App Transaction Scope

**Decision**: Agent app uses identical P19 API endpoints with the agent's JWT, which causes the server to return agent-scoped records (float top-ups, commissions, assisted cash-in). No separate agent-only endpoint.

**Rationale**: P19 is purpose-typed via `purpose_type` filter; agent JWT scopes the server response automatically. Agent transaction detail uses the same status-row structure with agent-specific purpose labels.

---

## Resolved Unknowns Summary

| Unknown | Resolved? | Resolution |
|---------|-----------|------------|
| `refund_eligible` source | Yes | P19 `PaymentHistoryItem` field |
| Refund submission API | Yes | E62 `requestRefund` |
| Support submission API | Yes | E22 `createSupportTicket` + `attachEvidence` |
| Encrypted cache library | Yes | `flutter_secure_storage` (already in tree) |
| Screenshot protection library | Yes | `flutter_windowmanager` ^0.2.0 (must add) |
| GoRouter deep-link registration | Yes | App-level; module exports constants |
| Agent app scope | Yes | JWT-scoped P19 API; no separate endpoint |
| Download token lifecycle | Yes | Single-use; open immediately via url_launcher; never cache |
