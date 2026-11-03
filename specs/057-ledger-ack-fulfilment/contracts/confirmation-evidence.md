# P08 confirmation evidence and fulfilment acknowledgement contract

**Status:** Implemented for development; schema integration verification and independent review pending. This is not an AD-08/AD-09 accounting approval.

## Confirmation evidence

`PaymentInternalConfirmationContract::confirmationEvidence(ServiceActor, paymentIntentId, organisationId, purposeType, purposeReference)` returns `PaymentConfirmationEvidence|null`.

| Field | Authoritative source and rule |
|---|---|
| Intent, organisation, purpose/reference, payer, expected amount/currency | Immutable `payment.payment_intents`; query must match every requested scope exactly. |
| Attempt and financial success | Succeeded `payment.payment_attempts` row belonging to the intent. An unknown/timed-out attempt is not evidence. |
| Captured amount/currency | Nullable `payment_attempts.captured_amount_minor` and `captured_currency_code`, populated only from the E25 verified normalized callback context. The same row retains `capture_evidence_reference` (webhook receipt ID). Missing facts stay null; expected amount is never copied. Exact amount/currency disagreement suppresses confirmation. |
| Ledger journal | Ledger `findPostedJournalEvidence('payment:{intentId}:ack')`: journal ID, posted state/time, entry type, transaction reference, idempotency key, currency and individual immutable account lines. Payment also stores the returned journal ID and debit/credit account references on the attempt. |
| Accounting correspondence | Exactly one debit and one credit line, each independently equal to the captured amount and intent currency, using the exact debit/credit accounts used to post. Payment never sums every debit and credit line to infer payment amount. |
| Accounting state | `payment_intents.accounting_status = ledger_posted`, committed only with the persisted journal reference and the Payment outbox event after Ledger verification. |

Only service ID `events`, actor type `SERVICE`, with exact scope `payment.confirmation.read:{organisationId}:{purposeType}` may query. This call accepts an Identity-resolved `ServiceActor`, not a caller-supplied customer actor. P25 purpose is exactly `paid_event_ticket`. Public payer-scoped status API remains unchanged. No Events resolver or placeholder order model is registered.

External/bank attempts without approved accounting mappings remain `pending_ledger`; they cannot yield evidence. The installed provider callback parser is explicitly development/mock-only. No live provider contract or certification is implied.

## Retry and commit boundary

1. E25 verifies raw body and stores a durable verified receipt; its normalized money values are the only current source for captured amount/currency.
2. Payment records success and capture facts. A missing/mismatched fact does not produce an eligible confirmation.
3. Payment posts Ledger with stable key `payment:{intentId}:ack`.
4. Payment queries Ledger by that same key and validates posted status and exact line correspondence.
5. Payment then commits attempt journal/account refs, `ledger_posted`, and its outbox row in one Payment transaction.

If the process crashes after step 3 and before step 5, accounting repair repeats the same Ledger key, recovers the same journal ID, verifies it, and completes step 5. Payment and Ledger are separate connections; this is idempotent recovery, not a distributed transaction.

## Fulfilment acknowledgement

`PaymentInternalConfirmationContract::acknowledgeFulfilment(...)` requires the `events` ServiceActor and exact scope `payment.fulfilment.ack:{organisationId}:{purposeType}`, plus exact intent/org/purpose/reference and a durable domain `fulfilmentReference` with stable `idempotencyKey`.

Payment stores one unique acknowledgement row per intent, including consumer service and scope, while transitioning `fulfilment_status` only `pending -> fulfilled` in the same Payment transaction. Exact retries are no-ops; conflicting references/keys and terminal-state changes fail. Events must call only after its durable fulfilment transaction commits. P08 does not verify a future Events transaction, implement an Events inbox, or create ticket entitlements.

## Open requirements

- Finance must approve/verify AD-08 wallet credit-account mapping and separately supply AD-09 external settlement-receivable debit, credit account, currency/classification, custody/entity meaning and effective written approval. Source document v1.1 provides none of these.
- **Wallet committed-capture contract proposal (not approved or implemented):** current Wallet schema has currency pockets and `wallet_holds` in `active`, `released`, or `expired` states. Its module contract supports `createHold`/`releaseHold` and exposes pocket ledger account references, but no capture/commit operation, captured state, transaction record, or query binding a hold to a posted debit journal. Wallet should own a typed lookup keyed by the authoritative hold/operation reference that returns evidence only after a committed debit: hold and transaction reference, wallet owner, exact amount/currency from Wallet records, posted journal ID/status, debit account reference, and stable posting idempotency key. It must return null for active/released/unknown holds and never infer capture from held or expected amount. A Wallet-owned commit operation and its destination/account semantics require approved Wallet/Finance contracts before implementation; Payments must consume the evidence without posting a duplicate wallet debit. This proposal does not resolve AD-08.
- Payments/Identity Security review must approve the service identity issuance, exact scopes, tenant isolation and caller integration. Passing development tests is not independent review.
- Schema-correct isolated PostgreSQL migration, acknowledgement, Ledger posting and P11 replay tests have now run. P08 Payment integration crash windows remain unverified because no supported Wallet committed-capture evidence exists and AD-08 is unresolved. No development database schema or migration history was changed.
