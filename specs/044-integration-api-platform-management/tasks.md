# Tasks ADM-P1-020
## Foundation
- [X] T001 Review source/governance and design in specs/044-integration-api-platform-management/research.md.
- [X] T002 Add compatible schema/contracts in services/api/app/Modules/Integration/Infrastructure/Migrations/2026_10_15_020000_admin_integration_management.php and Domain/Contracts/ProviderHealthInventoryInterface.php.
## US1 Credentials
- [X] T003 [US1] Add acceptance/security tests in services/api/tests/Feature/Modules/Integration/AdminIntegrationTest.php.
- [X] T004 [US1] Extend existing client service/DTO/auth and safe Admin aliases in services/api/app/Modules/Integration/Application/Services/ApiClientApplicationService.php and Infrastructure/Http/Controllers/AdminIntegrationController.php.
- [X] T005 [US1] Complete client form/one-time secrets/rotate/revoke in apps/admin/src/modules/integrations/pages/api-client-list.tsx and api-client-detail.tsx.
## US2 Callback log
- [X] T006 [US2] Add filters/retry/duplicate-worker tests in services/api/tests/Feature/Modules/Integration/AdminIntegrationTest.php.
- [X] T007 [US2] Implement durable retry intents and guards in services/api/app/Modules/Integration/Infrastructure/Jobs/ProcessWebhookEventJob.php and RetryWebhookEventJob.php.
- [X] T008 [US2] Complete callback monitor in apps/admin/src/modules/integrations/pages/webhook-monitor.tsx.
## US3 Health
- [X] T009 [US3] Add per-provider/history/probe/no-data tests in services/api/tests/Feature/Modules/Integration/AdminIntegrationTest.php.
- [X] T010 [US3] Extend collector/history through contracts in services/api/app/Modules/Ops/Application/Jobs/ProviderHealthCheckJob.php and Application/Services/IntegrationHealthHistory.php.
- [X] T011 [US3] Complete provider health list/detail in apps/admin/src/modules/integrations/pages/integration-list.tsx and provider-health.tsx.
## Verification
- [X] T012 Wire routes/navigation/contracts in services/api/routes/modules/admin-integration.php, services/api/openapi/v1/admin-integration.yaml and apps/admin/middleware.ts.
- [X] T013 Run regression/browser checks in apps/admin/__tests__/features/integrations/p1020.test.tsx and e2e/p1020-integrations.spec.ts.
- [X] T014 Record status/AC/DoD/files in specs/044-integration-api-platform-management/implementation-status.md.
## Dependencies and independent tests
Foundation -> US1 -> US2 -> US3 -> verification. US1 independently proves hash/auth/revoke; US2 filtered processing and durable single retry; US3 separate same-type partner history.
Parallel opportunities: after backend contracts, UI test files and health query tests are independent. No subsequent epic.
