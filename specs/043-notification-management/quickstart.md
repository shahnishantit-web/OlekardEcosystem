# Validation
Prerequisites: running PHP8.4 API/PostgreSQL/Redis containers; installed admin Node dependencies/Chromium.
Apply only the new Notification Admin migration after existing E17 schema. No seed or user-role mutation.
Run new AdminNotificationTest plus E17 Unit/Integration Notification and ADM018 feature suites using REDIS_CLIENT=predis; preserve logs.
Admin: npx tsc --noEmit; scoped ESLint; direct node Jest for p1019/auth/middleware/navigation; Playwright with playwright.p1019.config.ts.
Verify templates preview/edit, delivery filters/retry, optional rule toggle/new event effect, mandatory protection; capture desktop and narrow screenshots. UI mocks are isolated; backend tests use real PostgreSQL/RBAC.
