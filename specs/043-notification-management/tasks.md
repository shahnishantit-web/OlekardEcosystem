# Tasks: ADM-P1-019
## Phase 1 Setup
- [X] T001 Review sources and document design in specs/043-notification-management/research.md.
## Phase 2 Foundation
- [X] T002 Add schema/permissions and action/outbox plumbing in services/api/app/Modules/Notification/Infrastructure/Migrations/2026_10_15_019000_add_admin_notification_management.php.
## Phase 3 US1 Templates
- [X] T003 [US1] Add template acceptance/security tests in services/api/tests/Feature/Modules/Notification/AdminNotificationTest.php.
- [X] T004 [US1] Implement template listing/edit/preview/self-test in services/api/app/Modules/Notification/Application/Services/AdminNotificationService.php and Infrastructure/Http/Controllers/AdminNotificationController.php.
- [X] T005 [US1] Implement grouped accessible editor in apps/admin/src/modules/notifications/templates.tsx and app/(admin)/notifications/templates/page.tsx.
## Phase 4 US2 Delivery log
- [X] T006 [US2] Add log/filter/retry race/forbidden tests in services/api/tests/Feature/Modules/Notification/AdminNotificationTest.php.
- [X] T007 [US2] Implement log/retry and channel-job guards in services/api/app/Modules/Notification/Application/Services/AdminNotificationService.php and Application/Jobs/DeliveryExecutionGuard.php.
- [X] T008 [US2] Implement log/filter/retry UI in apps/admin/src/modules/notifications/log.tsx.
## Phase 5 US3 Rules
- [X] T009 [US3] Add immediate snapshot/mandatory/outbox tests in services/api/tests/Feature/Modules/Notification/AdminNotificationTest.php.
- [X] T010 [US3] Implement rules/snapshot/outbox in services/api/app/Modules/Notification/Application/Services/NotificationRuleService.php and Application/Jobs/DeliverAdminNotificationEventsJob.php.
- [X] T011 [US3] Implement rules UI in apps/admin/src/modules/notifications/rules.tsx.
## Phase 6 Integration and convergence
- [X] T012 Wire API contracts and RBAC/navigation in services/api/routes/modules/admin-notifications.php, services/api/openapi/v1/admin-notifications.yaml, apps/admin/middleware.ts and src/lib/navigation/nav-items.ts.
- [X] T013 Run frontend/unit/integration/regression and browser checks in apps/admin/__tests__/features/notifications/p1019.test.tsx and e2e/p1019-notifications.spec.ts.
- [X] T014 Record AC/security/DoD and file manifest in specs/043-notification-management/implementation-status.md.
## Dependencies and strategy
T001 -> T002 -> story API/tests -> story UI -> T012 -> T013 -> T014. Build US1 MVP then complete US2 and US3; no next epic.
Independent tests: US1 preview/save/render; US2 combined filters and single existing-row retry; US3 persist/event/new-versus-queued effect.
Parallel examples: after foundation, US1 UI and US2 test authoring use separate files; execution here remains sequential except independent reads/tests.
