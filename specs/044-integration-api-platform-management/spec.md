# Feature Specification: ADM-P1-020 Integration & API Platform Management
Created: 2026-10-15
Status: Approved-source implementation scope
Source: docs/admin-p1p2-epic-catalogue.md ADM-P1-020; specs/025-api-integration-platform/spec.md.
Execution: Claude quota HTTP429; continue using the user's approved Codex + Spec Kit fallback.

## User Scenarios & Testing
### US1 — US-P1-020-001 API client credentials (P1)
Admin navigates /integrations/providers, chooses a partner and New API Client, supplies name, permissions, IP whitelist and existing rate limit, and receives secret once.
Independent test: create for partner, verify hash-only storage, authenticate from allowed IP, rotate and reject old secret, revoke and reject all access.
- AC1: client creation accepts name, permissions, partner and IPv4/IPv6 IP/CIDR allowlist.
- AC2: cryptographic secret is shown only by creation/rotation, stored hashed and never logged or persisted in browser storage.
- AC3: rotate invalidates old secret immediately; revoke prevents authentication and rotation cannot reactivate revoked clients.
### US2 — US-P1-020-002 Webhook deliveries (P1)
Admin navigates /integrations/webhooks and sees event, endpoint, Delivered/Failed/Retrying, attempts and last attempt.
Independent test: filter outcomes/event type and retry only failed/dead-letter callbacks without processing completed events twice.
- AC4: paginated log contains event, endpoint, status, attempts and last-attempt timestamp.
- AC5: status and event-type filters combine.
- AC6: failed callbacks can be manually retried reliably and audited; completed or queued callbacks cannot.
### US3 — US-P1-020-003 Provider health (P1)
Admin navigates /integrations and sees integration health and last-checked timestamp, then View Health.
Independent test: two same-type partners have separate samples, 24 hourly buckets and recent error log; unobserved periods remain unknown.
- AC7: list shows Healthy/Degraded/Down from actual integration-scoped probes with timestamp.
- AC8: View Health shows a 24-hour observed-uptime chart and recent safe error records.

## Edge Cases
Unknown/inactive partner; invalid permissions/IP/CIDR; empty allowlist preserves unrestricted legacy policy; concurrent rotate/revoke and duplicate retry; queue outage; no samples; same-type partners; missing adapter/probe; invalid webhook signature; stale automatic retry; secret-bearing provider errors.

## Requirements
- FR001: implement AC1–3 with existing credential hashing/auth/service and permission integrations.manage.
- FR002: implement AC4–6 using existing E25 callback events/jobs; preserve original endpoints/contracts.
- FR003: implement AC7–8 using existing Ops probes and explicit module contract.
- FR004: preserve existing authentication/MFA, provider management, permissions and business flows; no ledger mutation or invented provider policy.
- FR005: audit privileged mutations, redact secrets, validate identifiers and bound queries.
- FR006: accessible shared Admin layout with loading/error/empty states and keyboard controls.
- FR007: reliable, idempotent webhook retry and completed-processing guards.

## Key Entities
Existing ApiClient plus partner link/IP allowlist/revoked timestamp.
Existing WebhookEvent and processing attempts; durable Admin retry intent.
Existing Integration and Ops ProviderHealthCheck plus nullable integration identity.
Domain audit records; existing Platform outbox for credential events.

## Success Criteria
SC001: eight mandatory acceptance groups pass reproducible tests.
SC002: unauthorized, denied IP, rotated-old-secret and revoked credential requests have zero successful outcomes.
SC003: relevant Integration, Notification and Admin authentication regression passes or failures are reported explicitly.

## Dependencies, Clarifications and Scope
E25, ADM-FOUND-001 and existing Ops health infrastructure. Preserve integrations.manage per E25 FR007a; no unsolicited permission split.
E25 webhooks are inbound callbacks. Delivered means processed by the platform; Endpoint is the existing receiver /v1/webhooks/{provider_slug}, not an invented outbound destination.
Provider selection remains TBD; do not add adapters or credentials. A provider with no attributable probe is Not checked with null timestamp, not falsely Healthy. Historical type-only checks are not attributed to arbitrary partners.
The chart covers 24 UTC hourly buckets; observed uptime is percentage of healthy probe samples, with missing buckets explicitly unknown, not fabricated continuous availability.
Empty IP whitelist preserves current unrestricted access; entered addresses/CIDRs are enforced server-side. Revocation is terminal for the new revoke action; legacy active/inactive APIs remain compatible except they cannot revive revoked credentials.
