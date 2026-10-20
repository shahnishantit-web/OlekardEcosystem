# Additive model
support.admin_cases: case_id UUID primary, escalated_at/deadline/reason/senior_id nullable; original entities remain authoritative.
support.admin_case_entries: UUIDv7 ID, case_id, actor_id, kind, JSON payload, correlation_id, request_key/hash nullable, UTC created_at; unique case/request key. Notes/links/attachment metadata/action audit, no secrets.
Existing ticket notes and dispute evidence remain readable; private attachment file keys are never serialized. Refund projection references parent original transaction and posted journal. No historical journal changes.
