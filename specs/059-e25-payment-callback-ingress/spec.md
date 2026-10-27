# P10 E25 adapter framework and callback ingress

Date: 2026-10-22. Mode: Implement. Responsible role: Integration engineer.
Status: PARTIAL — independent development framework implemented and verified;
live activation BLOCKED pending prerequisite evidence and provider contracts.

## Source authority

Latest prerequisite verification is complete: [closure](prerequisite-closure.md),
442 passing backend tests and 45-file PHPStan. Live processing stays disabled by
explicit user instruction; real provider contracts/certification and release
policy remain external gates. Original failed prerequisite snapshots are history.

Verified source: `C:/Users/BAPS/Downloads/OleKard_Shared_Payment_Architecture.docx`,
internal Version 1.1, 19 October 2026, SHA256
`B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`.
An unchanged, hash-matched repository copy is retained at
`docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`.
Complete paragraph/table-cell text retained in
`docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`.
Prompt authority: root `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0,
P10, retained in `docs/sources/OleKard_Managed_Prompts_v1.0.txt`.
Requirements: FD06; LC01, LC03, LC05; sections 1 and 8. No applicable AGENTS.md
found in repository. Constitution and CLAUDE.md apply.

## User stories and acceptance

### US1 Capability contracts and safe mocks (P1)
As an integration engineer I register reviewed adapters with separate initiate,
query, refund and optional payout contracts. Existing PaymentAdapterInterface
remains compatible. Unsupported operations explicitly reject. Development mock
scenarios are labelled and usable only in local/development/testing, never staging
or production, including cached singleton use after an environment change.
Independent tests: capability dispatch and unsupported payout; guard mock
operations and parser; explicit success/failure/unknown controls.

### US2 Verified durable ingress (P1)
As E25 I verify exact raw bytes using an account-scoped secret resolved through
foundation secret infrastructure. Server-managed integration configuration binds
provider account and environment; payload tenant IDs never choose context.
I durably retain restricted evidence and a sanitized notification before ACK.
Deduplication scope includes account/environment and provider event identity;
identity reuse with different content is rejected. Queue failure after commit
retains receipt for recovery. Suspension stops new payments, not safe resolution.
Independent tests: invalid/missing signature, body tampering, duplicate,
identity collision, storage and queue failures, disabled-provider resolution.

### US3 Semantic validation and operations (P1)
As Payments I validate provider/account/reference/amount/currency against durable
attempt and intent before processing. Mismatch quarantines without financial
mutation; unknown outcomes remain unresolved. Replay uses the original receipt
and operation identities. E25 keeps retry/quarantine/evidence authorization and
audit; Payments owns state transitions and never writes wallet balances.
Independent tests: matched notification, account/money mismatch, duplicate and
out-of-order delivery, quarantine and authorized retry. Admin reuses E25 screens;
mobile reads authoritative payment status and never interprets ACK as paid.

## Requirements

Continuation: selected local P05/P06/P07 repairs and schema verification are
recorded in [readiness.md](readiness.md). P10-R1 blocks fresh-key initiation while
UNKNOWN without verified non-execution/provider deduplication evidence; original
operation replay/query remains available. This records the conflict with P06
FR-07 explicitly rather than declaring that prerequisite ready.

- FR01 Reuse E25 lifecycle, ingress, receipt, retry and secret infrastructure.
- FR02 Separate capability interfaces; retain composite compatibility contract.
- FR03 Verify raw body before financial processing, with trusted account mapping.
- FR04 Durable receipt before acknowledgement; recover queue dispatch failures.
- FR05 Account/environment event deduplication and conflicting-body rejection.
- FR06 Bind money/context to stored attempt/intent; quarantine mismatch; never infer failure from uncertainty.
- FR07 Restrict and encrypt payment raw evidence; no secrets/raw payload in ordinary API resources or logs.
- FR08 Explicit local mock scenarios; fail production activation and execution.
- FR09 Preserve independent accounting, fulfilment, refund, settlement and payout states.
- FR10 Record onboarding/certification gates, source traceability and operator recovery.

## Prerequisite gates and scope boundaries

P05/P06/P07 contracts exist in code. Fresh predecessor subset: 66 tests, 25 errors,
2 failures (final-class mock errors and orchestration exception argument mismatch
among causes). A checked task is not acceptance evidence. Live financial callback
activation remains BLOCKED until the affected tests and crash/concurrency evidence
are verified. Independent receipt/framework work proceeds. Real Bank 1/2/3
contracts, credentials, signature/replay rules and certification are absent:
bank adapters and production activation BLOCKED. No invented endpoints.

No new ledger, balances, business entitlements, payout execution or provider API.
P11 business-event delivery is outside this package. Retention duration and
provider-specific rotation/replay windows await policy/contracts; do not invent.

## Success criteria

Invalid or mismatched callbacks produce zero orchestration calls; concurrent
duplicates create one receipt; queue outage cannot lose an acknowledged receipt;
supported local mock operations are visibly test-only and production-blocked;
new tests and affected existing tests run with honest results. Provider
certification and release remain separate gates.
