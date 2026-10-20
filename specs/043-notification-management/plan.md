# Implementation Plan: Notification Management
Date: 2026-10-15 | Feature: 043-notification-management | Spec: [spec.md](spec.md)
## Summary
Reuse E17 templates, notifications, per-channel delivery jobs and consent. Add Admin application service and thin validated controller, scoped permissions, durable action/outbox, and Next.js pages.
## Technical Context
PHP 8.4 Laravel; PostgreSQL notification connection; Redis/Horizon; Next.js TypeScript shared components.
Tests: transaction-based PHPUnit/Pest, Jest, Playwright Chromium; no external sends.
Scope: three P1 stories. Bounded 25-row delivery pagination and validated filters; no invented production latency SLA.
## Constitution Check
PASS before/after design: approved source and stack; mandatory delivery/consent preserved; no financial mutations; server RBAC and existing MFA unchanged; UUIDv7, UTC, audit and correlation; no credentials/content in audit; reliable domain outbox; accessible shared UI; regression and security checks required before completion.
## Structure
Backend: wallet-ecosystem/services/api/app/Modules/Notification/{Application/Services,Application/Jobs,Infrastructure/Http/Controllers,Infrastructure/Migrations}; routes/modules/admin-notifications.php; openapi/v1/admin-notifications.yaml.
Frontend: wallet-ecosystem/apps/admin/src/modules/notifications/ and app/(admin)/notifications/.
Evidence and traceability: specs/043-notification-management/.
## Phases
0 Research compatibility, mandatory policy, concurrency and legacy language.
1 Add nullable template language, integer revision, optional channel snapshot, rules and action/outbox tables. No role assignments or template copy invented.
2 US1 template editor/preview/self-test API and UI; optimistic revision conflicts.
3 US2 paginated log/status/date filters and durable failed-row retry. Serialize channel job execution with PostgreSQL advisory lock; skip completed rows to prevent concurrent duplicate provider dispatch.
4 US3 channel rules, mandatory protection, durable NOTIFICATION_RULE_UPDATED; creation snapshot and existing preference intersection.
5 auth/notification regression, browser visual checks and AC convergence.
## Dependencies
E17 schema and jobs, Identity permission resolver, ADM foundation. Local PostgreSQL and Chromium available. Providers log only. Claude quota blocked; user explicitly authorized Codex.
## Complexity Tracking
Domain-owned outbox required because Platform publisher's transaction is a separate connection. No architecture exception.
