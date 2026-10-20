# Data Model
Existing notification_templates: add language nullable string(35), revision integer default 1. Update by id/revision, subject/body only; optional language metadata validated.
Existing notifications: rule_channels nullable json; null preserves legacy and mandatory behavior, array snapshots optional enabled channels.
notification.admin_rules: template_id UUID + channel composite PK, enabled boolean, revision integer, timestamps; template FK.
notification.admin_actions: UUIDv7 id, actor UUID, action, resource UUID, correlation string, metadata JSON, timestamp. No rendered bodies/contact addresses.
notification.admin_outbox: UUIDv7 id, event_name, payload JSON, created_at, delivered_at nullable; pending index.
Existing deliveries: add enum Bounced without dropping existing outcomes; no new per-channel row on retry. Failed/permanently_failed -> queued; completed/queued/bounced rejected.
