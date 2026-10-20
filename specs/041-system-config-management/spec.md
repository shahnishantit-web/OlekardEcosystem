# Feature Specification: System Configuration Management

**Feature Branch**: `041-system-config-management`

**Created**: 2026-10-14

**Status**: Draft

**Input**: ADM-P1-017 — Admin edits configuration values, approves pending config changes, and views configuration version history.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Admin Edits a Configuration Value (Priority: P1)

An authorised admin navigates to a configuration key's detail page, enters a new value, previews the before/after diff, and submits the change. For keys classified as "critical", the system creates a change request in Pending Approval status rather than applying the value immediately. For non-critical keys the value is applied at once and an audit entry is recorded.

**Why this priority**: Configuration values control transaction limits, KYC rules, fraud thresholds, and fees. Admins must be able to update them safely. This is the primary write path for configuration management.

**Independent Test**: Render the key detail page with an authenticated maker, fill the edit form, confirm diff preview appears, submit — assert that a change request is created (critical) or the key is updated directly (non-critical).

**Acceptance Scenarios**:

1. **Given** I have `adminapp.config.write` permission and navigate to `/configuration/{domain}/{key}`, **When** I enter a new value and click "Submit Change", **Then** I see a diff preview panel showing the current value vs. the proposed value before I confirm.
2. **Given** the config key is critical and I confirm the diff, **When** the form is submitted, **Then** a change request in "Pending Approval" status is created and I am shown a success message with a link to the pending approval.
3. **Given** the config key is non-critical and I confirm the diff, **When** the form is submitted, **Then** the key is updated immediately and an audit entry is recorded.
4. **Given** I do not have `adminapp.config.write` permission, **When** I visit the key detail page, **Then** the edit form is hidden (read-only view only).
5. **Given** the key already has a pending change request, **When** I view the key detail page, **Then** the edit form is disabled with an explanation that a change is already pending approval.

---

### User Story 2 — Admin Approves a Pending Config Change (Priority: P1)

A second admin (the checker) navigates to the pending approvals list, selects a pending change request, reviews the before/after diff, and approves or rejects it. The system enforces maker/checker separation: the admin who submitted the change cannot approve it. On approval the backend applies the new value and fires `CONFIG_UPDATED` with both actors recorded in the audit log. On rejection a mandatory reason is required.

**Why this priority**: Maker/checker control on critical configuration changes is a financial-integrity and compliance requirement (Constitution Principle 4 and 13). Without it a single admin could unilaterally lower fraud thresholds or increase transaction limits.

**Independent Test**: Render the approval detail page as a checker (different user than the maker), assert Approve and Reject buttons are enabled; then render as the maker, assert Approve is disabled with an explanatory message.

**Acceptance Scenarios**:

1. **Given** I have `adminapp.config.approve` permission and I am not the maker of the pending change, **When** I navigate to `/configuration/approvals/{id}`, **Then** I see the before/after diff and the Approve, Reject, and Withdraw buttons.
2. **Given** I am the maker of the pending change, **When** I view the approval detail, **Then** the Approve button is disabled and a "You cannot approve your own change" notice is shown.
3. **Given** I click Approve, **When** the action completes, **Then** the backend applies the new value, fires `CONFIG_UPDATED`, records both maker and checker in the audit log, and I am redirected to the approvals list.
4. **Given** I click Reject, **When** I submit a rejection reason, **Then** the change request is rejected, the reason is stored, and I am redirected to the approvals list.
5. **Given** I do not have `adminapp.config.approve` permission, **When** I visit the approvals list, **Then** I see an access-denied message.

---

### User Story 3 — Admin Views Configuration Version History (Priority: P2)

An admin views the complete history of value changes for a configuration key. Each history entry shows: the event type, the value at that point, who changed it (actor), who approved it (approver), when it occurred, and the effective-from date. Entries are read-only; no editing is possible from the history view.

**Why this priority**: Auditability of configuration changes is required by Principles 13 (observability) and 15 (release governance). The version history allows compliance review and incident root-cause analysis.

**Independent Test**: Render the key detail page with a mocked list of version items including `approved_by` and `effective_from` fields; assert all columns are visible with correct values.

**Acceptance Scenarios**:

1. **Given** I navigate to `/configuration/{domain}/{key}`, **When** the version history section loads, **Then** I see a table with columns: Event, Value, Changed By, Approved By, Occurred At, Effective From.
2. **Given** a version entry was submitted by one admin and approved by another, **When** I view that row, **Then** "Changed By" shows the maker and "Approved By" shows the checker.
3. **Given** a version entry for a non-critical key has no approval actor, **When** I view that row, **Then** "Approved By" shows "—" (system/direct update).
4. **Given** I do not have `adminapp.config.read` permission, **When** I visit any configuration page, **Then** I see an access-denied message and no data is loaded.

---

### Edge Cases

- What happens when a config key already has a pending change request and an admin tries to submit another? The edit form is disabled with a "Pending change exists" notice.
- What if the approval API call fails? The action buttons return to enabled state and an error message is shown.
- What if `effective_from` is null? Display "—" in the version history table.
- What if the value type is boolean or numeric? The edit form must accept and validate accordingly; the diff preview must render JSON-serialised values.
- What if the logged-in user's ID cannot be determined when viewing an approval? Treat as checker (allow Approve) — the backend enforces maker/checker server-side.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Users with `adminapp.config.read` MUST be able to view configuration key details and version history; users without this permission MUST see an access-denied message.
- **FR-002**: Users with `adminapp.config.write` MUST be able to submit a change request for any configuration key.
- **FR-003**: Before submitting, the system MUST display a diff preview panel showing the current value alongside the proposed value.
- **FR-004**: For keys marked critical, submitting MUST create a pending change request; for non-critical keys, submitting MUST update the value immediately and record an audit entry.
- **FR-005**: If a key already has a pending change request, the edit form MUST be disabled with an explanatory notice; a link to the existing pending request MUST be shown.
- **FR-006**: Users with `adminapp.config.approve` MUST be able to approve or reject pending change requests they did not submit.
- **FR-007**: The system MUST prevent the maker of a change request from approving their own submission; the Approve button MUST be disabled with a "You cannot approve your own change" notice when the logged-in user matches the maker.
- **FR-008**: Rejecting a change request MUST require a non-empty rejection reason entered via a confirmation dialog.
- **FR-009**: The version history table for a configuration key MUST include the columns: Event Type, Value, Changed By, Approved By, Occurred At, Effective From.
- **FR-010**: All configuration pages MUST enforce RBAC gates client-side and redirect/show access-denied when permissions are absent.
- **FR-011**: The `ConfigurationKeyItem` type MUST include a `has_pending_change` boolean field so the list page can display the pending badge without an unsafe type cast.
- **FR-012**: The `ConfigurationVersionItem` type MUST include `approved_by: string | null` and `effective_from: string | null` fields.

### Key Entities

- **Configuration Key**: A named, domain-grouped setting (financial_policy, operational_policy, geographic) with a current value, value type, description, and criticality flag.
- **Change Request**: A proposed update to a configuration key, with a maker, status (pending_approval/approved/rejected/withdrawn/failed), proposed value, previous value, and optional rejection reason.
- **Version Item**: An immutable history record for a configuration key capturing the event type, value at that point, actor (changed-by), approver (approved-by), occurrence timestamp, and effective-from date.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorised admin can submit a config change (diff preview → confirm → submit) in under 3 steps.
- **SC-002**: The maker/checker constraint is enforced client-side: Approve is disabled (not merely hidden) when the viewer is the maker.
- **SC-003**: The version history table renders all 6 required columns with correct data for both critical (approved_by populated) and non-critical (approved_by = "—") entries.
- **SC-004**: All three pages (config list, key detail, approval detail) show an access-denied state when required permissions are absent — no data leaks to unauthorised users.
- **SC-005**: Zero TypeScript errors after type additions (`has_pending_change`, `approved_by`, `effective_from`).
- **SC-006**: Test suite covers all 5 US1 ACs, 5 US2 ACs, and 4 US3 ACs with at least 22 passing tests.

## Assumptions

- The backend API (E28) already enforces maker/checker at the server side; this spec covers client-side UX enforcement only.
- The `value_type` field on `ConfigurationKeyItem` (string, number, boolean, json) is used to render appropriate form inputs; the spec does not define a schema editor for arbitrary JSON structures.
- "Critical" vs. "non-critical" classification is determined by the backend field `requires_approval: boolean` on `ConfigurationKeyDetail`; the frontend reads this flag to decide between immediate-update and change-request flows.
- The existing `ConfigurationApiClient` in `lib/api/configuration.ts` already has all required API methods; no new API endpoints are added in this epic.
- RBAC permission slugs (`adminapp.config.read`, `adminapp.config.write`, `adminapp.config.approve`) are added to `ALL_PERMISSION_SLUGS` in `admin-app-client.ts`.
- The `security-config.tsx` page (separate security configuration surface) is out of scope for this epic.
