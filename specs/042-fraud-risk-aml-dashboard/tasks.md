# Tasks — ADM-P1-018
## Phase 1: Setup
- [x] T001 Correct spec/clarification in specs/042-fraud-risk-aml-dashboard/spec.md.
- [x] T002 Complete design/constitution in specs/042-fraud-risk-aml-dashboard/plan.md.
## Phase 2: Foundation
- [x] T003 Add backend acceptance/security tests in services/api/tests/Feature/Modules/Risk/AdminSecurityTest.php.
- [x] T004 Add schema/permissions in services/api/app/Modules/Risk/Infrastructure/Migrations/2026_10_14_018000_add_admin_investigation_tables.php.
## Phase 3: US1
- [x] T005 [US1] Implement queue adapter in services/api/app/Modules/Risk/Infrastructure/Http/Controllers/AdminSecurityController.php and routes/modules/admin-security.php.
- [x] T006 [US1] Implement typed API/queue/nav/middleware in apps/admin/src/modules/security/ and apps/admin/middleware.ts.
- [x] T007 [US1] Test filter/access/pagination in apps/admin/__tests__/features/security/p1018-security.test.tsx.
## Phase 4: US2
- [x] T008 [US2] Add evidence/versioned actions in services/api/app/Modules/Risk/Application/Services/InvestigationService.php.
- [x] T009 [US2] Add investigation route and apps/admin/src/modules/security/pages/alert-detail.tsx.
- [x] T010 [US2] Test investigation/resolution/conflict in services/api/tests/Feature/Modules/Risk/AdminSecurityTest.php and frontend tests.
## Phase 5: US3
- [x] T011 [US3] Add checker freeze and durable event/notification in InvestigationService.php and Notification module.
- [x] T012 [US3] Add freeze request/approval UI in apps/admin/src/modules/security/pages/alert-detail.tsx.
- [x] T013 [US3] Test event/audit/notification/replay/maker denial in services/api/tests/Feature/Modules/Risk/AdminSecurityTest.php.
## Phase 6: US4
- [x] T014 [US4] Extend findings states/constraint/audits in services/api/app/Modules/Security/.
- [x] T015 [US4] Complete findings CVE/status/filter/pagination UI in apps/admin/src/modules/security/pages/findings.tsx; retain suppression/overview.
- [x] T016 [US4] Test findings lifecycle and permissions in backend/frontend tests.
## Phase 7: Validation
- [x] T017 Run related regression, TypeScript/lint, browser and record specs/042-fraud-risk-aml-dashboard/implementation-status.md.
- [x] T018 Converge AC/security/DoD in specs/042-fraud-risk-aml-dashboard/implementation-status.md.

Dependencies: foundation → US1 → US2 → US3; US4 follows foundation independently. Backend/frontend test writing can proceed independently; shared edits sequential. All four stories mandatory.

## Phase 8: Convergence
- [x] T019 Repair the missing IntegrationEventInterface methods in services/api/app/Modules/Platform/Domain/Events/TransactionReversedEvent.php and add a contract regression test; the existing architecture test fatals before it can verify new code (Constitution 8/9/15, partial, HIGH).
- [x] T020 [US2] Include latest subject evaluations beyond alert-event links in services/api/app/Modules/Risk/Application/Services/InvestigationService.php and test an unlinked historical score per US2/AC1 (partial, HIGH).

## Phase 9: Convergence
- [x] T021 Repair inherited timestamp-property collisions in services/api/app/Modules/Risk/Domain/Events/AmlRestrictionAppliedEvent.php and AmlRestrictionLiftedEvent.php; preserve named constructor inputs and event payloads and add contract tests (Constitution 9/15, partial, HIGH).
