# Feature Specification: ADM-P1-019 Notification Management
**Created**: 2026-10-15
**Status**: Approved-source implementation scope
**Input**: Implement the three mandatory stories in docs/admin-p1p2-epic-catalogue.md, ADM-P1-019. Execution with Codex + Spec Kit authorized after Claude quota failure.

## User Scenarios & Testing
### User Story 1 — US-P1-019-001 Templates (P1)
An authorized Admin views templates grouped by event, channel and language, edits subject/body and previews sample substitutions.
Why: centrally managed communication copy.
Independent test: load templates, edit copy, preview and verify subsequent rendering.
Acceptance scenarios:
- AC1: templates show event type, email/SMS/push channel and language grouping.
- AC2: selecting a template permits subject/body editing and persists changes.
- AC3: preview substitutes supplied sample variables safely.
### User Story 2 — US-P1-019-002 Delivery log (P1)
An authorized Admin sees recent recipient/channel/event/status/timestamp records, filters them and retries failures.
Why: operational recovery and delivery visibility.
Independent test: seed deliveries with differing channels/status/dates; filter and retry a failed delivery.
Acceptance scenarios:
- AC4: recent paginated delivery rows expose recipient, channel, event, Sent/Failed/Bounced and timestamp.
- AC5: channel, status and inclusive date filters combine.
- AC6: failed deliveries may be retried; successful/queued deliveries cannot.
### User Story 3 — US-P1-019-003 Rules (P1)
An authorized Admin toggles optional event/channel rules; mandatory notifications remain protected.
Why: centrally configurable event delivery.
Independent test: toggle optional channel, observe durable NOTIFICATION_RULE_UPDATED and compare new versus already-created notification channel selection.
Acceptance scenarios:
- AC7: rule state is saved and NOTIFICATION_RULE_UPDATED is reliably emitted after durable save.
- AC8: changes affect new events immediately, preserving queued events and mandatory notification policies.

### Edge Cases
Unauthorized access; missing rows; stale template edits; unknown variables; literal markup; duplicate retry requests; queue outage; concurrent retries; permanently failed delivery; bounced provider outcome; unset language metadata; no templates; mandatory rule disable.

## Requirements
- FR-001: authorized Admins MUST list/edit templates and preview sample substitutions (AC1–3).
- FR-002: authorized Admins MUST inspect/filter paginated deliveries and retry only failures (AC4–6).
- FR-003: authorized Admins MUST persist optional channel rules, reliably publish the named update event, and apply changes to new events (AC7–8).
- FR-004: authentication, MFA, server-side RBAC, mandatory notification policies, existing APIs and consent MUST remain intact.
- FR-005: privileged changes MUST be audited without logging rendered messages, sample secrets or recipient contact details.
- FR-006: the surface MUST provide loading, empty, error and keyboard-accessible controls.
- FR-007: an authorized Admin MAY send a test using sample variables to their own account only; no arbitrary-recipient broadcast capability.

## Key Entities
Template: existing event key, subject/body, channels, variable schema; optional language metadata.
Delivery: existing recipient notification and per-channel outcome/attempt timestamps.
Rule: template/channel enabled state.
Action/outbox: durable actor/correlation metadata and reliable update/retry dispatch.

## Success Criteria
- SC-001: all eight acceptance groups pass reproducible checks.
- SC-002: forbidden mutations and mandatory-rule disable attempts have zero successful outcomes.
- SC-003: existing notification and Admin authentication regression suites pass or any failures are explicitly reported.

## Dependencies and Clarifications
E17 notification storage/rendering/jobs; ADM-FOUND-001 auth/layout/design system; existing Identity permissions and Platform audit.
Language is nullable metadata, displayed as Unspecified for legacy rows; no unsupported locale routing or invented locale default.
Existing templates define shared content across supported channels; editing that template updates its shared content, explicitly described in UI.
E17 FR-E17-008 mandates financial/security delivery on available channels; those rules cannot be disabled, including suspicious-login security alerts.
Optional rule snapshot is taken at notification creation; absent rules preserve existing enabled behavior. User consent remains checked by the existing pipeline.
Bounced is supported as a stored outcome; external provider bounce webhook integration is not part of this epic.
Real email/SMS/push adapters currently log; provider credential provisioning is an existing deployment dependency, not a claimed delivery result.
No unresolved product clarification is needed for the bounded scope.
