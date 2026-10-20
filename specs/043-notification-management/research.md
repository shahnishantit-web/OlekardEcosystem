# Research
Research agent notification_research inspected existing pipeline.
- Decision: preserve template key and shared copy; nullable language metadata groups legacy records as Unspecified. Rationale: existing render(key, variables) has no locale/channel input. Alternative per-locale template replacement would break consumers.
- Decision: mandatory rows locked; optional rules snapshotted at creation. Rationale: E17 FR008 outranks illustrative suspicious-login toggle; return type remains NotificationModel. Alternative suppressing notification creation breaks consumers.
- Decision: reuse delivery rows/jobs and serialize all channel jobs with advisory locks and completed-state guards. Rationale: Failed also denotes automatic backoff; existing jobs lacked guards. Alternative creating new delivery rows violates unique constraint.
- Decision: durable action/outbox in notification connection. Rationale: same transaction as state, reliable re-dispatch. Consumers must deduplicate event id; external provider exactly-once is not asserted.
- Blockers: no source blocker; real providers remain deployment dependency. No default language or template copy invented.
