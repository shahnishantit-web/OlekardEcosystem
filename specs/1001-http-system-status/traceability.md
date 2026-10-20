# ADM-P2-001 acceptance traceability

> **Latest status (2026-10-16): T019c/T020 requested browser and read-model checks PASS; Epic PARTIAL; DoD FAIL.** See the latest convergence table at the end of this file and the current [repair evidence](../admin-browser-validation/session-fx-repair-report.md). Earlier entries describing the user action as pending are historical and superseded.

> **Latest follow-up convergence (2026-10-16): Epic PARTIAL; AC01â€“AC08 PASS; AC09/release DoD FAIL.** Role, audit, wallet, and transaction list consumers now follow the live root `{data, meta}` contract; audit pagination and dashboard KPI routes/field mappings were corrected. Latest normal build: PASS,79 pages. TypeScript: PASS. Relevant Jest suites:189 tests PASS. Five Chrome route renders did not surface page errors, but list API requests were not observed and the role-create dialog did not open, so these checks do not establish working functionality. Users/agents AdminApp schema failures and the previous39 static failures plus19 unverified detail routes remain. The report below records full limitations and is authoritative for current status.

> Current reassessment after authorized session/FX repair: **Epic PARTIAL, AC01–AC08 PASS, broad AC09/release DoD FAIL.** Both BQA-001/BQA-002 are resolved. Normal build exit0;772 tests/51 suites/21 snapshots;19 fixture workflows;11 focused production checks plus1 production maintenance journey pass. Live static sweep36 PASS/39 FAIL and role workflow FAIL; corrected19 detail patterns skipped. The historical table/results below are retained for history and are superseded for AC09/overall DoD. See [current repair report](../admin-browser-validation/session-fx-repair-report.md).

| Follow-up | Requirement | Current evidence | Result |
|---|---|---|---|
| T013 / BQA-001 | Persistent full-admin session, unchanged identity gates | Production login/reload/navigation/logout/expiry, real401 tampered token rejection; unit incomplete-chunk/MFA/RBAC | PASS |
| T014 / BQA-002 | Preserve FX business errors and generic service handling | Real FX_PROVIDER_UNAVAILABLE503, one POST, visible local message; production generic HTML429/503/504 | PASS |
| T015 | Repeat full validation and record limits | repair-live-results.json, repair-production-results.json, repair-maintenance-results.json, repair-summary.json | Validation completed; overall gate FAIL |
Date:2026-10-16. Epic status:COMPLETE. Acceptance:PASS9/9. DoD:PASS.
| AC | Story | Evidence | Result |
|---|---|---|---|
| AC01 seven routes, messages/actions |001| p2001-ui tests; normal browser standalone401/429/503/504; maintenance and unsupported journeys; offline boundary |PASS|
| AC02 real HTML HTTP statuses, blob, preserve401 |001| p2001-system each429/503/504 POST+blob; browser realHTMLproxy; existingauthregression |PASS|
| AC03 shell/root fallback, no mutation replay |001| normalbrowser shell sidebar retained; standalone503/504; POST fetchcalledonce unit |PASS|
| AC04 maintenance routing/503, flagoff |002| middleware unit; realmaintenance browser; normalbrowser |PASS|
| AC05 operator ETA or unavailable |002| configuredUTC browser; unknownETA unit/axe |PASS|
| AC06 signed scoped bounded bypass |002| altered/expired/role/purpose/lifetime/secret/query tests; sameoriginbrowser denial; HttpOnly/SameSite/Secure productioncookie; login/MFA/RBAC stillactive |PASS|
| AC07 legacy/modern distinction and downloads |003| pureUA tests; rawIE/Edgefallback; supported4links |PASS|
| AC08 no scripts/noJS/no blank page |003| middlewareHTMLassertion; browserjavaScriptDisabledlegacycontext; pageerrorlistempty |PASS|
| AC09 regression/offline/mobile/a11y |all|766 unit/integration/regression tests; 6 production browser journeys; axe; mobileoverflow; existinggates |PASS|

Definition of Done:PASS. T012 resolved the75 recorded build errors in18 files. Normal npm run build with lint enabled exits0 (build-repair.log); full suite766 tests/50 suites/21 snapshots passes (repair-regression.log); six production browser journeys pass. Final types/strict repair lint pass. Original build-blockers.txt/build.log retained as historical evidence.

## T019/T020 live convergence reassessment — 2026-10-16

| Criterion | Evidence | Result |
|---|---|---|
| User list/detail and reversible suspend/reactivate | Authenticated Playwright live workflow; both status PATCH responses 200; user screen returned to active state | PASS |
| User detail wallet/transaction reads | Same authenticated browser workflow asserted `/v1/admin-app/wallets?user_id=…` and `/transactions?user_id=…` return 200; focused API list/filter tests pass | PASS |
| Agent list/detail and reversible suspend/reactivate | Authenticated Playwright live workflow; both status PATCH responses 200 and detail action buttons follow committed state | PASS |
| API read-model source/contract | Wallet module tables and balance service; transaction projection table; filtered rows and response aliases asserted in focused feature tests | PASS |
| Normal build after final convergence changes | Initial retry hit Node `Zone Allocation failed - process out of memory` while Playwright-owned Next dev servers remained; clean retry after stopping them passed with all79 pages | PASS |
| Broad admin route, role workflow and dynamic details | Historical 39/75 static failures, role-create workflow failure and 19 skipped dynamic details remain | FAIL |

ADM-P2-001 remains PARTIAL; current Definition of Done remains FAIL. The former user-reactivation pending observation was caused by the live browser run reaching its 45-second timeout before a complete journey; a new focused journey passed in 57.5 seconds. An agent cycle passed in 35.6 seconds. No visual screenshot claim is added. See [latest repair evidence](../admin-browser-validation/session-fx-repair-report.md).
