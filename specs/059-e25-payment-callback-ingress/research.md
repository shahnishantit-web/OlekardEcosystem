# P10 decisions and pending items

Source path/version/hash are in spec.md. FD06 and LC05 explicitly give E25 raw
ingress, trusted receipt and async delivery ownership. Payments owns validation and
state transitions. The legacy controller previously persisted unverified payloads
in a parallel store; route it through E25 instead. Keep old rows for audit, never
blindly replay them. Reject unmapped legacy requests instead of guessing account.

Only the explicitly permitted development mock has a known protocol. Its new
account parser uses HMAC SHA256 over exact bytes, a stable event_id, transaction_ref,
merchant_account, integer amount and uppercase currency. This is TEST ONLY, not a
real bank contract. Existing direct mock parser remains compatible but guarded.

Pending: P05/P06/P07 failing predecessor tests; PostgreSQL concurrency/recovery
proof; real provider verification/idempotency/query contracts; security replay and
rotation windows; approved evidence retention; certification and operating
permission. Owners: backend reliability engineer, integration engineer, Security,
Finance and provider owner respectively. None inferred from checkmarks.
