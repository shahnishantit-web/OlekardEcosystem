> Reassessment, 2026-10-16 broad admin browser validation: **Epic PARTIAL; regression gate FAIL; DoD FAIL.** The historical build and test results below remain valid for their original scope, but the FX provider-mode workflow now reproduces a cross-feature regression: the global 503 boundary hides its specific provider-configuration error. Resolve BQA-002 and rerun cross-feature regression before restoring COMPLETE. Full live admin validation is additionally blocked by BQA-001 (full-permission token cookie persistence). See [browser validation report](../admin-browser-validation/validation-report.md). No production fix was made during validation.

# ADM-P2-001 — HTTP Error & System Status Pages
Date: 2026-10-16. Implementation: Codex + installed Spec Kit.
**Epic: COMPLETE. Acceptance Criteria: PASS (9/9). Definition of Done: PASS.**

The recorded production build blocker is resolved. The normal `npm run build`, with lint and type validation enabled, passes with exit code0. All79 pages are generated. T001–T012 are complete; no mandatory story or technical task remains pending. No next epic started.

## Stories
| Story | Status | Evidence |
|---|---|---|
| US-P2-001-001 — Friendly HTTP error pages | COMPLETE | Seven status routes, shell/root fallback, normalized proxy errors, no mutation retry; production browser checks |
| US-P2-001-002 — Maintenance mode | COMPLETE | Runtime flag/ETA and verified expiring bypass, secure cookies; existing login/MFA/RBAC retained |
| US-P2-001-003 — Unsupported browser | COMPLETE | Script-free legacy response, browser list/links, no-JS browser checks |

## Technical implementation
Existing ErrorPage, AdminShell, API clients and authentication context are reused. Seven routes implement /401, /429, /503, /504, /maintenance, /offline and /unsupported-browser. API429/503/504 are recognized before JSON parsing, including proxy HTML and blob requests. Safe messages, bounded Retry-After and correlation references replace raw traces; failed mutations are never automatically replayed.

Maintenance uses server-only configuration, optional UTC return time, and HMAC-signed purpose/role/expiry capabilities. The form is same-origin and byte-bounded. Cookies are HttpOnly, SameSite Strict and Secure in production. A bypass skips maintenance only; existing identity gates still apply. Legacy engines receive script-free HTML before hydration. Offline handling stores no sensitive business data.

## T012 — build blocker resolution
User explicitly authorized resolving the75 recorded lint errors in18 files. Those errors are now resolved:
- Reused/extended shared Dialog and migrated13 modal wrappers across agents, audit, float, KYC, roles, transactions, users and wallets. Original styles, labels, handlers, approval/mutation payloads and permission logic remain. Native backdrop dismissal, Escape, focus trapping and opener focus restoration replace mouse-only wrappers and HTML autofocus.
- Removed unused imports/bindings and redundant native button/list roles; escaped JSX apostrophes without changing displayed text; corrected redundant image alt text.
- Reviewed and updated four snapshots whose only changes remove redundant native button roles.
- Added a keyboard/backdrop regression and a production role-modal journey verifying the unchanged creation payload and idempotency-key format.

No Laravel/domain API/schema/role grants, financial state, authentication/MFA or business action handlers changed. Original failure evidence remains in build.log/build-blockers.txt; current successful evidence is build-repair.log.

## Final verification
| Check | Result | Evidence |
|---|---|---|
| Normal production build, lint enabled | PASS, exit0; compile/types and79 pages generated | build-repair.log |
| Full frontend unit/integration/regression suite | PASS:766 tests,50 suites | repair-regression.log |
| Snapshots | PASS:21/21; four intentional role-only updates reviewed | repair-regression.log; repair-snapshots.log |
| Production browser: status/root/shell/offline/legacy/modal | PASS:5 journeys, exit0 | repair-browser.log |
| Production maintenance and identity gates | PASS:1 journey, exit0 | repair-browser-maintenance.log |
| Final TypeScript | PASS, exit0 | repair-types.log |
| Strict lint: shared Dialog and repair/browser tests | PASS, zero warnings | repair-lint.log |

Existing non-blocking lint warnings and React test act notices remain in logs. Global lint rules were not weakened, and no --no-lint flag was used for the successful normal build. The earlier diagnostic build is historical evidence only. No backend files changed, so no backend test rerun was necessary.

## Security and browser review
All required permission, MFA, signed capability and safe error handling regressions pass. The modal repair retains business payloads and confirmation actions. No unresolved critical/high defect was identified in scope; this is not an external penetration test or complete dependency audit.
Desktop1440x1000/mobile390x844 screenshots were opened and visually inspected. In-shell status pages retain navigation; standalone pages remain usable. Repaired role modal preserves layout, focus, keyboard dismissal and mobile wrapping. Legacy rendering is verified through script-free HTML and a JavaScript-disabled legacy-UA Chromium context, not a real IE11 executable.
See [security review](security-review.md), [screenshots](screenshots/), [repaired modal desktop](screenshots/repair-role-dialog-desktop.png), and [mobile](screenshots/repair-role-dialog-mobile.png).

## Acceptance, convergence and next action
[AC01–AC09 traceability](traceability.md): PASS. [Final convergence](convergence.md): no remaining mandatory gaps. DoD PASS: normal build, full regressions, snapshots, production browser, type/lint, security review, documentation and file traceability verified.
[Changed-file ownership manifest and SHA256](files-changed.md) includes the original epic and authorized T012 repair. The workspace began with untracked project trees, so the manifest records explicit task ownership rather than a git-baseline diff.
Recommended next action: code review/UAT and the normal release process. No deployment or next epic was started.
