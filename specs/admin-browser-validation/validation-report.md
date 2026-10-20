# Admin browser validation — 2026-10-16

> Superseded by the [session/FX repair and repeated validation](session-fx-repair-report.md). Both original blockers are repaired. Current overall status is PARTIAL with additional live API/rendering failures; the historical results below describe the initial validation only.

**Overall status: BLOCKED. All-functionality acceptance: FAIL. Definition of Done: FAIL.**

The panel cannot be certified as fully functional. A full-permission administrator cannot persist a browser session, and one cross-feature error-handling regression was reproduced. This is a validation result, not a completed remediation.

## Coverage and results

Playwright Chromium ran against Next.js on port 3030 and a real Laravel API on port 8001. The final combined run completed 94 tests in 3.3 minutes: 34 passed and 60 failed. These are test counts, not 94 verified routes.

| Coverage | Result | Interpretation |
|---|---|---|
| Route inventory | 94 routes: 75 static, 19 dynamic | Inventory is not functionality verification |
| Interactive fixture workflows | 18 PASS / 1 FAIL | Risk, notification, integration, support, FX, security; APIs intercepted in these tests |
| Live static-page run | 16 PASS / 59 FAIL | All 59 failures are unexpected login redirects, not 59 independent backend defects |
| Persistent real browser login, focused retest | FAIL | Initial navigation alone was an insufficient assertion; strengthened test confirms missing token cookie |
| Dynamic pages with real records | BLOCKED, 19 route patterns | Some details exercised with fixtures; no claim of live verification |
| Desktop/mobile visual review | PARTIAL PASS | Opened risk investigation mobile and notification delivery desktop images; fixture journeys include responsive assertions |
| Financial mutations, external delivery/providers, real MFA and maker/checker persistence | NOT VERIFIED | Fixture tests do not prove backend side effects or provider integration |
| Normal build / complete unit regression in this validation run | NOT RUN | Previous epic evidence remains historical; no production implementation changed here |

The 16 live passes include an initial login URL transition. It is superseded by the failed persistent-login retest and must not be described as a successful working login. Some public-page checks permit intentional redirects; they are smoke checks, not full form/workflow acceptance.

## Findings

### BQA-001 — High: full-admin login does not persist

The real API issued a 6,635-byte JWT containing 195 permission claims. `AuthProvider.saveSession` writes the token and encoded permission array into individual cookies. Chromium retained only `admin_user` and `admin_sid`; neither `admin_token` nor `admin_permissions` persisted. All protected static pages subsequently landed on `/login`. Individual cookie size limits make this representation unsuitable for the full-permission account.

Evidence: `auth-cookie-finding.json`, `login-persistence.log`, `live-results.json` (focused retest), `combined-results.json`, and `routes/*.json`. No raw token is included in the finding. The full-permission QA account was provisioned from existing declared permissions; role sizes in other environments can differ.

Remediation must preserve server-side JWT validation, RBAC and MFA. Use a reviewed session/token storage design supporting the complete permission set, then verify login, reload, navigation, expiry, logout and negative authorization with real API responses.

### BQA-002 — Regression: FX provider failure loses its actionable message

The existing FX workflow posts provider mode and receives a controlled 503 with “FX provider is not configured. Provider selection remains pending.” The new global system-status handling replaces the page with “Service temporarily unavailable”; the existing FX status assertion fails. `throwForSystemStatus` dispatches the global boundary before the domain error can remain visible.

Evidence: combined results, the FX fixture test, and `fixture-screenshots/p1022-fx/`. This is a fixture-reproduced integration regression, not evidence of an actual provider outage. The remediation needs to reconcile domain-specific 503 handling with generic proxy/service failures and retain the no-automatic-mutation-retry guarantee.

### BQA-003 — Deployment/fixture prerequisite: permission registry incomplete

The cloned local database initially lacked 82 permissions already declared by application routes/UI. Existing development permission grants alone were insufficient. Only the QA clone received those definitions and grants; its identity permission cache was invalidated before fresh login. This is a local snapshot/provisioning finding, not proof of a production deployment defect. It also exposed BQA-001 at the complete permission count.

### BQA-004 — Backend integration finding

Laravel route listing encountered a missing `App\Modules\Withdrawal\Infrastructure\Http\Controllers\WithdrawalCallbackController`. Admin impact was not established because live workflows are blocked by BQA-001. Track separately; no callback implementation was invented during validation.

## Isolation and security

Testing used database `olekard_browser_qa_20261016` and dedicated API/Redis containers. Mail used the array transport; no QA queue worker was started. No financial or external-provider mutation was performed. Initial harness results were discarded after discovering Laravel's development server stripped environment overrides; the corrected API used `serve --no-reload`. The initial authentication probe may have created a development login session before isolation was corrected. No financial actions ran during that attempt.

Private `.auth/` and `.env*` files are excluded by this directory's `.gitignore` and must not be shared. Temporary source-storage helper scripts were removed after provisioning. No production RBAC, authentication, MFA, API or domain implementation was changed. Browser validation is not a penetration test or dependency audit.

## Evidence and reproducibility

- `combined-results.json`, `combined-summary.json`, `combined-browser.log`: final broad run, including the failing FX scenario.
- `live-results.json`, `login-persistence.log`: focused persistence regression, expected FAIL until fixed.
- `route-inventory.json`, `routes/`, `fixture-screenshots/`: coverage inventory and page evidence.
- `initial-harness*`, earlier `live-browser.log`, and `fixture-browser.log`: superseded harness diagnostics; excluded from conclusions.
- Admin `playwright.audit.config.ts` and `e2e/admin-{live-audit,fixture-*}.spec.ts`: retained QA harness. Run with isolated services and local auth setup; do not use production credentials.

## Next action

Resolve BQA-001 first and BQA-002 next. Repeat the 59 protected static checks with persistent real authentication, then exercise all 19 dynamic route patterns using real QA records. Complete safe real-API create/edit/approve/export flows, permission-negative tests, MFA and session lifecycle checks, then rerun the normal build and regression suite. Until then, all-functionality acceptance and DoD remain failed. No next epic was started.
