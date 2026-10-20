# Data model
Currency existing code/name/symbol/decimal_places/is_active; no balance edits. Precision immutable through existing API; status proposed through E28 financial_policy.multi_currency.currency.CODE.
FX rate existing18,8 rate,6,5 spread, effective dates, enumstatus, creator/approver/change request; add source manual/provider, provider nullable, observed_at timestamptz. Legacy source manual. Rate history preserves superseded numeric values; effective_to and status transition only.
Provider snapshot: rate decimal string, provider nonempty, observed_at UTC not future. Provider ingestion honors manual lock; provider revert snapshots an approved proposed rate.
Requests bind maker, idempotency key, exact proposed payload, currency/pair. E28 approved immutable proposed IDs required before activation; same-maker forbidden.
