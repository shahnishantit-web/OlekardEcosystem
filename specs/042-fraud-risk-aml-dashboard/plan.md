# Implementation plan — ADM-P1-018
## Technical context
Existing Laravel/PHP 8.4+, PostgreSQL, Redis, Next.js/TypeScript/shared components. Add thin /v1/admin/security adapters over existing Risk application services. Preserve legacy APIs. No new framework/packages.
## Constitution check
PASS by design: approved intent, no invented thresholds, configuration reused, no ledger/balance writes, server authorization and independent checker, thin controllers/services, UUIDv7/correlation/durable events, auth/MFA retained, accessible UX and release evidence required. Re-evaluate after implementation. No extensions.yml hooks exist.
## Phase 0 research
See research.md. Required planning research agent used. Actual backend contracts inspected; API completeness assumptions rejected.
## Phase 1 design
See data-model.md and contracts/security.md. Append-only notes/links. Versioned alert locking. Maker requests freeze, separate authenticated permitted checker approves; backend derives checker/subject. Durable event and existing notification integration. Findings enum/constraint extended compatibly.
## Structure/order
Tests first, additive migration/permissions, InvestigationService/AdminSecurityController/routes, findings extension, typed API and queue/detail/findings pages, regression/browser/convergence.
## Validation
Use existing test DB and API container, never reset production. Jest, TypeScript/lint, Risk/Security/auth regressions, Playwright screenshots and real API feature tests. Report environment or pre-existing failures explicitly.
