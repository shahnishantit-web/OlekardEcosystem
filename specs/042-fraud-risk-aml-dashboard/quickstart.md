# Validation guide
Prerequisites: local API/PostgreSQL/Redis containers, node_modules and Playwright browser.
Run scoped AdminSecurity backend feature tests plus existing Risk/Security regressions in API container using test DB only.
Run frontend p1018 Jest, middleware/auth/nav regressions, TypeScript and lint.
Browser: queue filters/detail; notes/link/assignment/resolve/escalate; maker freeze request and checker approval; findings lifecycle; permission denial; desktop/narrow screenshots.
Record exact results, security review, story AC convergence and DoD in implementation-status.md.

## Verified execution (2026-10-15)
See implementation-status.md for exact commands and evidence. Backend: 61 passed; architecture: 4 passed/1 vendor deprecation; frontend: 154 passed; browser: 3 passed; scoped lint/TypeScript passed.
Local prerequisites and new migrations have been applied. New role permission is security.alerts.read; mutation permissions remain risk.manage_alerts / risk.freeze_account or security.findings.write. Existing actor roles were not changed.
Optional notification configuration: active template key security.account_frozen_aml, variables occurred_at and support_ref, channels from existing E17 configuration; scheduler and queue workers must run. No external provider send was performed during automated tests.
