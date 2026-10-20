# Feature Specification: ADM-P1-016 — Audit Log & Compliance

**Feature Branch**: `040-audit-log-compliance`

**Created**: 2026-10-14

**Status**: Draft

**Input**: ADM-P1-016 — Audit Log & Compliance [US-P1-016-001 Admin views immutable audit log, US-P1-016-002 Admin views compliance dashboard, US-P1-016-003 Admin configures data retention policies]

## User Scenarios & Testing *(mandatory)*

### User Story 1 - View Immutable Audit Log (Priority: P1)

An admin navigates to `/audit` to review a reverse-chronological list of all system audit events. They can filter by Event Type, Actor, Date range, and Entity type to narrow results. They can click any event to open a detail drawer showing full context including before/after state and integrity verification status. No edit or delete controls are ever shown on any audit event.

**Why this priority**: Regulatory compliance and security investigation depend on reliable, tamper-proof audit trails. This is the core compliance evidence surface.

**Independent Test**: Load the audit log page, apply filters, verify events render in reverse-chronological order, open a detail drawer — zero edit or delete controls visible at any point.

**Acceptance Scenarios**:

1. **Given** an admin with `adminapp.audit.read` permission visits `/audit`, **When** the page loads, **Then** audit events are displayed in reverse-chronological order with columns: Occurred At, Event Type, Actor, Affected Entity, IP Address, Correlation ID.
2. **Given** the audit log is displayed, **When** the admin applies an Event Type filter, Actor filter, Date Range filter, and/or Entity Type filter, **Then** only matching events are shown.
3. **Given** the admin clicks "View" on any event, **When** the detail drawer opens, **Then** it shows full event detail including before/after state, integrity verification badge, and outcome — with no edit or delete buttons anywhere.
4. **Given** the date range exceeds 90 days, **When** the admin clicks "Apply Filters", **Then** a validation error is shown and no API call is made.
5. **Given** an admin without `adminapp.audit.read` permission visits `/audit`, **When** the page loads, **Then** an access-denied message is shown and no audit data is fetched.

---

### User Story 2 - Compliance Dashboard (Priority: P2)

An admin navigates to `/compliance` to see a high-level compliance health dashboard showing: regulatory checklist status (KYC completion rates, AML screening coverage), open compliance items requiring attention, and upcoming data retention deadlines. Items are color-coded by urgency (green/amber/red).

**Why this priority**: Proactive compliance monitoring prevents regulatory breaches. Administrators need at-a-glance visibility without deep-diving individual user records.

**Independent Test**: Load the compliance dashboard, verify KPI checklist items render with color-coded status badges, verify open items list, verify retention deadlines section — all with urgency indicators.

**Acceptance Scenarios**:

1. **Given** an admin with `adminapp.compliance.read` permission visits `/compliance`, **When** the page loads, **Then** a regulatory checklist section shows items with green/amber/red status badges.
2. **Given** the dashboard is loaded, **When** there are open compliance items, **Then** they are listed with urgency color-coding (red = overdue, amber = due within 7 days, green = on track).
3. **Given** the dashboard is loaded, **When** there are retention policies with upcoming deadlines (within 30 days), **Then** a retention deadlines section shows them sorted by earliest deadline first.
4. **Given** an admin without `adminapp.compliance.read` permission visits `/compliance`, **When** the page loads, **Then** an access-denied message is shown.

---

### User Story 3 - Configure Data Retention Policies (Priority: P2)

An admin navigates to `/compliance/retention` to view and update data retention policies. When they edit a policy (retention period, post-retention action), the system requires an explicit confirmation step before saving. The change is logged in the audit trail. New policy settings take effect from the next scheduled cleanup run.

**Why this priority**: Data retention configuration is a high-impact, compliance-sensitive operation. Changes must be intentional, confirmed, and auditable per constitution Principle 3 and Principle 13.

**Independent Test**: Load retention policies list, edit a policy, see confirmation dialog, confirm — verify save succeeds and an audit entry records the change.

**Acceptance Scenarios**:

1. **Given** an admin with `adminapp.compliance.config` permission visits `/compliance/retention`, **When** the page loads, **Then** all configured retention policies are listed with: Data Category, Min Retention Days, Post-Retention Action, Effective From, Grace Period Until, Last Updated.
2. **Given** the admin clicks Edit on a policy and modifies values, **When** they click "Update Policy", **Then** a confirmation dialog appears summarizing the change before it is committed.
3. **Given** the confirmation dialog is shown, **When** the admin confirms, **Then** the policy is saved via PATCH, the audit log records the change (actor, before/after values), and a success message is shown.
4. **Given** the admin cancels the confirmation dialog, **When** the dialog closes, **Then** no changes are saved and no API call is made.
5. **Given** an admin without `adminapp.compliance.config` permission visits `/compliance/retention`, **When** the page loads, **Then** Edit buttons are hidden (read-only view).

---

### Edge Cases

- What happens when the audit log API returns no events for the applied filters? Empty state message with filter reset option.
- What happens when the compliance dashboard API is temporarily unavailable? Graceful error state with retry button.
- What happens when a retention policy minimum days is set below the regulatory minimum? Frontend validation prevents submission.
- What happens when the actor search field is cleared? Filter is removed and full event list reloads.
- What if the retention policy has `enforcement_hold_until` set? The policy row displays the grace period badge and Edit is disabled until the hold expires.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST display audit events in reverse-chronological order with columns: Occurred At, Event Type, Actor, Affected Entity, IP Address, Correlation ID.
- **FR-002**: System MUST provide filter controls for Event Type, Actor (text search), Date Range (from/to), and Entity Type on the audit log page.
- **FR-003**: System MUST enforce a maximum 90-day date range on the audit log filter with a client-side validation error.
- **FR-004**: System MUST provide a detail drawer for each audit event showing before/after state, metadata, integrity verification status, and outcome.
- **FR-005**: System MUST NOT render any edit or delete controls on audit events anywhere on the audit log page or detail drawer.
- **FR-006**: System MUST gate the audit log page behind `adminapp.audit.read` permission; show access-denied if missing.
- **FR-007**: System MUST support cursor-based pagination ("Load More") for the audit log list.
- **FR-008**: System MUST display a compliance dashboard with a regulatory checklist section showing items with color-coded urgency badges.
- **FR-009**: System MUST display open compliance items on the dashboard, color-coded by urgency: red (overdue), amber (due ≤ 7 days), green (on track).
- **FR-010**: System MUST display upcoming data retention deadlines (within 30 days) on the compliance dashboard, sorted by earliest first.
- **FR-011**: System MUST gate the compliance dashboard behind `adminapp.compliance.read` permission.
- **FR-012**: System MUST list all data retention policies on `/compliance/retention` with all relevant fields visible.
- **FR-013**: System MUST show a confirmation dialog when an admin submits a retention policy change, summarizing before and after values.
- **FR-014**: System MUST only save a retention policy change after explicit confirmation; cancellation must not trigger an API call.
- **FR-015**: System MUST hide Edit buttons on the retention policies page for admins without `adminapp.compliance.config` permission (read-only view).
- **FR-016**: System MUST disable Edit on any retention policy row where `enforcement_hold_until` is in the future.

### Key Entities

- **AuditEvent**: Append-only record of a system action. Fields: id, event_type, event_category, actor_id, actor_type, entity_type, entity_id, action, outcome, ip_address, correlation_id, occurred_at, before_state, after_state, metadata, integrity_verified.
- **ComplianceDashboard**: Aggregate view returned from the backend. Contains: regulatory_checklist (array of {label, status, urgency}), open_items (array of {id, description, due_date, urgency}), retention_deadlines (array of {policy_id, data_category, deadline_at, urgency}).
- **RetentionPolicy**: Per-category data lifecycle rule. Fields: id, data_category, minimum_retention_days, post_retention_action, effective_from, enforcement_hold_until, configured_by_actor_id, updated_at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Audit log page loads within 2 seconds for the default (unfiltered) view.
- **SC-002**: Zero edit or delete controls present on the audit log page at any point — verified by automated test.
- **SC-003**: All three pages pass RBAC gate tests: unauthorized users see access-denied, not data.
- **SC-004**: Retention policy confirmation dialog is shown for 100% of attempted saves — verified by automated test.
- **SC-005**: Compliance dashboard renders urgency color-coding correctly for all three urgency levels in tests.
- **SC-006**: TypeScript type-checks cleanly with zero errors across all modified files.

## Assumptions

- The backend E23 module exposes `/v1/admin/audit/events` (list with filters), `/v1/admin/audit/events/{id}` (detail), `/v1/admin/compliance/dashboard` (compliance dashboard aggregate), `/v1/admin/compliance/retention-policies` (list), and `/v1/admin/compliance/retention-policies/{id}` (PATCH update).
- `adminapp.audit.read`, `adminapp.compliance.read`, and `adminapp.compliance.config` permission slugs are already seeded by E04 (Roles & Permissions).
- The existing page shell routes (`/audit`, `/compliance`, `/compliance/retention`) are in place and import from `src/modules/audit/pages/`.
- The partial implementations in `audit-events.tsx`, `compliance-status.tsx`, and `retention-policies.tsx` are enhanced (not replaced) where existing functionality is correct.
- IP address field is present in audit events from the backend; if absent from a given event, the cell displays "—".
- The compliance dashboard endpoint returns a pre-computed aggregate — no client-side aggregation is required.
- Retention policy audit logging is handled server-side by the E23 backend; the frontend only triggers the PATCH call.
