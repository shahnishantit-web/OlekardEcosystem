# P10 flows and operator runbook

## LC01 adapter onboarding and configuration

Trigger: operator proposes a payment account or adapter change. Prerequisites:
reviewed provider contract, ownership/currency/merchant mapping, technical and
Finance owners, P05 permission/configuration controls. Foundation/E25 owns
connector, secret and ingress lifecycle; Payments owns capabilities/eligibility;
admin proposes/reviews configuration; mobile only displays eligible options.

States: draft → documentation review → implementation → sandbox verification →
approval pending → production verification → active → suspended/retired. Success
requires versioned configuration, evidence and independent approvals. Unknown or
unsupported capability fails explicitly; incoming payments never imply payouts.
Missing bank documentation blocks bank onboarding, not independent framework work.

The implemented TEST ONLY parser requires an E25 Payment/Bank connector with
configuration `payment_provider_account_id`, `payment_provider_code`,
`environment` exactly matching the server-side Payment account/provider. The
account `callback_secret_reference` names a foundation EnvSecretProvider key;
provision its value outside DB/config API. This does not introduce a real bank
secret scheme. Use a distinct connector per account/environment. Inactive
connectors resolve only with explicit `allow_resolution_when_inactive: true`;
suspended/disabled Payment providers can resolve existing attempts. Payment
callback mapping is read fresh, avoiding stale ownership/secret configuration.

Never activate a mock in staging/production. Constructor, per-operation guard,
callback parser, provider activation and checkout discovery reject/hide it.
Review real bank endpoint allowlists, TLS/mTLS/signature/replay rules, key rotation,
idempotency/query finality, refund/payout capabilities, account ownership and
reconciliation specimens before installing a separate adapter. Obtain real sandbox
evidence, Security/Finance approval, provider certification/operating permission
and explicit release authorization separately. None exists by implication here.

Completion evidence: account/configuration/adapter versions, traceable tests,
owners and activation audit. This package has development framework evidence;
real-provider completion is BLOCKED. Retention/rotation windows remain pending
Security policy, not invented defaults.

## LC03 initiation query refund and optional payout

Trigger: Payments has an eligible immutable quote and durable attempt/operation
reference. Prerequisites: verified P05/P06/P07 contracts and supported adapter.
Admin enables approved options; mobile submits selection and displays backend
status; foundation supplies authentication/transport; Payments supplies stored
money/context; Wallet and ledger alone own balances/postings.

States: created → submitting → requires action/processing → verified success;
ambiguity → unknown. Accounting, fulfilment, refund, settlement and payout states
remain independent. Initiate/query/refund have separate interfaces; payout is
optional and has its own result enum. Existing composite adapter remains valid.
No real bank transport/endpoints/credentials are implemented. Existing shared
provider interfaces are reused; an actual reusable E25 HTTP transport remains a
prerequisite gap for bank execution.

Development controls reuse `payment.mock_bank.default_outcome`:
`success`, `failed`, `pending`, `unknown`, `timeout`; timeout maps to UNKNOWN.
`payment.mock_bank.auto_confirm_seconds` retains the existing delay simulation
(zero disables it). Only explicit development use is permitted; test-only badge,
redirect metadata and `[MOCK]` logs distinguish simulated outcomes. No provider
certification follows from these controls. Do not use a success URL or ACK as
proof of capture. Unknown outcome retains the original operation and requires
query/reconciliation; do not create another charge.

Completion evidence: adapter result and original operation identity, verified
money/account, ledger acknowledgement and separate fulfilment evidence. Financial
end-to-end completion is BLOCKED by predecessor failures and pending migrations.

## LC05 receive verify persist and deliver

Trigger: provider submits raw bytes to `POST /v1/webhooks/{slug}`. Prerequisites:
trusted connector/account/secret mapping and reviewed parser. The TEST ONLY
protocol is documented in contracts/ingress.md; no bank protocol is guessed.

States: received → authenticated → durable receipt → asynchronous delivery →
semantic validation → processed, or failed/retry/dead-letter quarantine. E25
verifies exact body before parsing; signature failure returns 401 without a
receipt or money mutation. Storage failure returns 5xx before ACK. Canonical
E25 ACK is 200; legacy Payment URL returns 202 and requires one unambiguous E25
mapping. Old raw payloads with no trusted mapping are not automatically replayed.
This is a deliberate fail-closed migration requirement, not a compatibility alias
that guesses a tenant. Preserve original rows and arrange verified re-delivery.

Receipt, encrypted evidence, digest, sanitized notification, outbox and durable
delivery intent are committed before ACK. Identical redelivery returns the same
receipt even through an alias; conflicting content for one event ID returns 409.
At-least-once dispatch is expected. Payments checks stored attempt/account,
merchant, provider/environment, reference and integer money before calling the
existing outcome processor. Amount/account/currency mismatches and ambiguous
outcomes quarantine without changing financial state. Receipt replay cannot
regress a processed receipt. Different provider events for one operation still
depend on P06/P07 semantic deduplication; that prerequisite is not certified here.

Admin `/integrations/webhooks` shows test-only/quarantine badges and Retry or
Quarantine actions. `integrations.manage` is required server-side. Operator
quarantine and retry are audited. Mobile continues polling authoritative payment
status; it never interprets receipt delivery as accounting or fulfilment success.

Recovery: verify receipt ID, digest, generation, account/configuration and safe
error code; do not paste raw evidence/secrets into tickets or logs. Fix the root
mapping/adapter defect through governed configuration, then retry the original
receipt via `POST /v1/admin/webhooks/deliveries/{id}/retry`. Mismatches remain
quarantined until verified; retry does not override validation. Use
`POST /v1/admin/webhooks/deliveries/{id}/quarantine` to stop unresolved delivery.
Processed receipts cannot be retrospectively quarantined. Raw evidence is hidden
and encrypted; no public or ordinary admin raw-evidence endpoint is added.
Approved break-glass evidence access and retention duration await Security policy.

Queue outage: durable E25 delivery intents remain pending; the existing scheduler
dispatches DeliverAdminRetryIntentsJob every minute. Restore the queue, verify
intent dispatch and receipt status, monitor `webhooks` workers, then audit recovery.
An outage after financial effect must query/recover the original operation, never
repeat initiation. Outbox and domain inbox delivery beyond this handoff is P11.

Completion evidence: one receipt identity/digest, trusted mapping, processing
generation and attempt history, outcome/exception, ledger/domain references where
financial prerequisites permit. The real two-process test proves one receipt and
one delivery intent, not provider certification or exactly-once external charging.

## Local migration and verification

The initial P10 operation applied the additive receipt migration below. The
authorized continuation also applied reviewed predecessor additions individually;
see [readiness.md](readiness.md) for the partial-run history and targeted log.

```powershell
php artisan migrate --path=app/Modules/Integration/Infrastructure/Migrations/2026_10_22_000001_add_verified_webhook_receipts.php --no-interaction
```

Do not run all pending predecessor migrations without reviewing their contracts
and rollout/rollback evidence. Rolling P10 down destroys the new evidence columns;
archive required evidence first under approved policy and drain receipts/workers.
Rollback must not restore the insecure legacy financial callback processor.
No production deployment is authorized. See evidence.md for exact tests/results.

## Continued submission and recovery flows

| Flow / trigger | Prerequisites and states | Responsibilities and success | Failure / recovery / completion evidence |
|---|---|---|---|
| Customer submits payment | Server-selected intent/account; locked payment intent; no active or unknown operation; SUBMITTING persisted before adapter | Mobile retains original idempotency key and reads authoritative state; Payments locks/rechecks and submits once; E25 owns connector infrastructure; Wallet/ledger remain authoritative | Adapter exception leaves SUBMITTING and processing intent. Do not issue a new key. Existing crash scanner queries the original operation. Record attempt/operation IDs and query evidence; ACK is never completion |
| Timeout or crash scanner | Existing durable attempt/operation and trusted query-capable adapter, or explicit unsupported capability | Foundation queue runs existing jobs; Payments locks the attempt, processes verified definitive outcome through outcome engine or records UNKNOWN; admin investigates reconciliation | Unknown/no reference/unsupported capability keeps hold and nonterminal intent; one reconciliation exception on repeat. Admin verifies original operation, account and bank evidence before resolving; never release a hold based on absence of evidence |
| Request replay/cache outage | Same actor/scope/key/body and durable response; original operation identity | Payments replays saved DB response even if cache unavailable; foundation locks use owner tokens; mobile reuses key; admin needs no replay override | Changed body conflicts; new request without lock infrastructure fails closed. Recover cache then replay same key; record one durable response and original operation, no new charge |
| Local additive rollout | Reviewed schema and migrations, financial row inventory, verified local target | Foundation operator applies exact files and checks state constraints/migration records; Payments supplies state semantics; no mobile change | Stop on existing-table/history mismatch; inspect rather than reset/reseed. Reconcile historic migration history before environment rollout. Down migration blocks narrowing while newer states exist; preserve evidence and holds |

P10-R1 intentionally blocks fresh-key same-provider submission while UNKNOWN
until verified resolution. Original-key replay remains supported. P06 FR-07's
wording requires architecture review; do not bypass this guard operationally.
