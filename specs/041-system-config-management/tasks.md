# Tasks: System Configuration Management (ADM-P1-017)

**Input**: Design documents from `specs/041-system-config-management/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓

**Organization**: Tasks grouped by user story. Phase 1 (foundational type/permission changes) blocks all user stories.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Foundational — Type & Permission Additions

**Purpose**: Type fixes and new permission slugs that all three user stories depend on. Must be complete before any page work.

**⚠️ CRITICAL**: These changes block all user story phases.

- [x] T001 Add `has_pending_change: boolean` field to `ConfigurationKeyItem` interface in `wallet-ecosystem/apps/admin/lib/api/configuration.ts`
- [x] T002 Add `requires_approval: boolean` field to `ConfigurationKeyDetail` interface in `wallet-ecosystem/apps/admin/lib/api/configuration.ts`
- [x] T003 Add `approved_by: string | null` and `effective_from: string | null` fields to `ConfigurationVersionItem` interface in `wallet-ecosystem/apps/admin/lib/api/configuration.ts`
- [x] T004 [P] Add `adminapp.config.read`, `adminapp.config.write`, and `adminapp.config.approve` entries to `ALL_PERMISSION_SLUGS` in `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`

**Checkpoint**: Type additions done — page implementation can begin in parallel.

---

## Phase 2: User Story 1 — Admin Edits a Configuration Value (Priority: P1) 🎯 MVP

**Goal**: Config list page has RBAC gate; key detail page has RBAC gate, edit form with diff preview, submit-to-change-request flow, and pending-change guard.

**Independent Test**: Render `ConfigurationKeyDetailPage` with `adminapp.config.write` permission, enter a new value, assert diff preview panel appears, click Confirm — assert `submitChangeRequest` called with correct payload.

- [x] T005 [P] [US1] Add `adminapp.config.read` RBAC gate (access-denied card when missing) to `ConfigurationListPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-list.tsx`
- [x] T006 [US1] Add `adminapp.config.read` RBAC gate (access-denied card when missing) to `ConfigurationKeyDetailPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T007 [US1] Add edit form (textarea for proposed value labelled "Proposed Value", "Preview Change" button) to `ConfigurationKeyDetailPage` — only visible when `hasPermission('adminapp.config.write')` is true in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T008 [US1] Add diff preview panel (side-by-side before/after `<pre>` blocks, shown when `showDiff === true`) with "Confirm Submit" and "Cancel Preview" buttons to `ConfigurationKeyDetailPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T009 [US1] Wire "Confirm Submit" to `ConfigurationApiClient.submitChangeRequest()` with `generateIdempotencyKey()` and show inline success banner (with link to approval if `status === 'pending_approval'`) in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T010 [US1] Disable edit form (textarea and "Preview Change" button) and show "A change is pending approval" notice with link to approval when `keyDetail.pending_change_request_id !== null` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T011 [P] [US1] Remove the unsafe type cast `(key as ConfigurationKeyItem & { has_pending_change?: boolean })` from `ConfigurationListPage` now that `has_pending_change` is properly typed in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-list.tsx`

**Checkpoint**: US1 fully functional — config list gated, key detail edit/submit/pending-guard all working.

---

## Phase 3: User Story 2 — Admin Approves a Pending Config Change (Priority: P1)

**Goal**: Approvals list page has RBAC gate; approval detail page has RBAC gate and maker/checker guard (Approve disabled + notice when viewer is the maker; Reject also disabled for maker).

**Independent Test**: Render `ConfigurationApprovalDetailPage` with `user.id === request.maker_id` — assert Approve and Reject are disabled and the notice is visible; then render with different `user.id` — assert both are enabled.

- [x] T012 [US2] Add `adminapp.config.approve` RBAC gate (access-denied card when missing) to `ConfigurationApprovalsPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-approvals.tsx`
- [x] T013 [US2] Add `adminapp.config.approve` RBAC gate (access-denied card when missing) to `ConfigurationApprovalDetailPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-approval-detail.tsx`
- [x] T014 [US2] Destructure `user` from `useAuth()` and compute `isOwnChange = user?.id === request?.maker_id` in `ConfigurationApprovalDetailPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-approval-detail.tsx`
- [x] T015 [US2] Disable the Approve button and show `<p role="alert">You cannot approve your own change request.</p>` notice when `isOwnChange === true` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-approval-detail.tsx`
- [x] T016 [US2] Disable the Reject button (but keep Withdraw enabled) when `isOwnChange === true` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-approval-detail.tsx`

**Checkpoint**: US2 fully functional — approvals list gated, maker cannot approve/reject own change.

---

## Phase 4: User Story 3 — Admin Views Configuration Version History (Priority: P2)

**Goal**: Version history table shows 6 columns including `Approved By` (truncated UUID or `—`) and `Effective From` (formatted date or `—`).

**Independent Test**: Render `ConfigurationKeyDetailPage` with mocked version items including `approved_by` and `effective_from` fields; assert both columns appear with correct values.

- [x] T017 [US3] Add `Approved By` and `Effective From` column headers to the version history table in `ConfigurationKeyDetailPage` in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T018 [US3] Render `v.approved_by ? v.approved_by.slice(0, 8) + '…' : '—'` in the `Approved By` cell of each version history row in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`
- [x] T019 [US3] Render `v.effective_from ? new Date(v.effective_from).toLocaleDateString() : '—'` in the `Effective From` cell of each version history row in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/configuration-key-detail.tsx`

**Checkpoint**: US3 fully functional — version history shows all 6 required columns.

---

## Phase 5: Tests

**Purpose**: Regression-verified test coverage for all 3 user stories in one test file.

- [x] T020 [US1] Write 9 US1 tests in `wallet-ecosystem/apps/admin/__tests__/features/configuration/p1017-system-config-management.test.tsx`: (a) access-denied when no config.read, (b) config list renders keys table when authed, (c) edit form hidden without config.write, (d) edit form visible with config.write, (e) diff preview appears after "Preview Change", (f) Confirm Submit calls submitChangeRequest, (g) pending change disables edit form with notice, (h) success banner shown after submit (pending_approval status), (i) list page has_pending_change badge renders
- [x] T021 [US2] Write 8 US2 tests in `wallet-ecosystem/apps/admin/__tests__/features/configuration/p1017-system-config-management.test.tsx`: (a) approvals list access-denied without config.approve, (b) approvals list renders rows, (c) approval detail access-denied without config.approve, (d) approval detail renders diff, (e) Approve disabled when viewer is maker + notice shown, (f) Approve enabled when viewer is not maker, (g) Reject disabled when viewer is maker, (h) reject dialog requires non-empty reason
- [x] T022 [US3] Write 5 US3 tests in `wallet-ecosystem/apps/admin/__tests__/features/configuration/p1017-system-config-management.test.tsx`: (a) version history table has 6 column headers, (b) approved_by truncated UUID shown for entries with approver, (c) approved_by shows '—' for entries without approver, (d) effective_from formatted date shown, (e) effective_from shows '—' when null

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T023 Run TypeScript check `npx tsc --noEmit` in `wallet-ecosystem/apps/admin/` and fix all errors
- [x] T024 Run full test suite `npx jest --no-coverage` in `wallet-ecosystem/apps/admin/` and confirm 0 new regressions

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Foundational)**: No dependencies — start immediately
- **Phase 2 (US1)**: Depends on Phase 1 (types must exist before page code compiles)
- **Phase 3 (US2)**: Depends on Phase 1; independent of Phase 2 (different pages)
- **Phase 4 (US3)**: Depends on Phase 1 and Phase 2 (US3 enhances the key-detail page from US1)
- **Phase 5 (Tests)**: Depends on Phases 2–4 complete (all pages modified before tests written)
- **Phase 6 (Polish)**: Depends on Phase 5

### User Story Dependencies

- **US1 (P1)**: After Phase 1 — no US dependency
- **US2 (P1)**: After Phase 1 — no US dependency (different files from US1)
- **US3 (P2)**: After US1 Phase 2 complete (modifies same `configuration-key-detail.tsx`)

### Parallel Opportunities

- T004 (permission slugs in admin-app-client.ts) parallelises with T001-T003 (configuration.ts changes)
- T005 (config-list RBAC) parallelises with T006-T010 (key-detail changes) once T001-T004 done
- T011 (remove unsafe cast in config-list) parallelises with T006-T010
- T012-T016 (US2 pages) parallelise with T005-T011 (US1 pages) — different files

---

## Implementation Strategy

### MVP First (US1 Only)

1. Complete Phase 1 (types + permissions)
2. Complete Phase 2 (US1 edit flow on key-detail)
3. Validate: render key-detail page, enter a value, confirm diff preview, confirm submit calls API
4. **STOP and VALIDATE** before proceeding to US2/US3

### Incremental Delivery

1. Phase 1 → Type safety restored
2. Phase 2 → Admins can propose config changes via UI
3. Phase 3 → Maker/checker enforced in approval UI
4. Phase 4 → Full version history audit trail visible
5. Phase 5+6 → Tests + regression clean

---

## Notes

- Use `STABLE_CLIENT = {} as never` + `(useAuth as jest.Mock).mockReturnValue(...)` pattern from E40 for all tests
- `configuration-approval-detail.tsx` already has `approveChangeRequest`, `rejectChangeRequest`, `withdrawChangeRequest` — only add `user` destructure and `isOwnChange` logic
- `configuration-key-detail.tsx` already renders version history — only add the two new columns and the edit form
- `configuration-list.tsx` already has the unsafe cast — T011 cleans this up after T001 adds the type
- Security: idempotency keys required for all write operations — `generateIdempotencyKey()` already imported in approval-detail; add to key-detail for submit
