# ADM-P1-018 — implementation status and acceptance traceability

Date: 2026-10-15. **Epic status: COMPLETE for the four approved mandatory stories.**
Completed: US-P1-018-001, 002, 003, 004. Pending mandatory stories/tasks: none.
Acceptance: **9/9 criterion groups PASS**. Definition of Done: **PASS for this implementation scope**.
No next epic, commit, push, or production deployment was performed.

## Implementation

- Permissioned paginated risk queue at /security/dashboard; severity and Open/In Review/Resolved filters map to existing Risk statuses.
- Investigation page /security/alerts/{id}: real persisted trigger description, transaction references, entity history, chronological scores (including unlinked historical evaluations), assignment, append-only notes, related links, false-positive/confirmed-fraud decisions and escalation.
- Freeze uses a maker request and a separate authenticated permitted checker. Account subject is derived from the alert; wallet/user owners resolve through existing module contracts. A real existing Risk account hold is applied, preventing risk-approved spending. No balances or ledger records are mutated.
- Version/row locking protects investigation decisions; subject advisory locking in the shared hold service protects simultaneous freezes through both new and legacy callers. Approval replay returns the existing result. Cache population occurs after transaction commit.
- Domain-owned audit records and ACCOUNT_FROZEN_AML outbox rows commit with the freeze. Scheduled delivery retries preserve events across receiver failures. Notification module listener uses its own transaction and existing deduplication, templates, preferences and delivery jobs.
- Notification is enabled by an active security.account_frozen_aml template; variables are occurred_at and support_ref. Channels use existing E17 configuration. No new business thresholds, mandatory messaging defaults, or automatic notification opt-in were invented.
- Findings supports CVE identification, Acknowledged, In Remediation and Resolved while preserving suppression. Existing findings counts remain at /security/overview.
- Existing E18/E26 endpoints, authentication, MFA and permission boundaries retained; new Admin adapters documented in openapi/v1/admin-security.yaml.
- Required regression scanning exposed pre-existing event-class load errors. TransactionReversedEvent now implements its existing interface without changing constructor/public data. AML restriction events use a separate private timestamp field while preserving named constructor arguments, getters and payloads. Contract tests cover these repairs.

## Approved criteria

Source: docs/admin-p1p2-epic-catalogue.md, ADM-P1-018, the four stories requested by the user.

| Story / criterion group | Result | Evidence |
|---|---|---|
| 001 AC1: security.alerts.read; queue Severity, Type, Entity, Created, Assigned To | PASS | Backend queue permission/filter test, denied read-only mutation test; Jest queue columns/denial; browser queue |
| 001 AC2: severity and Open/In Review/Resolved filters | PASS | Backend grouped filter test, Jest filter/page query checks, browser severity filtering |
| 002 AC1: trigger, affected transactions, entity history, risk-score timeline | PASS | Persisted linked transaction plus older unlinked evaluation integration test; browser investigation and screenshots |
| 002 AC2: self assignment, notes, related alerts | PASS | Backend durable mutations/stale version/self-link rejection; Jest API assertions; browser persisted UI states |
| 002 AC3: resolve false-positive/confirmed-fraud or escalate | PASS | Backend both outcomes/invalid outcome/terminal-state checks; browser escalation and confirmed fraud |
| 003 AC1: investigation freeze, ACCOUNT_FROZEN_AML and audit | PASS | Independent maker/checker, forged checker denial, wallet-owner derivation, hold/outbox/domain-audit assertions, real central audit test, rollback/cache and duplicate-account tests; browser checker approval |
| 003 AC2: notification if configured | PASS | Real notification persistence and delivery-job dispatch with active template, dedup/retry tests; inactive/unconfigured path does not force delivery |
| 004 AC1: findings.read, CVE/Severity/Component/Status/Discovered | PASS | Backend CVE resource and permission tests; Jest CVE/read-only view; browser findings table |
| 004 AC2: Acknowledged/In Remediation/Resolved | PASS | PostgreSQL enum/constraint/resource/API lifecycle test and browser transition sequence |

## Verification results

| Check | Result |
|---|---|
| Backend new acceptance + Risk lifecycle/freeze/manual-review/SAR + hold unit + event contracts | **61 PASS, 228 assertions, exit 0** |
| Controller architecture gate | **4 PASS, 1 third-party deprecation, 18 assertions, exit 0** |
| Frontend security/auth/MFA/middleware/navigation/roles | **154 PASS, 13 suites** |
| Playwright Chromium | **3 PASS**: investigation, separate-checker freeze, findings/denied route |
| TypeScript | **PASS**, tsc --noEmit --pretty false, exit 0 |
| Scoped frontend ESLint | **PASS**, zero warnings/errors for security module and security routes |
| PHP formatting | **PASS**, affected files formatted with installed Pint |
| Local additive migrations | **8 applied successfully**, existing E17/E26 table prerequisites and two ADM-P1-018 migrations |
| Story convergence | **PASS**, three discovered contract/history issues remediated; no remaining in-scope implementation gaps |

Evidence: backend-functional.log, architecture-final.log, frontend-regression.log, browser-results.json, local-migrations.log.
Backend uses PHP 8.4.26 in wallet-ecosystem-api-1. Predis selected for test processes because native phpredis is absent.
Tests use transaction rollback; missing dependency schemas were initially provisioned only inside rollback fixtures. Afterwards eight additive migrations were applied to the local development container to make the running API reviewable. No database reset or production migration was run.

Backend command:
docker exec -e REDIS_CLIENT=predis wallet-ecosystem-api-1 php artisan test tests/Feature/Modules/Risk/AdminSecurityTest.php tests/Feature/Modules/Risk/AlertLifecycleTest.php tests/Feature/Modules/Risk/AccountFreezeTest.php tests/Feature/Modules/Risk/ManualReviewTest.php tests/Feature/Modules/Risk/SarWorkflowTest.php tests/Unit/Risk/Services/AccountHoldServiceTest.php tests/Unit/Risk/Services/AmlRestrictionEventContractTest.php tests/Unit/Platform/TransactionReversedEventContractTest.php --no-coverage --compact

Architecture command:
docker exec -e REDIS_CLIENT=predis wallet-ecosystem-api-1 php -d memory_limit=1024M -d display_errors=stderr vendor/bin/pest tests/Architecture/ControllerThinTest.php --no-coverage --compact

Frontend from wallet-ecosystem/apps/admin:
node node_modules/jest/bin/jest.js --runInBand --testPathPattern 'p1018|middleware|auth|navigation|p1012' --watch=false
node node_modules/eslint/bin/eslint.js src/modules/security 'app/(admin)/security' --max-warnings=0
npx.cmd tsc --noEmit --pretty false
node node_modules/@playwright/test/cli.js test -c playwright.p1018.config.ts

## Browser visual verification

**PASS**: Chromium at desktop 1440x1000 and narrow 390x844. Screenshots were opened and visually inspected:
- screenshots/queue-desktop.png
- screenshots/investigation-desktop.png
- screenshots/freeze-confirmation.png
- screenshots/findings-desktop.png
- screenshots/investigation-mobile.png

Readable severity/status labels, scrollable tables, confirmation dialog, keyboard focus retention/tabbing, responsive account identifiers and explicit frozen state were verified. Browser scenarios use isolated test sessions and mocked API responses; real SQL persistence, authorization, audit, notification creation and conflict handling are verified by backend feature tests. No real customer account was frozen by browser testing and no external email/SMS provider delivery is claimed.

## Security review

Verified in the new Admin workflow:
- Server-side read/write/freeze permissions; UI and middleware gates are supplementary.
- Authenticated actor/checker attribution, no trust in body checker_id or subject_id, and maker cannot self-approve.
- Stale/closed mutation rejection, one pending request per alert, replay-safe approval and serialized account holds.
- Failed freeze rolls back hold/event/request state and leaves no cached hold.
- Atomic domain audit/outbox, receiver retry, notification deduplication, and React text escaping of notes.
- No balance, ledger, token, MFA, authentication or secret-management changes. Only stale MFA test-label selectors were updated.

Existing limitations retained and reported:
- The legacy direct /v1/risk/freeze-account-due-to-risk contract accepts a caller-supplied checker_id without independently authenticating that supplied identity. It remains the existing trusted Risk integration API; the new Admin workflow does not use that endpoint or trust that field. Review its broader approval policy before expanding legacy access. Existing role assignments were not changed.
- Existing Platform AuditService logs rather than propagates central audit write failures. New investigation actions have an additional durable domain audit trail in the same Risk transaction; actual central audit persistence is also tested.
- Production notification delivery depends on configured channels, an active template, scheduler and workers. These are operational prerequisites, not an automatic opt-in.
- Existing PHPUnit XML coverage configuration warning, third-party PHP 8.4 deprecation and some React act warnings remain. Numeric coverage was not claimed; no coverage driver is installed. Scoped security lint passes; pre-existing middleware warnings are outside this change.
- This is a scoped source/security review and regression result, not a penetration-test certification.

No newly introduced unresolved critical/high finding was identified in the selected Admin stories.

## Spec Kit and governance

Codex continuation was explicitly authorized after Claude Code quota exhaustion. Specify corrected the draft against approved intent; clarify found no new critical business ambiguities. Setup-plan/setup-tasks/check-prerequisites scripts were run. The quality checklist was re-evaluated: 16/16 requirements-quality items pass; these markers do not signify implementation completion.

Plan, research, data model, contracts, task list and constitution review were completed. Analysis mapped all four stories and six FRs to tasks. Planning research delegation was used because the installed planning skill explicitly requires it. No extension hook configuration exists.

Implementation completed all 21 tasks, including appended convergence T019-T021. Final convergence checks four stories, nine acceptance groups, six FRs, plan decisions and applicable constitution principles; no further task append is required.

Constitution preserved: approved intent/configuration; immutable financial truth; centralized auth/RBAC/MFA; module contracts/thin controllers; versioned API/OpenAPI/correlation; reliable domain event recording; shared UX/accessibility; evidence/audit/release review. No stack or service-boundary change.

## Definition of Done

**PASS for the four requested stories**: implementation, approved acceptance evidence, related unit/integration/browser/regression, local additive schema validation, visual review, security review, OpenAPI and traceability completed. Production deployment/UAT, external provider certification and changing the legacy Risk integration approval contract are not claimed.

Changed files are enumerated in files-changed.md. Existing unrelated workspace changes were preserved.

## Recommended next action

Review the changes and the documented legacy integration limitations. For controlled release, apply the additive migrations in the target environment, assign the new read permission through existing governance, configure the optional notification template/channels if desired, and verify scheduler/workers. Do not start the next epic automatically.

## Final route and artifact verification

All six Admin security route patterns were verified in the running application's route collection with their identity.auth and exact read/write/freeze middleware. The global artisan route:list command is separately blocked by a pre-existing missing App\Modules\Withdrawal\Infrastructure\Http\Controllers\WithdrawalCallbackController; scoped route inspection and backend request tests succeed. That unrelated Withdrawal route was not modified.

Final task count: 21 checked, zero unchecked. All changed-file inventory entries exist. Final convergence left tasks.md byte-for-byte unchanged; no new actionable gap in the selected stories was identified. The catalogue records completion specifically for US-P1-018-001 through 004.
