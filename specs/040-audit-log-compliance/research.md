# Research: ADM-P1-016 — Audit Log & Compliance

**Date**: 2026-10-14

## Decision 1 — API path for audit events

**Decision**: Use `AdminAppClient.listAuditEvents()` → `GET /v1/admin-app/audit` (AdminAppClient BASE pattern).

**Rationale**: All admin-portal-specific API calls go through `AdminAppClient` with the `/v1/admin-app` prefix. The existing stub at line 646 of `admin-app-client.ts` already follows this pattern. The audit events page currently uses the raw `/v1/audit/events` path (E23 backend direct) — update to use the AdminAppClient wrapper for consistency and to benefit from typed responses.

**Alternatives considered**: Keep `/v1/audit/events` direct call — rejected because it bypasses admin RBAC middleware and the AdminAppClient abstraction layer.

## Decision 2 — Compliance dashboard API shape

**Decision**: Add `getComplianceDashboard()` → `GET /v1/admin-app/compliance/dashboard` returning `ComplianceDashboard` with three sub-arrays: `regulatory_checklist`, `open_items`, `retention_deadlines`.

**Rationale**: The existing `compliance-status.tsx` is a per-user lookup. US2 needs an aggregate dashboard. A single dashboard endpoint is more efficient than assembling from multiple calls client-side.

**Alternatives considered**: Assemble from multiple calls — rejected because it creates N+1 waterfall and exposes partial data if any call fails.

## Decision 3 — Compliance page strategy

**Decision**: Replace the body of `compliance-status.tsx` (`ComplianceStatusPage`) with the compliance dashboard implementation. The per-user lookup section is removed (it was not part of ADM-P1-016 ACs; it serves a user-management workflow that belongs on a user detail page).

**Rationale**: The route `/compliance` maps to `ComplianceStatusPage`. US2 explicitly defines this route as the dashboard. The existing per-user compliance lookup is not referenced by any ADM-P1-016 AC.

**Alternatives considered**: Keep per-user lookup, add dashboard above it — rejected because it would double the page scope and the AC never references per-user lookup.

## Decision 4 — Retention policy confirmation dialog

**Decision**: Use an inline confirmation state (no separate Dialog component dependency). When the admin clicks "Update Policy", set `pendingChange` state with before/after values; render a confirmation card/overlay; on "Confirm" call the API; on "Cancel" clear `pendingChange`.

**Rationale**: The project does not have a dedicated `<Dialog>` or `<Modal>` component that could be reused (not visible in the component inventory). An inline confirmation card (role="dialog", aria-modal) is accessible and self-contained.

**Alternatives considered**: Use browser `window.confirm()` — rejected because it is not accessible and cannot be tested in JSDOM. Import a new Dialog library — rejected because it introduces an unapproved dependency.

## Decision 5 — Urgency colour-coding for compliance dashboard

**Decision**: Map urgency to Badge variant: `high`/`overdue` → `error` (red), `medium`/`soon` → `warning` (amber), `low`/`ok` → `success` (green). Existing `Badge` variants cover all three states.

**Rationale**: The existing `Badge` component already uses `success`/`warning`/`error` variants matching the green/amber/red pattern. No new UI components needed.

## Decision 6 — RBAC gate pattern

**Decision**: Use `hasPermission(slug)` from `useAuth()` to gate both data-fetch and UI rendering. If the user lacks the required permission, show a static access-denied card (no API call made).

**Rationale**: Consistent with patterns already used in `report-schedule-list.tsx` and `fee-rules-management.tsx`. Prevents information disclosure even if the backend returns data.

## Decision 7 — Audit event filters — Actor and Entity Type

**Decision**: Actor filter = free-text input sent as `actor_id` query param. Entity Type filter = select from known categories (`wallet`, `user`, `transaction`, `agent`, `kyc`, `config`, `system`).

**Rationale**: The backend E23 spec lists `entity_type` as a filterable field. Actor is searched by `actor_id` (UUID or system actor string). Free-text is simplest; the backend does the filtering.

**Alternatives considered**: Typeahead actor search — too complex for P1 scope; deferred.
