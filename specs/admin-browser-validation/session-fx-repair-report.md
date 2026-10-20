# Session persistence and FX error repair — 2026-10-16

**Requested repairs: COMPLETE. Overall admin validation: PARTIAL; all-functionality acceptance and release DoD: FAIL.**

## Follow-up contract repair — 2026-10-16

Aligned list-page consumers with the live API's root `{data, meta}` shape for roles, audit events, wallets, and transactions; corrected audit field names, request filters, and page-based pagination; and rewired dashboard KPI calls from unsupported `/summary` URLs to the registered `/v1/admin/dashboard/*` routes with their actual metric names. The audit view now shows only fields returned by the list contract and opens its detail panel from the selected event data instead of calling an unregistered AdminApp detail route.

Validation after these edits: production build PASS (79 pages); TypeScript PASS; 189 tests across five relevant Jest suites PASS. Five production Chrome route checks rendered the dashboard, audit, role, wallet, and transaction routes with no uncaught page error or authentication redirect. They are not counted as functionality passes because the harness observed no list API requests, and the role-create dialog did not open. The role-create production workflow therefore remains FAIL. The isolated API still reports schema mismatches in AdminApp users (`identity.users.full_name` absent) and agents (`agent.agent_float_wallets` absent). Those backend read-model failures, the broader previous 39/75 sweep failures, and 19 unverified dynamic details still block full Admin DoD.

The refreshed browser-auth fixture initially included a UTF-8 BOM and could not be parsed; it was rewritten without the BOM. Direct API login and an authenticated read request verified the refreshed token, but the browser login form workflow itself was not reconfirmed in this follow-up. The in-app browser surface was unavailable, so no new screenshot-based visual claim is made beyond the existing inspected FX screenshots.

BQA-001 and BQA-002 are fixed and verified against the isolated real API, including the normal production build. The broader audit exposed additional API, response-contract and rendering failures; the admin panel is not certified as fully functional.

## Implementation and acceptance

| Repair criterion | Result | Evidence |
|---|---|---|
| Full-permission login persists across reload and navigation | PASS | Real 195-permission account; production login/session journeys; `repair-cookie-evidence.json` |
| Logout clears token and every chunk; expired storage fails closed | PASS | Production logout and expiry journeys |
| Backend rejects a tampered chunked JWT and clears the browser session | PASS | Real security-events request returns 401; production negative journey |
| Existing MFA enrollment and permission gates remain enforced | PASS | Production enrollment gate, middleware/RBAC regressions, maintenance bypass journey |
| Known FX provider failure remains actionable on the form | PASS | Real JSON503 / FX_PROVIDER_UNAVAILABLE, desktop/mobile screenshots |
| Failed FX request is sent once, without automatic replay | PASS | `live-fx-error.json`: status503, expected code, count1 |
| Unknown service/proxy failures still receive safe status UI | PASS | Production HTML429/503/504, blob/unit regressions, shell preservation |
| Entire admin panel works against the real API | FAIL | 39 of75 static checks fail; role action workflow fails; dynamic record coverage remains incomplete |

The session writer now splits JWTs into at most four 3,000-character cookies and writes a bounded count marker last. Middleware, root routing and server layout use the same reconstruction helper. Missing/invalid chunks fail closed; old single-cookie tokens remain compatible. Login and MFA use the same writer, token expiry is retained, and replacement/logout clears stale chunks. The unused duplicate permission cookie is removed; UX permissions continue to come from the token, and the backend still validates signature, session and permissions. Tokens larger than12,000 characters receive an explicit storage error rather than a silent login loop.

FX submission opts into only the existing FX_PROVIDER_UNAVAILABLE JSON503 domain code. The authenticated client checks a cloned response for that explicitly requested code. Generic JSON/HTML503, 429, 504, blobs and the existing401 sign-out behavior remain unchanged. No request replay, API change, provider choice or financial-state change was introduced. The FX browser fixture now supplies the real API envelope's success flag and domain code.

## Verification

| Check | Result |
|---|---|
| Normal `npm run build`, lint/types enabled | PASS, exit0;79 pages generated; `repair-build.log` |
| Full frontend unit/integration/regression | PASS:772 tests,51 suites,21 snapshots; `repair-regression.log` |
| Final targeted security/status/session regression | PASS:30 tests; exit0; `repair-targeted-final.log` |
| Final TypeScript | PASS, exit0; `repair-types-final.log` |
| Scoped lint | PASS, zero errors; pre-existing warnings and two QA-test nullable-expression warnings remain in recorded logs |
| Existing interactive browser fixture workflows | PASS:19/19; risk, notifications, integrations, support, FX, security |
| Focused production browser | PASS:11/11 executed;19 detail checks skipped; exit0; `repair-production-results.json` |
| Production maintenance / signed bypass / MFA / CSRF / secure cookie regression | PASS:1/1; exit0; `repair-maintenance-results.json` |
| Real static-page sweep |36 PASS /39 FAIL; zero unexpected protected-page login redirects |
| Additional live action workflows |2 PASS (FX and session lifecycle),1 FAIL (role workflow) |

The broad development run contains116 tests:58 passed,40 failed,18 skipped (`repair-live-results.json`). Its one apparent detail-route pass matched the static `/integrations/providers` route. The selector was corrected to exclude known static routes; the production retest correctly skips all19 dynamic patterns. That apparent pass is not accepted as detail coverage. The corrected inventory records these separately in `repair-summary.json`.

Public-page smoke passes do not certify full password reset/email verification workflows. The MFA result proves the existing enrollment gate, not real OTP enrollment or successful OTP verification. Fixture tests intercept API traffic and do not prove financial or provider side effects.

## Remaining failures

`repair-summary.json` lists every failing route and its observed API status/browser error. Main groups:

- HTTP500: agent/float/wallet/transaction lists, configuration, dead-letter, operations monitoring, QA, report jobs/schedules.
- HTTP404: compliance endpoints, profile endpoint, sessions endpoint and KYC summary.
- Dashboard transaction query returns422; dashboard wallet/transaction summaries return500. Financial/risk dashboards return503.
- Response-shape rendering failures: roles, users, devices, audit, fees, KYC, sessions and settlement report pages (`length`/`map` on undefined).
- The real role POST returned201 and created the empty-permission QA role recorded in `created-role.json`; the UI workflow then failed to show success/persisted detail because rendering broke. It is not counted as a passing workflow.
- Dynamic details remain unverified without usable links from working list pages or appropriate QA records. Financial refunds, real provider execution and external message delivery remain unverified.

These are observations from the live API sweep. Shared root causes still need diagnosis; counts represent failing checks, not39 independent defects. Sanitized API logs traced the user/agent schema errors noted above. The `/v1/admin/{wallets,transactions}/summary` calls were invalid and collided with UUID detail routes; the frontend now calls registered dashboard routes. The remaining unrelated operations/configuration/QA/report failures have not been repaired.

## Browser visuals and security

Opened and inspected `repair-screenshots/live-fx-error-desktop.png` and `live-fx-error-mobile.png`: the specific provider error, populated form, disabled provider-mode rate field and shell remain usable. The provider remains intentionally unconfigured; no rate is invented. Production status and maintenance screenshots are retained as additional automated evidence.

The QA database/Redis/API from the original validation were reused; actual code mounts were verified. API port8001 and frontend3030 are local-only test endpoints. The production frontend was compiled with the isolated API URL; deployers must use their normal deployment environment. Mail stays in-memory and no queue worker was started. The only successful mutation was the empty-permission QA role; FX returned503 before configuration changed. No financial/provider mutation succeeded. Private auth/environment artifacts remain ignored and are not reproduced in this report. Temporary provisioning helpers were removed in the earlier validation.

No JWT verification, API authorization, MFA policy, maintenance capability or financial idempotency rule was weakened. This is not a penetration test or a full dependency audit. Session storage remains within the application's existing cookie-based design.

## Files changed

Product code: `lib/auth/token-cookies.ts` (new), `lib/auth/context.tsx`, `middleware.ts`, `app/layout.tsx`, `app/page.tsx`, `lib/api/authenticated-client.ts`, `lib/api/admin-app-client.ts`, `src/modules/multi-currency/pages/fx-rates.tsx`, role/audit/user/wallet/transaction pages, and dashboard KPI API under `wallet-ecosystem/apps/admin/`.

Tests/harness: `__tests__/session-fx-regression.test.ts`, relevant AdminApp role/audit/user/wallet/transaction tests and mocks, `e2e/p1022-fx.spec.ts`, QA copies/fixtures and `e2e/admin-live-{audit,workflows}.spec.ts`, `e2e/admin-status-regression.spec.ts`, and `playwright.audit*.config.ts`. Evidence scripts/reports remain under this directory. Spec Kit specification clarification, plan, tasks, traceability, security review and implementation status were updated in `specs/1001-http-system-status/`.

## Recommended next action

Repair the remaining AdminApp user/agent schema-bound reads using the module-owned profile and wallet/agent data sources, then diagnose operations/configuration/QA/report API failures. Rerun browser validation with a fresh authenticated UI session and provision suitable isolated records for dynamic detail flows. Keep the overall release gate failed until full live evidence passes. No next epic was started and no deployment or commit was performed.

## User/agent read-model and live-action follow-up — 2026-10-16

**Result: PARTIAL.** The read-model fixes are in place, but live status-action convergence remains incomplete. This is not a pass for the broad Admin Definition of Done.

- Legacy user list/detail queries now compose identity, profile, KYC, and wallet-owner data through their owning schemas. The UI normalizes the root `{data, meta}` response and user detail navigation uses a real link. AdminApp user status actions use the customer lifecycle service and return success without duplicating audit/action writes when the requested state is already set.
- Agent list/detail/float reads now use `agent.agents`, application/profile/identity data, `float_wallets`, `float_limits`, and the ledger contract. Authenticated actor IDs come from the identity middleware claim. Agent suspend/reactivate now use the request's unique `Idempotency-Key` and return the lifecycle service's committed status; the detail screen updates its status from that confirmed response. This fixed a live defect where a hard-coded key replayed an old result and left the agent ACTIVE.
- Authenticated Chrome run: user `GET /v1/admin/users` returned 200 with a bearer header; legacy and AdminApp user detail reads returned 200; suspend completed. Reactivate stayed in its loading state and the 45-second browser test timed out. An API feature test for suspend/reactivate plus persisted status passed (6 assertions), so the remaining failure is browser-path-specific and unresolved.
- Authenticated Chrome run: agent list and detail loaded. Before the idempotency fix, suspend returned 200 but detail stayed ACTIVE due to cached replay. The final browser rerun did not complete before Playwright's worker/server teardown stalled, so live agent suspend/reactivate is not counted as passed. API feature tests now pass for suspend/reactivate/suspend using three unique keys and assert persisted statuses (9 assertions); suspend audit regression also passes.
- The user detail screen's auxiliary wallet/transaction reads returned HTTP 500 in one live capture; another snapshot rendered their empty states. Those endpoints are still a separate known issue and are not counted as user detail read-model passes. Existing full-admin role and route failures remain.
- Frontend verification after the final code edits: TypeScript PASS; user+agent Jest suites PASS (80 tests). API checks: user feature suite 6/6 PASS, customer lifecycle cycle 1/1 PASS, agent lifecycle cycle 1/1 PASS, agent suspend audit 1/1 PASS. Playwright did not produce a clean full result; no new manually inspected screenshot is claimed.

**Acceptance convergence:** user/agent authenticated lists PASS; primary user/agent detail reads PASS; persisted API lifecycle cycles PASS; user browser reactivation FAIL; clean live agent lifecycle cycle NOT VERIFIED; incidental wallet/transaction panels FAIL in the captured run. Target overall acceptance and Definition of Done remain FAIL.

**Security notes:** RBAC and JWT validation remain unchanged; controller actor attribution uses the verified identity claim. User list contact values remain masked by the AdminApp resource, while the authorized portal resource returns the existing fields. No test reported a new authorization bypass. The repeated-status shortcut avoids duplicate action/audit records. No financial/provider mutation was performed.

**Next action:** diagnose why the browser's user reactivation PATCH remains pending, capture a clean live agent status cycle after the idempotency-key correction, and then repair the remaining user-detail wallet/transaction failures before reassessing the broad Admin DoD.

Final verification after this follow-up's source edits: normal `npm run build` PASS (exit0;79 pages); `npx tsc --noEmit` PASS; the targeted user+agent Jest suites PASS (80 tests); API lifecycle regression tests PASS as listed above. Existing strict-boolean lint warnings remain. The browser status limitations above are unchanged by the build and keep T019c open.

## User/agent browser reactivation and user-detail wallet/transaction repair — 2026-10-16

**Requested workflows: PASS. Epic/release DoD: PARTIAL / FAIL.** This section supersedes the preceding T019c “open” status for the requested live workflows, while leaving the broad Admin DoD unchanged.

The pending reactivation observation was investigated with request and runner evidence. The original `playwright.audit.config.ts` imposed a 45-second whole-test timeout. The QA API health probe took 6.4 seconds after restart and had previously taken up to 28 seconds; the first browser rerun timed out in `beforeEach` while waiting for login, before reaching the detail workflow. With a 150-second test cap and the already running local Next dev server, the complete authenticated user browser workflow passed in 57.5 seconds. It verified the authenticated `/v1/admin/users` list, user detail, wallet and transaction list responses (both HTTP 200), and suspend/reactivate PATCH responses plus returned active UI state. This shows the original “pending” state was a test timeout under slow local API setup/reads; the user reactivation operation itself completed in the successful run.

A clean authenticated agent list/detail/suspend/reactivate browser workflow also passed in 35.6 seconds after the unique idempotency-key fix. Both status PATCH responses were 200, the committed status fields were checked, the detail action buttons followed each state, and no API errors were captured. A later retry after adding extra request instrumentation produced no output and was interrupted; it does not replace the clean successful cycle. The tested workflow implementation was unchanged by that instrumentation experiment.

A subsequent fresh-server paired rerun revealed a second test-harness issue: an active QA user had a null email, and the user detail locator filtered table rows by email, matching multiple rows. The locator is now keyed by the user's stable detail URL. The paired rerun then encountered a Next dev-server `Array buffer allocation failed` during the agent test and was stopped; the updated selector did not receive a new pass in that final rerun. The earlier independent user and agent workflow runs remain clean passes against the same product code and data contracts, but the latest test-harness adjustment is not independently reconfirmed.

The detail panel's two failing reads were caused by obsolete AdminApp queries. Wallet lists queried retired unqualified `wallets` columns; transaction lists queried an absent `transactions` table and legacy columns. The wallet list now reads `wallet.wallets` and active `wallet.currency_pockets`, scopes by `owner_id`, and resolves balances through the Wallet module's `WalletBalanceService`. The transaction list reads `transaction.transaction_projections`, scopes by `customer_id`, and maps its current projection fields to the established AdminApp response. Resources now provide the `id`, `user_id`/`currency` aliases expected by the detail components. Reversal eligibility follows the existing transaction reversal configuration and completed status. Owner/initiator display names come from profile names; identity email stays available for search but is not returned as a display name. Route permission middleware, authentication and financial services were not weakened or replaced.

### Verification and reassessment

| Check | Result |
|---|---|
| Authenticated live user list/detail/wallet/transaction/suspend/reactivate | PASS; one complete browser run, 57.5 seconds |
| Authenticated live agent list/detail/suspend/reactivate | PASS; one complete browser run, 35.6 seconds |
| Wallet API list and user filter | PASS; 2 tests, 16 assertions, including a seeded wallet/pocket and balance response |
| Transaction API list and customer filter | PASS; 2 tests, 9 assertions |
| User/agent frontend Jest suites | PASS; 80 tests |
| TypeScript | PASS; `npx tsc --noEmit` |
| Latest normal Next build | PASS; after stopping orphaned Playwright-owned Next dev servers, `npm run build` completed with lint/types enabled and all79 pages generated. The first retry had hit a transient Node out-of-memory failure. |
| Browser visual screenshot review | NOT PERFORMED; no new visual claim |
| Overall admin DoD | FAIL; the previously recorded 39/75 static route checks, role-create workflow, and 19 unverified dynamic detail routes remain open |

The focused API test runs emitted existing PHPUnit XML configuration and missing coverage-driver warnings. A broader `WalletTest` run still fails its independent freeze mutation: that handler queries the retired unqualified `wallets` table. The user-detail wallet list and filter tests both pass; the unrelated freeze endpoint was not modified. Existing user/agent lifecycle API-cycle feature regressions remain passing as recorded above. The additional Playwright retry did not complete, but each requested end-to-end status cycle has a separate clean passing run. The first build retry also failed transiently while orphaned test servers remained; the clean retry passed after they were stopped.

**Acceptance convergence:** T019c PASS for the requested browser flows; T020 wallet/transaction read-model and user-detail browser checks PASS. Broad AC09 / Definition of Done remains FAIL because of unrelated live routes, role workflow, and dynamic-detail coverage. The production build now passes. No screenshot-based visual pass is claimed.

**Security findings:** AdminApp permission checks and bearer authentication remain enforced. User query filters are applied to `wallet.owner_id` and `transaction.customer_id`. No new security finding was identified. Wallet balance reads use the owning balance service; no financial records or balances were mutated. Only the QA user/agent reversible status cycles changed status, and each was restored to active.

**Recommended next action:** close the outstanding broad live route/role/detail gaps before reassessing the release DoD. No next epic was started; no commit or deployment was made.

## Live route, role, and dynamic-detail convergence — 2026-10-16

**Result: PARTIAL; overall Admin DoD remains FAIL.** The broad route rerun supersedes the historical 39/75 static failures: the refreshed authenticated run completed all 74 static-route checks with 62 passing and 12 failing. The route harness now checks stored-token expiry and reauthenticates before a route sweep, preventing stale QA cookies from being counted as application route failures.

The live failures were concentrated in `/financial-dashboard` (503 `ADMINAPP_DASH_001`, cache not warmed), `/compliance`, `/compliance/retention`, `/devices`, `/operations/dead-letter`, `/ops/logs`, `/ops/metrics`, `/ops/queue-monitoring`, `/profile`, `/profile/sessions`, `/qa`, and `/risk-dashboard`. The `/profile/notifications` route passed in the final authenticated run. The financial dashboard's cold-cache condition remains an operational dependency; no fabricated financial metric or cache value was written.

Diagnosis of the earlier Ops live failures found missing QA database schema, not missing route implementations: `ops.alert_rules`, `ops.alerts`, `ops.backup_jobs`, `ops.service_metrics`, and `ops.provider_statuses` were absent. The existing Ops module migrations were applied to the local QA API database. A subsequent authenticated rerun passed `/ops/alert-rules`, `/ops/alerts`, `/ops/backup-jobs`, and `/ops/db-monitoring`. This is QA environment provisioning only; the migration files were pre-existing. The failure detail for logs, queue monitoring, dead-letter, profile, compliance, devices, QA and risk remains open.

The route payload checks also uncovered client contract regressions. Fee-rule and reconciliation/settlement collection consumers expected an extra nested `data` object despite the API returning root `data` rows and sibling `meta`. The consumers and shared types were aligned, including the report settlement and reconciliation views. Focused authenticated browser checks then passed `/fees/rules`, `/fees/rules/create`, `/reconciliation/batches`, and `/reports/settlement` (4/4).

The live role-create workflow passed create, reload/persistence, and detail navigation. The dynamic-detail sweep passed 6 live detail workflows: agents in both registered aliases, roles, transactions, users, and wallets. Thirteen of the 19 dynamic patterns were skipped because the QA database exposed no corresponding record/link; KYC in particular returned an empty queue. Those are unverified acceptance paths and are not counted as passes. The final full route sweep recorded no role route or role API failure.

Frontend regression: five focused Jest suites passed (167 tests across roles, fees, KYC, reports and reconciliation). TypeScript passed after response-type fixes. A normal production build was not rerun after this follow-up's last edits because the local command runner stopped launching processes with Windows logon error 1909; the prior session's normal build pass predates these edits. The full browser sweep completed, but teardown stalled after all route results had printed, consistent with prior Playwright/Next dev-server teardown behavior.

Browser visual verification: automated route artifacts include screenshots for failed routes. No new failure screenshot was manually inspected during this pass. The earlier wallet-detail screenshot remains the latest manual visual inspection; this does not constitute a full-admin visual pass.

Security: no authentication, MFA, RBAC, actor attribution, financial workflow, or API authorization change was made. Ops schema provisioning used existing migrations in the isolated local QA database. No financial mutation was performed. The remaining 12 route failures and 13 skipped dynamic-detail paths keep overall acceptance convergence and DoD failed.

**Recommended next action:** investigate the 12 current route failures and provision safe isolated records for the skipped detail routes, then rerun the full browser route/detail sweep and normal production build before changing DoD status. No next epic was started.

## Final reassessment — 2026-10-17

**Overall status: PARTIAL; Definition of Done: FAIL.** The 74 static routes now have passing live evidence cumulatively: the latest full inventory run passed 73/74, with `/sessions` the sole failure; after adding the missing read endpoint, its isolated authenticated browser check passed. The 12 failures from the preceding run were rechecked after their fixes and passed in targeted route tests. This is a full route inventory plus targeted retests, not a single clean 74/74 rerun.

Follow-up fixes after the previous section:

- Device and own-session consumers now use the root-array response emitted by their API; Ops logs, metrics, and queue-monitoring normalize their root-array responses. Live checks passed `/devices`, `/profile/sessions`, `/ops/logs`, `/ops/metrics`, and `/ops/queue-monitoring`.
- The local QA database's existing AdminApp dashboard cache refresh command was run, warming the operational/financial/risk caches using its current safe fallback behavior. `/financial-dashboard` and `/risk-dashboard` then passed live route checks.
- A paginated, searchable `GET /v1/admin/sessions` endpoint now backs the existing admin session-management screen. It uses the existing `session.admin.revoke` permission gate and selects only session, email, and device fields needed by that screen; it excludes IP/refresh-token hashes and raw user-agent values. The API feature suite passed (9 tests, 41 assertions) and `/sessions` passed in a live browser check.
- The route audit now renews expired QA credentials and records `/v1/` responses independent of host. The workflow harness checks JWT expiry and creates the screenshot directory before writing dynamic-detail captures.

Verification: latest normal `npm run build` passed with all 79 pages generated; existing strict-boolean lint warnings remain. TypeScript passed after the final harness edits. Five focused frontend suites passed 167/167 tests; device and own-session suites passed 27/27 in a separate run. The API session suite passed with existing PHPUnit XML configuration and missing coverage-driver warnings. A manually inspected browser screenshot of the financial dashboard shows the expected safe, branded 503 screen while its cache is cold; a later cache refresh made the live dashboard pass. No new financial transaction was performed.

Dynamic details remain the release gap. Cumulative successful live detail evidence covers agents, roles, transactions, users, and wallets (including the agent alias); role create/persist/reload/detail also passes. Thirteen of 19 patterns remain skipped because the isolated QA snapshot has no linked record. The latest expanded dynamic rerun encountered one agent screenshot-write harness error (fixed by creating the output directory first), then expired-auth/Next allocation failures; the refreshed agent alias rerun itself could not complete because the test server exhausted its allocation. Those later runner failures do not prove a product regression, but they prevent claiming a clean latest dynamic suite. KYC has no live submissions; reports, findings, support, configuration approvals, and integration details also need safe QA records.

Browser visual review: I inspected the captured financial dashboard 503 screen; it is legible and contained in the admin shell. The earlier wallet detail screenshot was also inspected. The rest of the updated routes were checked through DOM/API assertions, not manual screenshot review. This is not a complete visual sign-off.

Acceptance convergence: static route behavior PASS on cumulative evidence; role create/persist/detail PASS; six dynamic detail patterns have prior live passes; 13 dynamic detail patterns remain unverified; AC09 and overall DoD remain FAIL. Security review found no auth/MFA/RBAC weakening. The new session list retains the existing permission gate, avoids secret fields, and adds a constrained read-only endpoint. No deployment, commit, or next-epic work occurred.

**Recommended next action:** seed isolated non-production records for the 13 missing detail routes, clear only the test-owned Next development cache/processes after explicit review, refresh the authenticated browser session, and rerun dynamic details plus one complete 74-route sweep before reassessing DoD.

## QA fixture and final route-sweep reassessment — 2026-10-17

The prior recommendation above is superseded by this run. ADM-P2-001 remains **PARTIAL; overall Definition of Done: FAIL**.

An idempotent seeder at `wallet-ecosystem/services/api/scripts/qa/seed-admin-detail-browser-fixtures.php` added isolated records in the confirmed `olekard_browser_qa_20261016` database under marker `browser-qa-20261017`. It covers all 13 requested detail patterns (14 rows including a zero-row report definition): pending configuration approval and inert config value; deactivated expired fee rule; inactive provider plus unknown health sample; synthetic KYC case; completed QA run; zero-count reconciliation batch and zero-value draft settlement; completed zero-row non-PII report; low-severity synthetic risk alert; informational security finding; and open support ticket. No provider call, refund, account freeze, or real settlement was initiated. The QA module's existing migrations were applied because `qa.test_runs` was absent and the QA dashboard returned HTTP 500.

The fresh-admin dynamic run completed all 19 cases before the Next/Playwright teardown hung (Ctrl-C was needed after every case reported). The harness now visits the 13 seeded IDs directly when no list link exists and requires a visible detail-page H1. Latest result: **9 pass, 10 fail, 0 skip**. Five of the 13 requested details pass (KYC, reconciliation batch, settlement, vulnerability finding, support case); eight fail (configuration approval, configuration key, fee rule, integration provider health/detail, QA run, report, security alert). Parent API failures block five; configuration, fee, and QA pages render only the admin shell with no H1. The agent detail alias also fails the heading check. The stricter assertion exposed false positives: fee and QA were previously marked pass while their screenshots were blank.

One fresh-login browser sweep executed the login test plus all **74 static routes**. The login passed; the route result was **64 PASS / 10 FAIL**. Failures: `/compliance`, `/compliance/retention`, `/configuration/approvals`, `/financial-dashboard`, `/integrations/clients`, `/integrations`, `/integrations/providers`, `/operations/dead-letter`, `/profile`, and `/reports`. The other routes, including `/qa`, `/risk-dashboard`, `/security/dashboard`, `/security/findings`, `/sessions`, and `/support/cases`, passed. The browser runner again hung after reporting all cases; it was interrupted after all route results printed. This is one complete route sweep, but it is not an all-pass sweep.

The remaining failures include live API/read-model responses and blank details, and keep acceptance convergence and DoD **FAIL**. A later Windows process launch briefly failed with `CreateProcessWithLogonW 1909`; it recovered for the final dynamic run. Build/regression checks were not performed after the fixture and harness edits. Security boundaries were preserved. No next epic was started.

**Next action:** resolve the ten live-route failures and the eight failing requested detail paths plus the agent alias, then run a clean 74/74 sweep and normal build before reconsidering DoD.
