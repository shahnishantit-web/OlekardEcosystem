# ADM-P1-021 verification and deployment
Run from C:\Projects\OleKardEcosystem.

## Dependencies
Existing E22 Support, E21 Fees, E11 Transaction/history, E07 Ledger, E06 Wallet, E01/E04 Identity/RBAC and E17 Notification schemas/services are required. Local additive prerequisites were provisioned in migrations.log and fee-migrations.log. Permission-grant seeds were excluded from local implementation work.

New migrations:
- app/Modules/Support/Infrastructure/Migrations/2026_10_15_021000_admin_support_cases.php
- app/Modules/Notification/Infrastructure/Migrations/2026_10_15_021100_support_notification_templates.php

Apply through the project's normal migration/release process after dependency schemas exist. Templates insert only when absent; existing administered content is preserved.

## Reproduce
Backend acceptance and all Support regressions:
    docker exec -e REDIS_CLIENT=predis wallet-ecosystem-api-1 php -c docker/support-uploads.ini -d memory_limit=512M vendor/bin/pest tests/Feature/Modules/Support --no-coverage --compact

Related backend regressions:
    docker exec -e REDIS_CLIENT=predis wallet-ecosystem-api-1 php vendor/bin/pest tests/Feature/Modules/Risk/AdminSecurityTest.php tests/Feature/Modules/Notification/AdminNotificationTest.php tests/Feature/Modules/Integration/AdminIntegrationTest.php tests/Feature/Modules/Identity/RequirePermissionMiddlewareTest.php tests/Unit/Modules/Identity --no-coverage --compact

From wallet-ecosystem/apps/admin:
    node node_modules/jest/bin/jest.js --runInBand --testPathPattern 'p1021|p1020|middleware|auth|navigation' --watch=false
    npx.cmd tsc --noEmit --pretty false
    node node_modules/eslint/bin/eslint.js src/modules/support __tests__/features/support/p1021.test.tsx e2e/p1021-support.spec.ts lib/api/authenticated-client.ts --max-warnings=0
    node node_modules/@playwright/test/cli.js test --config playwright.p1021.config.ts

Browser harness starts a separate Next.js server on3021 with isolated synthetic API responses. Real financial/security assertions live in rollback-based backend tests. No shared database reset or Redis flush is required. Do not run older destructive Identity E2E fixtures against shared infrastructure.

## Operator UAT
Open /support/cases with support.investigate. Exact assign/resolve/refund/escalate permissions control their actions. Customer and transaction links retain their existing independent permission gates.
Use an E22-approved dispute, a completed original transaction and a real owned wallet currency pocket. Enter amount/reason, initiate the forced enrolled TOTP/PIN challenge, verify and confirm. When central support.dispute.refund.require_second_approval is enabled, a distinct privileged administrator confirms the identical pending amount/reason.
Verify balanced journal, linked type=refund transaction/history, DISPUTE_RESOLVED_WITH_REFUND outbox record and customer notification. Escalate a case to a senior holding support.assign and support.investigate; check24h deadline and senior notification.

## Release notes
Rebuild/reload the API image to apply docker/support-uploads.ini: post_max_size20M and upload_max_filesize10M. Current pre-existing image defaults remain until rebuild/reload. Boundary tests load the committed INI directly.
Legacy POST /v1/disputes/{id}/refund and PATCH /v1/disputes/{id}/confirm-refund preserve their URLs/response shapes and now require scoped actor/session-bound step_up_token plus UUID Idempotency-Key. See updated openapi/v1/support.yaml.
The Notification outbox/queue workers must run. In-app delivery is verified; existing external channel implementations are logging adapters.
Preserve existing RBAC grants and use approved role administration for Support permissions. Ordinary support SLA remains unspecified.
Do not proceed to ADM-P1-022 automatically.
