# Data model
Existing risk_alerts/events/evaluations/decisions/account_holds remain authoritative.
Add alert_notes (UUIDv7, alert FK, actor, text 1–4000, UTC).
Add alert_links (alert/related FK composite key, actor, UTC; no self/duplicate links).
Add alert_freeze_requests (UUIDv7, alert FK, maker, reason 10–2000, checker nullable, pending/approved, hold nullable, UTC).
Add durable risk investigation event delivery if existing publisher cannot share transaction.
Findings status adds acknowledged/in_remediation preserving existing open/suppressed/resolved. Existing finding_ref yields nullable CVE only on actual CVE match.
Mutations lock alert and verify supplied version; approval locks request and derives subject/checker.

## Implementation refinements
- risk.investigation_actions: append-only domain audit rows (UUIDv7, alert FK, actor UUID, action, correlation ID, metadata JSONB, UTC created_at); committed with privileged state and mirrored to the existing central audit service.
- risk.investigation_outbox: event ID/name/payload and created_at/delivered_at, owned by Risk so account hold plus event insertion share one transaction. Scheduled receiver delivery uses the existing Notification module with per-event deduplication.
- Wallet and user alert subjects resolve to customer/agent account owners through WalletModuleContract/IdentityModuleContract; no wallet schema writes or balance mutation.
- Score timeline is the latest 100 subject evaluations in chronological order, including historical evaluations not attached to an alert event. Entity risk history is bounded to the latest 100 events.
- Findings constraint rollback refuses to discard active additive lifecycle states. Local migrations are additive.
