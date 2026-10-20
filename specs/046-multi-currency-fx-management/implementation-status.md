# ADM-P1-022 implementation status
Date: 2026-10-15. Agent: Codex + installed Spec Kit. **Epic: PARTIAL. Definition of Done: FAIL.**
No next epic started. No commit, deployment, external vendor selection or credentials added.

## Stories
| Story | Status | Evidence / remaining work |
|---|---|---|
| US-P1-022-001 | Implemented; scoped AC PASS | Supported currencies include precision and centrally configured Base; status proposals require distinct checker and durable E28 approval; old approvals cannot undo later decisions. Base explicitly Unconfigured when no approved value exists. |
| US-P1-022-002 | PARTIAL | Current rates, exact spread %, manual locking, maker-checker, provider contract/reversion/refresh behavior implemented and tested using injected adapter. No approved live vendor/credentials/adapter; production provider path safely returns503. T015 open. |
| US-P1-022-003 | Implemented; scoped AC PASS | 30-day rate-change chart with manual annotations; complete paginated effective-rate history with source and UTC timestamp. |

## Technical implementation
Extended existing E29 services/screens instead of replacing them. Added two additive provenance/application migrations, explicit FX provider boundary with unavailable default, and catalogue API aliases. E28 requests are reused for currency status and rate approval. Rate submission serializes UUID idempotency and pair operations, binds actor/payload/explicit effective date and replays original proposed IDs after provider refresh. Activation checks distinct checker and exact approved rate, spread, source, provider and initial effective date. Future rates cannot activate early. Provider observations cannot replace manual locks or a different provider and must advance observation time. Existing superseded rate values are retained.

Fixed E28 draft version allocation under a configuration-key lock after repeated FX approvals exposed version1 collisions. Added real Eloquent model type annotations. Existing customer quote/conversion URLs and ledger postings remain; active-pair selection additionally respects effective time and disabled currencies. Auth/MFA implementation and permission grants remain unchanged.

Compatibility: existing admin URLs remain. Currency status PATCH now submits approval instead of immediately applying. Legacy rate activation requires configuration.approve in addition to existing FX permission and UUID Idempotency-Key. New currency registration is inactive and audited; existing currencies use PATCH; precision changes rejected because existing financial engine assumes two minor-unit decimals. These restrictions close financial approval bypasses.

## Verification
| Check | Result | Evidence |
|---|---|---|
| FX unit + API + server permission regression | PASS:38 tests,142 assertions (12 decimal unit,22 ADM022 integration,4 permission) | backend-unit-regression.log |
| Existing identity + controller architecture | No failures:23 passed,1 deprecated,59 assertions | security-architecture-regression.log |
| Frontend + support/auth/navigation regression | PASS:119 tests,13 suites; final changed-screen subset6/6 | frontend.log; frontend-final.log |
| Chromium browser | PASS:3 journeys; desktop1440x1000/mobile390x844,6 screenshots | browser-final.log; browser-results.json; screenshots/ |
| TypeScript | PASS | types-final.log |
| Scoped ESLint/a11y | PASS,0 warnings in changed pages and tests | lint-scoped.log |
| PHPStan level6 | PASS across all19 changed production PHP files | phpstan-final.log (18); phpstan-convergence.log (3 overlapping/final) |
| Broad existing E29 regression | FAIL:66 failed,16 passed at assessment | e29-regression.log; T016 |

The broad E29 run is not a passing release regression. Most failures occur before endpoint assertions because MultiCurrencyTestCase inserts wallets without now-required wallet_reference; its fixtures also contain obsolete Ledger/configuration fields and permission slugs. Quickstart assumes preseeded currencies, while the role-grant seed was deliberately excluded. Existing tests also expect immediate currency changes and activation without approved E28 workflow. The suite requires fixture/expectation repair before rerunning financial regression. Broad counts are from the assessment run before final added cases; they are not a final all-suite count.

Test isolation: real PostgreSQL and E28 services, test-only authentication middleware with DB permissions, provider injected only in controlled tests. Cross-connection PostgreSQL lock contention verified; no full multi-process HTTP/load certification claimed. Browser requests use explicit API fixtures; real backend behavior is verified separately. Initial red run stopped on missing E29 schema (0 assertions), then reviewed additive E29/E28 prerequisites were applied in local container; no database reset, role-grant seed or external provider call. A simultaneous Next route-type regeneration caused a transient type-file failure; final serial TypeScript check passed. Existing PHPUnit XML warning, vendor deprecation, old PHPStan notices, middleware lint warnings and auth React act warnings are baseline; none are represented as new test failures.

## Browser visual verification
Inspected currencies desktop/mobile, FX mobile, and focused history desktop/mobile screenshots. Labels, states, manual annotation, exact rates and UTC table readable. Tables and chart scroll horizontally on narrow screens; no document overflow. Keyboard focus and forbidden route covered. Provider failure explicit. Not a live-provider browser verification.

## Security and release
See security-review.md. Fixed approval bypass, conflicting replay/duplicate pending rate, stale currency approval replay, financial precision mutation and provider exception disclosure. No confirmed new critical/high finding in scoped review; full financial regression and live-provider verification remain release blockers.

## Acceptance / DoD
AC01,02,03,04,06,07 PASS. AC05 FAIL/BLOCKED for live provider (safe unavailable behavior and injected-adapter contract PASS). Overall AC FAIL; DoD FAIL because mandatory provider functionality is not verified live and broad E29 regression fails.
Traceability: traceability.md. Files: files-changed.md and SHA256 manifest files-changed.json.

## Recommended next action
Approve/select the FX vendor and configure an authenticated adapter plus its approved freshness/refresh policy, complete T015 live provider tests, repair broader E29 fixtures/expectations in T016 and rerun financial regression, then repeat convergence for this epic. Do not start ADM-P1-023.
