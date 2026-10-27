# Verified callback receipt model

Owner: E25 `integration.webhook_events`. Existing receipt PK, retry count,
processing generation and status remain authoritative for transport delivery.

| Added field | Type | Meaning |
|---|---|---|
| verified_notification | nullable jsonb | Sanitized entity/ref/status/correlation plus trusted account/environment, claimed merchant and integer money |
| payload_hash | nullable char-compatible varchar(64) | SHA256 of exact verified raw bytes |
| encrypted_evidence | nullable text | Foundation-encrypted raw body, hidden from model/API serialization |

Account-scoped mock event identity is SHA256(account ID, environment, stable event
ID), unique across E25 aliases using a partial PostgreSQL unique index. Advisory
transaction lock covers the same identity. Conflicting bytes never replace an
existing receipt. Legacy nonpayment receipts retain their current storage format.

Payment receipts also insert an existing E25 `admin_retry_intents` delivery record
in the receipt transaction, closing commit-before-dispatch loss. This table's name
is historical; it now stores both initial verified-payment delivery and operator
retry intents. Existing scheduler/worker delivers them. Crash/retry retains the
same receipt ID. Generation guards prevent stale retries after quarantine.

Payments consumes notification state and returns a safe quarantine reason; E25
atomically commits receipt dead-letter status, attempt record and dead-letter
outbox event. Payments does not own the receipt table. Legacy parallel Payment
callback rows are retained; queued unverified jobs move to the existing
`dead_lettered` status with a quarantine reason, never silently deleted.

No balance, journal, entitlement, refund, settlement or payout state columns are
changed. PayoutOutcome is a separate adapter result enum, not a collection state.
