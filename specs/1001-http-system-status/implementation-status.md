# ADM-P2-001 — current implementation status
Date: 2026-10-16. Codex + installed Spec Kit.
**Epic: PARTIAL. Requested session/FX repairs: COMPLETE. Shared contract/dashboard follow-up: PARTIAL. Overall release DoD: FAIL.**

Latest reassessment (2026-10-16): T019c user and agent browser cycles PASS; T020 user wallet/transaction read models and browser responses PASS. Normal production build PASS after stopping test-owned Next dev servers left by crashed Playwright retries; 79 pages generated. Broad admin route/role/detail gaps remain, so the DoD stays FAIL. The detailed evidence below supersedes earlier “reactivation pending” and “agent cycle unverified” notes.


BQA-001 (full-admin token cookie persistence) and BQA-002 (FX provider-error presentation) are fixed. The normal build passes with lint/types enabled. All772 frontend tests,51 suites and21 snapshots pass. All19 fixture workflows and12 focused production journeys pass (11 normal +1 maintenance).

The previous live sweep passed36/75 static checks and failed39. This follow-up aligned role, audit, wallet, and transaction list consumers with the observed root `data` + `meta` API contract, matched audit filters/pagination to the backend, and moved dashboard KPIs to registered dashboard endpoints with correct field mapping. T018 repaired the user/agent read models and confirmed authenticated list and detail requests. In the latest browser rerun, user suspension completed but reactivation remained pending. Agent status testing exposed a fixed idempotency-key replay defect; it is fixed and API cycle tests pass, but a clean agent browser action result remains outstanding. The role-create workflow and unrelated legacy routes remain release blockers; other dynamic patterns remain unverified.

## Stories and acceptance
- US-P2-001-001: status routes, generic proxy failures and domain-specific FX handling implemented; targeted production checks PASS.
- US-P2-001-002: maintenance interception/bypass/MFA/CSRF/Secure-cookie production regression PASS.
- US-P2-001-003: script-free legacy/no-JS browser regression PASS.
- AC01–AC08: PASS using current targeted evidence and unchanged earlier criterion-specific evidence.
- AC09 / broad business-regression release gate: FAIL; full live admin functionality cannot be certified while legacy API failures, role workflow failure, and dynamic-detail coverage remain.
- T001–T015: implementation/verification tasks completed. Remaining release work is the broader live API/response-contract remediation and missing real-record coverage; it was not silently implemented as another epic.

## Changes
Shared bounded JWT cookie storage supports the full permission set across middleware, root layout/routing, login/MFA and logout, retaining legacy sessions. The unused duplicated permission cookie is removed. Only the FX submission's existing FX_PROVIDER_UNAVAILABLE JSON503 envelope is handled locally; generic failures retain safe global UI. This follow-up changed frontend response typing/consumption and dashboard URLs/field mapping only; it did not alter backend APIs, RBAC/MFA policy, provider selection or financial ledger behavior.

## Verification and evidence
- Normal npm run build: PASS, exit0,79 pages generated.
- Full Jest:772 tests/51 suites/21 snapshots PASS; final targeted30 tests PASS.
- Final TypeScript: PASS, exit0. Scoped lint: zero errors, warnings documented.
- Follow-up targeted Jest: PASS,189 tests across five role/audit/wallet/transaction/user suites. Production build rerun after source changes: PASS, exit0,79 pages generated; existing strict-boolean lint warnings remain.
- Follow-up production Chrome checks: dashboard, audit, role, wallet and transaction routes loaded without page errors or auth redirects. The route harness observed no list API requests, and the role-create dialog did not appear; these are not accepted as functional passes.
- Fixture interactions:19/19 PASS.
- Production:11 focused checks PASS;19 detail checks skipped (not passes).
- Production maintenance:1/1 PASS, including signed bypass, identity/MFA, CSRF and secure cookie.
- Actual FX503: correct domain code, visible actionable message, exactly one request.
- Desktop/mobile actual FX error screenshots opened and inspected.

Current logs, detailed failures, changed files, security limits and next action: [repair report](../admin-browser-validation/session-fx-repair-report.md).
Earlier implementation/build evidence is retained in implementation-status-before-session-fx-repair.md and the existing logs. Its historical COMPLETE claim is superseded by this live convergence result.

Sanitized QA API logs previously confirmed legacy user/agent schema defects. T018 now reads profile, KYC, wallet, application, float wallet, float limit, and ledger data through owning module connections; agent mutations use the authenticated identity claim as actor ID. Agent lifecycle actions honor the request's unique idempotency key and return the committed status. The dashboard summary URL collision remains fixed. User browser reactivation, clean agent browser action convergence, other route errors, and the role workflow remain in the release assessment.

Recommended next action: resolve the browser-only user reactivation request, complete live agent suspend/reactivate verification, then rerun the broader admin route sweep. No next epic, deployment or commit was performed.

## Latest convergence reassessment — 2026-10-16

**ADM-P2-001 remains PARTIAL; DoD remains FAIL.** T019c and T020 are complete for the requested workflows. The old “pending user reactivation” observation was reproduced only under the 45-second browser-run cap; a focused authenticated browser run completed the full user list/detail/wallet/transaction/suspend/reactivate journey in 57.5 seconds. A clean authenticated agent list/detail/suspend/reactivate browser run passed in 35.6 seconds. Both status responses and rendered state transitions passed. User wallet and transaction reads returned HTTP 200 in that browser run.

AdminApp wallet reads now use `wallet.wallets` and active `wallet.currency_pockets`, filter on `owner_id`, and resolve displayed balances through `WalletBalanceService`. The response resource exposes the frontend's expected id/user/currency aliases. Transaction reads now use `transaction.transaction_projections`, filter on `customer_id`, map projection fields to the existing AdminApp response contract, and use the existing configured reversal types/status for the eligibility flag. Owner/initiator display names are composed from profile names; identity emails remain searchable but are not returned as display names. Frontend user/agent tests pass (80/80) and TypeScript passes.

Focused API validation passed wallet search/user scope (2 tests, 16 assertions, including a seeded wallet/pocket balance response) and transaction search/user scope (2 tests, 9 assertions). A broader WalletTest run still exposes its separate legacy freeze handler querying an unqualified `wallets` table; that mutation was not changed by T020. The first build retry exited during static page generation with `FATAL ERROR: Zone Allocation failed - process out of memory` while two test-owned Next dev servers were still alive. After stopping those orphaned servers, a clean normal build passed with lint/types enabled and all79 pages generated. A later fresh-server paired browser rerun exposed a null-email selector bug in the user test (now changed to a stable URL selector), followed by a Next dev-server allocation failure; earlier isolated user and agent workflows both passed, but the updated selector did not get a new pass in that rerun. No new screenshot was manually inspected, and the broad 39/75 route sweep, role-create workflow and 19 unverified dynamic details remain open.

Security: wallet/transaction route permission middleware and JWT/actor validation are unchanged. User filters constrain wallet owner/customer ID. No balance, transaction, refund, provider or status mutation was performed outside the reversible QA suspend/reactivate cycles. No new authorization weakness was observed.

Recommended next action: continue the outstanding broad live route/role/detail convergence. Do not start another epic until those gates are resolved.

Build status correction (2026-10-16): the first OOM happened while two orphaned Next dev servers left by crashed Playwright retries were still running. After stopping those test-owned processes, normal `npm run build` passed (lint/types enabled;79 pages generated).

## Live route/detail follow-up — 2026-10-16

ADM-P2-001 remains **PARTIAL; overall Definition of Done FAIL**. The refreshed live authenticated route inventory completed 74 checks: 62 PASS, 12 FAIL. The prior recorded 39/75 failures are historical and superseded by this run. The audit harness now refreshes expired auth state by using the established QA admin login before each route sweep.

Resolved in this follow-up: applied the already-authored Ops module migrations to the local QA API database, which fixed the missing Ops alert/rule/backup/metrics/provider tables; aligned fee, reconciliation, settlement and related report consumers to the live root `data` plus sibling `meta` contracts. Targeted live routes passed for those pages. Focused regression coverage passed 167 tests; TypeScript passed. Role creation/persistence/detail navigation passed. Six live dynamic detail workflows passed. Thirteen dynamic patterns remain skipped for absent QA rows or links, including KYC details because the live KYC queue is empty.

Current route failures: financial dashboard cold-cache 503; compliance and compliance-retention; devices; dead-letter; Ops logs, metrics and queue-monitoring; profile and profile sessions; QA; risk dashboard. Profile notifications passed on the final run. Exact route evidence is in `specs/admin-browser-validation/routes/` and dynamic discovery is in `specs/admin-browser-validation/live-detail-discovery-current.json`.

Normal production build remains **not reverified after these last source edits**: the command runner began failing process creation with Windows logon error 1909 after the long Playwright sweeps. The prior build pass predates the fee/reconciliation contract changes. Browser visual review of the new failed-route screenshots was not performed; automated artifacts were saved. Therefore AC09 and DoD remain FAIL, and no overall completion claim is made.

## Final live route and DoD reassessment — 2026-10-17

**ADM-P2-001: PARTIAL. Overall DoD: FAIL.** The final full static route inventory passed 73/74; the one remaining route, `/sessions`, was repaired by adding a permission-gated `GET /v1/admin/sessions` and then passed in a targeted authenticated browser run. The 12 other failures from the preceding inventory were each rerun successfully after correcting frontend list response shapes, provisioning existing Ops migrations, and warming the existing dashboard caches. Thus all 74 routes have passing evidence across a full inventory and targeted retests; a single clean final 74/74 run was not completed.

The session endpoint uses the existing `session.admin.revoke` gate and returns a paginated search result with only the fields needed by the session page. It does not return IP hashes, refresh token hashes, or raw user agents. Its API feature suite passed 9 tests / 41 assertions. Device, own-session, Ops log/metrics/queue, finance/risk dashboard and all targeted prior failure routes now pass browser route checks.

Post-fix frontend evidence: normal `npm run build` PASS (79 pages; pre-existing strict-boolean lint warnings), TypeScript PASS, focused Jest PASS 167/167 across roles, fees, KYC, reports, and reconciliation, plus device/own-session PASS 27/27. Current identity session API suite PASS (9/9, 41 assertions; PHPUnit XML/coverage-driver warnings remain). Role create, persistence after reload, and detail navigation PASS. Cumulative live dynamic detail evidence covers agents, roles, transactions, users and wallets (six patterns including the agent alias); 13/19 dynamic patterns remain skipped because safe QA records do not exist. The latest dynamic retry did not produce a clean whole-suite result due screenshot harness path and test-runner allocation/auth-expiry faults; the screenshot path and JWT-expiry checks have since been fixed, but the agent rerun was blocked by the same Next allocation issue.

Browser visual review: the captured financial-dashboard 503 screen was inspected and displays a safe branded unavailable state; the cache refresh then restored the live route. The earlier wallet detail screenshot was also inspected. Other latest pages received DOM/API checks rather than manual visual review.

Security: existing JWT, MFA, permission, and actor checks remain intact. The session list uses the existing gate and omits secret fields. QA-only Ops migrations and dashboard cache refresh used existing project mechanisms; no financial business transaction was performed. AC09 / Definition of Done remain FAIL because 13 dynamic detail paths lack live records and the latest expanded detail suite did not complete cleanly.

Next action: provision isolated non-production records for the 13 missing detail paths, run the dynamic suite with a fresh valid admin session after reviewing/clearing only test-owned Next processes/cache, and run one final full static inventory. No next epic was started.

## QA fixtures and final browser sweep — 2026-10-17

**Status remains PARTIAL; overall Definition of Done FAIL.** The isolated QA database `olekard_browser_qa_20261016` now has marker-scoped fixtures for all 13 missing detail path patterns (14 rows including the report definition). The QA-only schema migrations were applied after the live `/qa` dashboard failed on the absent `qa.test_runs` table. Seed definitions and record IDs are captured in `specs/admin-browser-validation/session-fx-repair-report.md` and `wallet-ecosystem/services/api/scripts/qa/seed-admin-detail-browser-fixtures.php`.

Fresh-session dynamic suite with direct-ID fallback and a required visible H1: **9 pass, 10 fail, 0 skip** across 19 patterns. Of the 13 targeted missing-detail paths, KYC, reconciliation batch, settlement, vulnerability finding, and support case pass; configuration approval, configuration key, fee rule, integration provider health/detail, QA run, report detail, and security alert detail fail. Parent APIs fail for five; configuration, fee, and QA routes render only the admin shell. The agent detail alias also fails the heading check. This stricter assertion exposed blank detail pages that the earlier URL/API-only harness had counted as passing.

Fresh-session static browser sweep: **74 routes executed; 64 pass, 10 fail** (plus the passing login test). Failed routes: compliance, compliance retention, configuration approvals, financial dashboard, integration clients, integrations, integration providers, operations dead-letter, profile, and reports. Every route case reported before the runner teardown hung; the run was interrupted after all route results printed. The normal build and regressions were not rerun because subsequent Windows process creation failed with error 1909.

Security review: fixtures are confined to the explicitly named browser QA database. Financial fixtures are zero-value or deactivated; the provider is inactive with a `qa://` credential reference; no external calls, refunds, freezes, or real settlements were made. JWT/MFA/RBAC and actor checks were unchanged. Browser visual status is partial: automated DOM/API capture ran, but the new failure screenshots were not manually reviewed. AC09 and DoD remain **FAIL**; do not start another epic.

Next: resolve the ten failing routes and eight failing targeted detail paths plus the agent alias, then complete a clean 74/74 sweep and normal build before reassessing DoD.
