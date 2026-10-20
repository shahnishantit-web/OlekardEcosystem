# Implementation plan — ADM-P1-022
## Technical context
Laravel PHP8.4 / PostgreSQL platform connection, existing E29 repositories, E28 ConfigurationChangeRequestService, Next.js token components. No new framework. Feature 046-multi-currency-fx-management.
## Constitution check
PASS design: no ledger mutations, existing identity untouched, server RBAC, audited atomic configuration, UUID idempotency and advisory locks, decimal strings. Provider vendor remains TBD. Important configuration persists with E28 immutable versions; no new integration events required.
## Design
Extend existing rates with source/provider/observed_at; preserve legacy rows as manual. Reuse E28 submit/approve for financial changes and verify approval before activation. Currency status changes also E28; precision remains immutable through these endpoints because the existing quote engine assumes two minor-unit decimals. New currencies are registered inactive with supported precision and audited; enable through approval. Serialize requests by idempotency and pair/currency under PostgreSQL transaction advisory locks. Effective rates activate at approval (future legacy dates rejected until due). Provider contract fails closed when unconfigured; explicit provider ingestion must never replace a manually locked pair. History uses existing immutable rate rows, paginated table and 30-day active-rate chart. API aliases from catalogue coexist with existing E29 URLs. UI uses same authenticated client and existing permissions; add checker controls gated by configuration.approve.
## Structure
MultiCurrency Domain/Contracts/FxRateProviderInterface; Infrastructure/Providers/UnavailableFxRateProvider; additive migration; Application/Services/AdminFxManagementService and hardened FxRateAdminService; thin Admin controllers. Existing multi-currency pages extended.
## Verification
Backend integration tests for queues, currency approvals, same-maker rejection, decimal validation, provider failure/manual lock, history, auth, idempotency. Unit provider validation, frontend interaction tests, browser fixtures desktop/mobile, TypeScript, lint, PHPStan scoped, existing E29 regression. Inspect existing destructive tests before execution.
## Dependencies / blocked
E28 available; E29 service exists with inherited approval and replay gaps to repair. ADM014 FX fee engine unchanged (spread remains existing fractional charge; no duplicate fee). Live FX vendor/credentials are unapproved/TBD, so AC05 live provider convergence may remain blocked and epic PARTIAL.
