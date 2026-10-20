# Implementation Plan: ADM-P1-020
Date: 2026-10-15 | Spec: [spec.md](spec.md)
## Summary
Extend existing E25 services and Admin pages. Preserve public APIs and existing integrations.manage permission. Add missing create/partner/IP/revoke capabilities, callback log aliases and safe retry, and integration-scoped Ops health history.
## Technical Context
Laravel/PHP8.4, PostgreSQL platform connection, Redis/Horizon; Next.js/TypeScript shared Admin components. Tests: transaction-based backend, Jest, Chromium; no live provider calls.
## Constitution Check
PASS pre/post design: approved stack/source; no invented provider selection or SLA; hash-only secrets/no-store responses; server RBAC/MFA unchanged; audit and durable outbox; explicit Integration↔Ops contracts; UUIDv7/UTC; bounded pagination and accessible controls. No financial mutation.
## Structure
Existing services/api/app/Modules/Integration/{Application,Infrastructure} and Ops; new admin-integration.php route aliases and OpenAPI.
Existing apps/admin/src/modules/integrations/pages and app/(admin)/integrations; add API wrapper and health detail.
## Design
Append optional partner/IP fields to CreateApiClientData; store allowlist, integration_id, revoked_at; enforce middleware IP/CIDR with Symfony IpUtils. Keep legacy clients unrestricted. Reuse service create/rotate; serialize rotate/status/revoke via row locks; revoked terminal.
New Admin controller delegates client mutations and returns explicit safe resources/no-store. Existing endpoints remain.
Webhook receiver semantics explicit. Platform transaction retry-intent table; worker locks + retry generation guard protect manual/automatic jobs. Preserve original receive, domain handlers and exponential retry behavior.
Ops health model gains nullable integration_id. Integration inventory contract provides active registered adapters without probing arbitrary URLs. Existing Ops collector uses inventory to probe actual integration adapters; old type-only history remains unassigned. Health history contract returns bounded 24h per-integration buckets and safe structured errors, no raw provider messages/config.
Unknown/stale data labeled explicitly; no fabricated health. Health row no-sample can be Not checked. Existing default/status provider actions remain available.
## Phases
Foundation/schema/contracts → US1 tests and credentials/UI → US2 tests and callback reliability/UI → US3 collector/history/UI → regression/security/browser → convergence/artifacts.
## Dependencies
E25 schema, Ops health schema, Platform outbox/audit, Identity permission, installed Node/Chromium. Concrete providers/probe adapters remain deployment-owned; none are invented.
