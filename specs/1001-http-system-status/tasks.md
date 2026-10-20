# ADM-P2-001 tasks
## Phase1 Governance
- [x] T001 Review approvedcriteria/governance/code/reuse/dependencies; specify andclarify.
- [x] T002 Plan/research/model/contracts/quickstart; constitutioncheck.
## Phase2 Foundation
- [x] T003 Add failing statusclient/middleware/token tests beforeimplementation.
- [x] T004 Shared safe statusmodel, embeddedErrorPage and shell/offlineboundary.
## Phase3 US-P2-001-001
- [x] T005 Sevenstatusroutes and shell/rootpresentations; sharedAPIclients statusbeforeJSON/HTML/blob handling; preserve401.
## Phase4 US-P2-001-002
- [x] T006 Maintenanceflag/503interception/ETA, expiringverifiedbypass and sameoriginHttpOnlycookie endpoint; operatordocs/helper.
## Phase5 US-P2-001-003
- [x] T007 Root/Edgebrowserdetection and script-free fallback withofficialdownloads.
## Phase6 Verification
- [x] T008 Targetedunit/integration/accessibility and existingauth/MFA/RBAC/layout/clientregression; TypeScript/lint.
- [x] T009 RealNext middleware/browser normal+maintenance, desktop/mobile/noJS screenshotsandvisualreview.
- [x] T010 Read-onlyconvergence; remedymandatorygaps; finalstatus/security/AC/DoD/traceability/filemanifest. Stop.

## Phase7: Convergence
- [x] T011 Add formal internal HTTP contract for the maintenance form handler per AC06 / Constitution8 (missing).
- [x] T012 Resolve the existing whole-app production build lint gate:75 errors in18 untouched files; rerun normal build before release per Constitution15 / DoD (partial; outside this epic implementation scope).

T012 resolution (2026-10-16): user authorized the follow-up; all75 recorded errors repaired, normal build exit0,766 tests/21 snapshots and six production browser journeys pass. The original gap description above is retained for history; no task remains open.

## Authorized browser regression repair (2026-10-16)
- [x] T013 Fix BQA-001: bounded token cookie chunks shared by login/MFA, middleware, root/layout and logout; preserve legacy sessions and fail closed on incomplete chunks. Verify full-permission login/reload, MFA/RBAC and cleanup.
- [x] T014 Fix BQA-002: preserve the existing FX_PROVIDER_UNAVAILABLE domain envelope only for FX submission; generic JSON/HTML 503 and other system statuses retain safe global handling; no mutation replay.
- [x] T015 Repeat isolated real-API browser validation, fixture workflows, unit/regression, normal build and visual review; record remaining blockers and honest DoD.

T013–T015 evidence: `../admin-browser-validation/session-fx-repair-report.md`. Both requested defects pass real production-browser checks. The overall admin/release gate remains failed because39 static checks and the role workflow fail on API/response contracts, and19 dynamic patterns lack usable QA links. Those broader defects were recorded rather than silently expanding this repair into unrelated epics.

## Follow-up Admin workflow convergence (2026-10-16)
- [x] T016 Align roles/audit/wallet/transaction list consumers and dashboard KPI calls with observed backend response contracts and registered endpoints; add regression coverage for audit page-based pagination.
- [x] T017 Rerun TypeScript, relevant Jest suites, normal production build and focused production-browser route checks; update convergence/status/security evidence.
- [x] T018 Repair Admin user/agent read models against their owning module schemas, verify authenticated list requests reach the API, and rerun list/detail/reversible action workflows in the live QA browser. Keep release DoD FAIL for unrelated unresolved routes/workflows.

T016-T018 evidence is appended to ../admin-browser-validation/session-fx-repair-report.md. User/agent schema-backed list/detail reads and authenticated list requests are repaired. Live action convergence uncovered stale agent idempotency replay and a browser-side reactivation request that remains pending; see T019.

## Follow-up user/agent live convergence (2026-10-16)
- [x] T019a Replace fixed agent lifecycle idempotency keys with each authenticated request's key; return the lifecycle service's committed status and update the detail screen from the confirmed mutation response.
- [x] T019b Add user and agent suspend/reactivate API cycle regressions, including persisted status checks; add live list/detail/action browser assertions and user detail link semantics.
- [x] T019c Resolve the user reactivation request that remains pending in the live browser, capture a clean agent browser cycle result, and rerun both complete browser workflows. Do not mark full live action convergence until these pass.

T019a-T019c evidence and remaining status: `../admin-browser-validation/session-fx-repair-report.md`.

## Follow-up user detail wallet/transaction convergence (2026-10-16)
- [x] T020a Rebuild AdminApp wallet/transaction list reads on the owning Wallet and Transaction projection schemas; support user scoping and existing response aliases without weakening endpoint permissions.
- [x] T020b Verify authenticated user detail wallet/transaction reads and user/agent reversible status cycles in the live browser; run focused API, frontend, TypeScript and build checks; reassess DoD.

T020 evidence is appended to `../admin-browser-validation/session-fx-repair-report.md`. The user and agent browser workflows each completed successfully. After stopping test-owned Next dev servers left by crashed Playwright retries, the normal production build completed successfully (79 pages). The broader admin/release DoD remains FAIL due previously recorded route/workflow gaps.
# Follow-up convergence — 2026-10-16

- [x] T019d Refresh live authenticated route evidence; renew expired QA browser auth before each route check.
- [x] T019e Align fee, reconciliation, settlement, and report list consumers with root `data` plus sibling `meta` response contracts.
- [x] T019f Provision the isolated QA database with the existing Ops module migrations and verify Ops alert/rule/backup/database-monitoring routes.
- [x] T019g Re-run role create/persist/detail and all dynamic detail patterns; record live passes and skips separately.
- [x] T019h Resolve the 12 current static route failures; retest all 74 static routes across a full sweep and targeted follow-ups.
- [x] T019i Re-run the normal production build after response-contract/read-model edits; inspect the financial-dashboard error state.
- [x] T019j Implement the existing Admin Session Management GET read route with the current permission gate and safe fields; cover API and browser behavior.
- [ ] T019k Provide isolated records for 13 skipped dynamic detail paths and complete a clean expanded dynamic browser run.
- [ ] T019l Complete manual visual review of the remaining updated routes and repeat one single-run 74/74 static inventory.
