# ADM-P2-001 implementation plan
## Technical Context
Existing Next14/React18/TypeScript, Edge middleware, shared ErrorPage/AdminShell and Jest/Playwright. UI-only, no Laravel/schema/business API changes. No dependency upgrades.
## Constitution Check
PASS: approved scope; no invented estimates/browser policy; server-only secret; auth/MFA/RBAC unchanged; no financial state; shared tokens; accessible actions; tests/security/traceability required. No additional Factory constitution/AGENTS found.
## Design
1. Shared typed system-status descriptions + safe normalized HTTP failure events. Both clients inspect429/503/504 before JSON; do not retry or alter401 flow.
2. Extend ErrorPage with optional embedded presentation. Boundary inside AdminShell handles system status/offline without destroying shell. Root routes work independently; root failures remain.
3. Server-only maintenance config. HMAC SHA256 purpose/role/exp token, maximum1h, secret>=32chars; trusted operations mint helper. Token cookie is HttpOnly SameSiteStrict secure inproduction; POST same-origin form and boundedinput. Failclosed absentsecret. No tokens in URLs. Bypass only maintenance, then existinggates.
4. Middleware maintenance interception with503rewrite and no-store; independent status/assets/bypass endpoint allowlist. No broad auth exemptions. API503 JSON safe; public status routes exact.
5. Browser detection pure helper reused rootlayout and Edge raw HTML fallback; script-free legacy response with static links/inlineCSS. No external JS/CDN dependency.
6. Seven pages and real offline events; no sensitive caching. Unknown ETA explicit. Document operator setup and API behavior.
## Verification
Test-first unit/integration: token tamper/expiry/purpose/roles, routing/gates, HTML statusclient andblob handling/no replay, pages/boundary/offline/accessibility. TypeScript/scopedESLint, existing auth/layout/client/middleware regressions. Chromium desktop/mobile screenshots; JavaScript-disabled legacy response; real maintenanceflag-on and valid/invalid bypass with auth/MFA preserved. Final read-only convergence.
## Dependencies
ADM-FOUND-001 component available; existing relatedADM001/002/023authgates reviewed. Remaining ADM022 providerblocker is unrelated to thisUI-onlyepic. No external dependencyblocks. Deploymentsecrets/returntime areoperatorconfig, not fabricated.

## Authorized browser regression repair
T013: split oversized JWTs into at most four 3,000-character cookie values with a bounded count marker; reconstruct identically in Edge middleware and server layouts. Retain single-cookie compatibility. Remove unused duplicate permission cookie; derive UX permissions from the original token as before. Clear all chunks on replacement/logout and use the existing token expiry. Backend signatures, permissions and MFA remain authoritative and unchanged.
T014: FX submission explicitly accepts only the existing FX_PROVIDER_UNAVAILABLE domain code from a JSON 503 envelope. Other 503 errors still trigger system status handling before ordinary parsing, including proxy HTML. Do not replay requests.
T015: real API uses isolated QA database/Redis and no queue worker/external delivery; distinguish fixture UI coverage from persistent backend workflow evidence. No release pass while mandatory regression or live blockers remain.

## T012 authorized follow-up (2026-10-16)
User explicitly authorized resolving all recorded build blockers and reassessing DoD. Reuse/extend the shared Dialog for the repeated modal wrappers, preserving titles, classes, handlers, approval payloads and permissions. Add focus/backdrop/Escape regression coverage; fix unused imports, redundant roles and JSX/alt issues in the eighteen recorded files. No lint-rule weakening or backend/API changes. Run the normal build, all frontend regressions and production browser checks before status reassessment.
