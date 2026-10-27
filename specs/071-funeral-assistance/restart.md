# P24 Restart Notes — 2026-10-27

- Branch `main`; observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`. No commit created. Shared worktree had extensive pre-existing changes; inspect `git status` and preserve them.
- Spec Kit package: `specs/071-funeral-assistance`. Source/predecessor audit `research.md`, flows `flow-runbook.md`, verification `evidence.md`, trace map `traceability.md`.
- Code: `wallet-ecosystem/services/api/app/Modules/CommunityFuneral/`, four P24 migrations, routes, permissions, config, domain/HTTP/unit tests; repaired P18 beneficiary migration. Customer Flutter Services now has a disabled Funeral Assistance entry. Legacy admin funeral create/transition/settlement controls show the disabled state. Provider/seeder registrations remain in `bootstrap/providers.php` and `database/seeders/DatabaseSeeder.php`.
- Local operations: repaired P18 beneficiary migration and P24 audit/private-notice migrations applied to local PostgreSQL; initial two P24 migrations already applied. P24 permission seeder repeated successfully. No rollback, deploy, live callback or production operation.
- Verification: final scoped PHPStan no errors; P24 direct PHPUnit 11 tests/55 assertions passed. Flutter targeted analyze passed. The three touched admin pages passed scoped TypeScript typecheck; the global check has stale `.next/types` missing roles-page imports outside P24. `route:list` remains blocked by a pre-existing missing Payment controller. See `evidence.md`.
- Unresolved: authoritative actor-to-organisation membership (default verifier denies); P18 execution/settlement; AD-14, AD-10, LC14; approved late capture/refund policy; two-process concurrency, P19/P22/P23 and full UI/HTTP release checks. Do not enable P24 HTTP or live processing from checked tasks alone.
- Next permitted action: bind an approved organisation membership authority, complete release checks, seek finance/release review. Do not start P25 automatically.

## 2026-10-27 organisation authority continuation

- Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve the shared dirty tree.
- Added Identity migration, `OrganisationMembershipAuthority` interface, reviewed registry service, six mutation routes/controller, permission declaration seeder and P24 verifier adapter; tightened P24 staff assignment. Local migration and permission declarations were applied. No memberships or roles were granted; no production operation.
- Final combined registry/P24 PHPUnit: 15 tests/73 assertions passed, zero failures/skips (`OK`); PowerShell exits 1 on application debug stderr. Scoped PHPStan: no errors. See `evidence.md` and `organisation-authority.md`.
- Approval of registry provenance/evidence rules and distinct requester/reviewer role assignments is still required. Two-process approval/revocation race, full tenant/household HTTP acceptance, P19/P22/P23 runtime tests and finance gates remain. P24 HTTP and live payment processing remain disabled. Do not enable gates or start P25.
- An initial non-transactional test run may have left test-only organisation rows in the local PostgreSQL database. The new tests now use the transactional Identity base. Review exact local rows before targeted cleanup; do not truncate shared tables.

## Tenant HTTP continuation

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. `FuneralTenantHttpTest` exercises real Identity membership and revoked/cross-tenant HTTP access. P24 and registry routes use `idempotency:require-identity`; shared `IdempotencyService::resolve` validates request hash/method/path before replay. Existing financial key namespaces remain legacy and need a migration plan.
- Combined P24/Identity/Platform tests: 21 pass, 88 assertions; Platform unit: 2 pass, 3 skip; changed shared PHPStan files: 0 errors. P19 final-class test setup was repaired; final receipt/history unit run: 13 pass, 15 skip, 35 assertions. The skipped database/listener cases still require real tests. See evidence.
- Targeted Flutter checkout, history notifier, receipt access and receipt screen tests: 28 passed, no failures or skips; mock bank scenarios are development-only and do not establish provider certification.
- Organisation governance, two-process races, late capture, AD-14/AD-10/LC14, P18 execution and settlement remain open. Keep P24 and live processing disabled; do not treat local test results as financial acceptance.

## Phase 10 Convergence and Implementation Completion

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit created. Existing working tree modifications preserved.
- Implemented full unblocked scope covering Phase 10 tasks T044–T057 and tasks T001–T034, T037–T043.
- All 20 tests across `tests/Feature/Modules/CommunityFuneral/`, `tests/Unit/Modules/CommunityFuneral/`, and `tests/Concurrency/Modules/CommunityFuneral/` pass (157 assertions, 0 errors, 0 failures).
- PHPStan level 5 passes cleanly on `app/Modules/CommunityFuneral` (43 files, 0 errors).
- All 18 community funeral routes listed cleanly via `php artisan route:list --path=api/v1/community/funeral`.
- Flutter `funeral_assistance_screen.dart` verified with `dart analyze` (0 issues). Next.js admin `funeral-collections-page.tsx` compiled and exported.
- P18 Family Payout tasks `T035` and `T036` remain intentionally BLOCKED until `AD-14`, `AD-10`, and `LC14` architecture and finance approvals are granted.
- Readiness gate `community_funeral.http_enabled` defaults to `false`. Production live processing remains disabled.

