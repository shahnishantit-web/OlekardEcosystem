# Tasks: ADM-P1-016 — Audit Log & Compliance

**Input**: Design documents from `specs/040-audit-log-compliance/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Organization**: Tasks are grouped by user story to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

---

## Phase 1: Setup (Shared Types & Client Methods)

**Purpose**: Add typed audit + compliance types and AdminAppClient methods that all three user stories depend on.

- [x] T001 Add `AuditEvent`, `AuditEventDetail`, `AuditEventCursorMeta` types to `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- [x] T002 Update `listAuditEvents()` return type to `ApiResponse<{ data: AuditEvent[]; meta: AuditEventCursorMeta }>` and add `getAuditEventDetail(id: string)` method in `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- [x] T003 Add `ComplianceDashboard`, `ChecklistItem`, `OpenComplianceItem`, `RetentionDeadline` types and `getComplianceDashboard()` method to `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- [x] T004 Add `RetentionPolicy` type (with `enforcement_hold_until` field), `listRetentionPolicies()` and `updateRetentionPolicy(id, payload)` methods to `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- [x] T005 Add `adminapp.compliance.read` and `adminapp.compliance.config` entries to `ALL_PERMISSION_SLUGS` array in `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`

**Checkpoint**: AdminAppClient fully typed for all three stories — no story can proceed until T001–T005 are done.

---

## Phase 2: Foundational

No additional foundational tasks — existing page shell routes and module structure are in place.

---

## Phase 3: User Story 1 — View Immutable Audit Log (Priority: P1) — MVP

**Goal**: Admin can view reverse-chronological audit events with Event Type, Actor, Entity Type, and Date Range filters; a detail drawer shows full context; zero edit/delete controls visible anywhere.

**Independent Test**: Navigate to `/audit`, apply filters, open detail drawer — verify no edit/delete buttons at any point; verify 90-day range validation fires.

### Implementation for US1

- [x] T006 [US1] Add `hasPermission('adminapp.audit.read')` RBAC gate to `AuditEventsPage` in `wallet-ecosystem/apps/admin/src/modules/audit/pages/audit-events.tsx` — show access-denied card if missing, no API call made
- [x] T007 [US1] Replace Category filter with Event Type text input; add Actor text input (sends as `actor_id`); add Entity Type select (options: `wallet`, `user`, `transaction`, `agent`, `kyc`, `config`, `system`) in `wallet-ecosystem/apps/admin/src/modules/audit/pages/audit-events.tsx`
- [x] T008 [US1] Add `ip_address` field to `AuditEvent` interface and add IP Address column to the events table in `wallet-ecosystem/apps/admin/src/modules/audit/pages/audit-events.tsx`
- [x] T009 [US1] Switch `fetchEvents()` to use `new AdminAppClient(authenticatedClient).listAuditEvents(params)` and `getAuditEventDetail(id)` replacing direct `authenticatedClient.get('/v1/audit/events')` calls in `wallet-ecosystem/apps/admin/src/modules/audit/pages/audit-events.tsx`
- [x] T010 [US1] Write tests for US1 ACs (RBAC gate, filters, detail drawer, no edit/delete, 90-day range) in `wallet-ecosystem/apps/admin/__tests__/features/audit/p1016-audit-log-compliance.test.tsx`

**Checkpoint**: Audit log page fully functional; all US1 tests pass.

---

## Phase 4: User Story 2 — Compliance Dashboard (Priority: P2)

**Goal**: Admin sees a dashboard at `/compliance` with regulatory checklist, open compliance items, and retention deadlines — all colour-coded by urgency.

**Independent Test**: Navigate to `/compliance` with `adminapp.compliance.read` — verify three sections render with appropriate green/amber/red badges; verify access-denied without permission.

### Implementation for US2

- [x] T011 [US2] Replace the body of `ComplianceStatusPage` in `wallet-ecosystem/apps/admin/src/modules/audit/pages/compliance-status.tsx` with a compliance dashboard: add `hasPermission('adminapp.compliance.read')` gate, fetch `new AdminAppClient(authenticatedClient).getComplianceDashboard()` on mount
- [x] T012 [US2] Implement Regulatory Checklist section with urgency badge mapping (`ok`→`success`, `warning`→`warning`, `critical`→`error`) in `wallet-ecosystem/apps/admin/src/modules/audit/pages/compliance-status.tsx`
- [x] T013 [US2] Implement Open Compliance Items section with urgency badge mapping (`low`→`success`, `medium`→`warning`, `high`→`error`) and due date display in `wallet-ecosystem/apps/admin/src/modules/audit/pages/compliance-status.tsx`
- [x] T014 [US2] Implement Retention Deadlines section sorted by `deadline_at` ascending with urgency badges in `wallet-ecosystem/apps/admin/src/modules/audit/pages/compliance-status.tsx`
- [x] T015 [US2] Write tests for US2 ACs (RBAC gate, checklist renders with urgency badges, open items, retention deadlines) in `wallet-ecosystem/apps/admin/__tests__/features/audit/p1016-audit-log-compliance.test.tsx`

**Checkpoint**: Compliance dashboard functional; all US2 tests pass.

---

## Phase 5: User Story 3 — Configure Data Retention Policies (Priority: P2)

**Goal**: Admin can view retention policies; editing requires an explicit confirmation dialog; rows with active `enforcement_hold_until` have Edit disabled; changes are saved via PATCH.

**Independent Test**: Navigate to `/compliance/retention` — click Edit, modify days, see confirmation dialog, confirm; verify no API call on cancel; verify Edit disabled on held policy.

### Implementation for US3

- [x] T016 [US3] Add `hasPermission('adminapp.compliance.config')` RBAC gate to `RetentionPoliciesPage`; hide Edit buttons for users with only `adminapp.compliance.read` in `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`
- [x] T017 [US3] Add `pendingChange` state (`{ policy: RetentionPolicy; newDays: number; newAction: 'archive' | 'purge' } | null`); show confirmation dialog (role="dialog", aria-modal) with before/after summary when admin clicks "Update Policy" in `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`
- [x] T018 [US3] Move the API PATCH call into the confirmation handler; clicking "Cancel" in the dialog clears `pendingChange` and makes no API call in `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`
- [x] T019 [US3] Disable the Edit button for any policy row where `enforcement_hold_until` is non-null and in the future; show tooltip/note "Policy in grace period until {date}" in `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`
- [x] T020 [US3] Switch `fetchPolicies()` to use `new AdminAppClient(authenticatedClient).listRetentionPolicies()` and the submit handler to use `updateRetentionPolicy(editingId, payload)` in `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`
- [x] T021 [US3] Write tests for US3 ACs (RBAC gate, confirmation dialog shown on edit, no API call on cancel, hold-period disables Edit) in `wallet-ecosystem/apps/admin/__tests__/features/audit/p1016-audit-log-compliance.test.tsx`

**Checkpoint**: Retention policies page functional with confirmation workflow; all US3 tests pass.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T022 Run TypeScript check and fix any type errors: `npx tsc --noEmit` in `wallet-ecosystem/apps/admin/`
- [x] T023 Run full regression suite: `npm test` in `wallet-ecosystem/apps/admin/` — verify 0 new failures
- [x] T024 ARIA review: verify `role="alert"` on error states, `aria-label` on filter controls, `role="dialog"` + `aria-modal` on confirmation dialog, no missing `scope="col"` on table headers

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No external dependencies — start immediately
- **T001–T005**: Must complete before any story phase begins (type dependencies)
- **US1 (Phase 3)**: Depends on T001–T002 (audit types)
- **US2 (Phase 4)**: Depends on T003 (compliance types) — can run in parallel with US1
- **US3 (Phase 5)**: Depends on T004 (retention types) — can run in parallel with US1 and US2
- **Polish (Phase 6)**: Depends on all stories complete

### User Story Dependencies

- **US1**: After T001–T002 — no dependency on US2/US3
- **US2**: After T003 — no dependency on US1/US3
- **US3**: After T004–T005 — no dependency on US1/US2

---

## Implementation Strategy

### MVP First (US1 Only)

1. Complete Phase 1: T001–T005 (setup types)
2. Complete Phase 3: T006–T010 (US1 audit log)
3. **Validate**: All US1 tests pass, no edit/delete visible, RBAC gate works
4. Proceed to US2 and US3

### Incremental Delivery

1. T001–T005 → Foundation types ready
2. T006–T010 → Audit log enhanced (MVP!)
3. T011–T015 → Compliance dashboard live
4. T016–T021 → Retention policy editor secured
5. T022–T024 → TypeScript + regression + ARIA clean

---

## Notes

- No new pages or routes — all changes are enhancements to existing files
- `compliance-status.tsx` body is fully replaced (per-user lookup removed per research.md Decision 3)
- Immutability mandate: audit events must have zero edit/delete controls in ANY state
- Confirmation dialog uses inline state (no Dialog component dependency per research.md Decision 4)
- Tests file is created once (T010 creates it) and appended by T015 and T021
