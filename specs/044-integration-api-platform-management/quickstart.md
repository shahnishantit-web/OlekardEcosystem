# Validation
Use PHP8.4/PostgreSQL containers and installed admin Node/Chromium.
Ensure E25 and Ops schemas exist, then apply only additive044 migration. Do not run reset or destructive database refresh.
Run new AdminIntegrationTest and existing E25 feature tests, Notification Admin regression and architecture checks.
Admin: direct node Jest p1020/p1019/auth/middleware/navigation; tsc; scoped ESLint; Playwright config playwright.p1020.config.ts.
Verify client create/one-time dismissal/rotate/revoke/IP denial; callback filters and duplicate-safe retry; per-provider health/chart/no-data/error masking.
Browser mocks contain test-only credentials; backend verifies real hash/auth/RBAC/PostgreSQL behavior. No live provider calls or production secret capture.
