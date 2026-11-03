# P25 dependency checkpoint: existing P08 resumed

**Date:** 2026-10-28  
**P25 status:** PARTIAL. No P25 order, checkout, ticket-issuance or live-payment flow was added in this checkpoint.  
**P08 status:** PARTIAL — bounded evidence and acknowledgement code implemented for development; migration-backed acceptance and independent review remain open.

## Source and predecessor result

The exact source `docs/sources/Olekard_Shared_Payment_Architecture_v1.1.docx` exists. Its body identifies v1.1 and supports the shared Payments, Wallet, Ledger, E25 and outbox ownership model. It says Bank names are placeholders and no bank API/live provider contract was inspected. It does not resolve AD-08/AD-09.

P08/P06/P02 state code and P11 outbox/replay facilities were reused. The `payment.confirmed` event is still only a delivery signal; Events must query authoritative evidence. No Events resolver or order placeholder is bound.

## Implemented contract links

- P08 requirements/tasks and implementation status: `../057-ledger-ack-fulfilment/tasks.md`, `../057-ledger-ack-fulfilment/research.md`.
- Field mapping, service scope, state separation and retry protocol: `../057-ledger-ack-fulfilment/contracts/confirmation-evidence.md`.
- P25 source-to-code dependency proposal: `payment-ledger-dependency-proposal.md`.
- Code: `PaymentInternalConfirmationContract`, `PaymentConfirmationEvidenceService`, `LedgerModuleContract::findPostedJournalEvidence`, `PostedJournalEvidence`, `LedgerPaymentEvidenceVerifier`, capture fields on `PaymentAttemptModel`, `PaymentConfirmedEvent` additions and P08 migration `2026_10_28_000001_add_capture_evidence_and_fulfilment_ack.php`.
- Tests: `tests/Feature/Modules/Payment/LedgerAcknowledgementTest.php`, `tests/Unit/Modules/Payment/Jobs/RepairPendingAccountingJobTest.php`, `tests/Unit/Modules/Payment/PaymentLedgerEvidenceVerifierTest.php`, `tests/Unit/Modules/Payment/EventHandlers/PaymentConfirmedListenerDedupTest.php`.

## What P25 can rely on now

For a supported path only, typed internal evidence binds succeeded intent/attempt, exact organisation/purpose/reference/payer, verified actual captured amount/currency/provenance, `ledger_posted`, and the Ledger-owned posted journal with stable idempotency key, transaction reference, currency, entry type and exact individual debit/credit correspondence. The attempt stores the journal reference. A same-key Ledger retry closes the crash gap before Payment persistence.

Payment exposes idempotent fulfilment acknowledgement after an authenticated Events caller attests its durable fulfilment reference. This does not verify an Events DB commit, implement Events inbox/outbox consumption, create orders/tickets, or prove ticket issuance. Events must call only after its own durable issuance transaction commits.

## Remaining P25 blockers

- **Orders / T010:** no authoritative Events order schema/service exists; its creation must atomically lock event currency before first durable order/hold. Build only after B-06 price inputs are resolved; never bind the resolver before the order model exists.
- **AD-08:** existing Wallet credit-account config is not itself Finance approval; verify the authorized account and posting purpose before claiming supported wallet readiness.
- **AD-09:** all external/bank attempts stay `pending_ledger`; Finance's written account mapping and supported provider capture evidence are absent. No API, mapping or credentials were invented.
- **T004/Ticket issuance:** P08 evidence is available, but policy gates on issuance threshold, late capture, fees/tax and customer confirmation, and P25's locked order comparison remain open.
- **T033/T034:** database-backed ack/replay and crash-window acceptance; full P08 quickstart; P11 replay tests skipped / Docker environment pending.
- **T035:** module PHPStan still has repository-wide findings; targeted changed-file PHPStan passed (see P08 task notes).
- **Independent review:** Payments/Ledger contract and accounting review; Security review of service identity/scopes and replay/isolation; no reviewer appointment or sign-off is represented.

## Verification evidence

- Final P08 evidence/feature/repair tests: **19 passed, 0 failed, 51 assertions**, including fixture-based retry recovery after the Ledger-post/evidence-query failure window. Existing duplicate/contradictory/out-of-order outcomes: **10 passed, 0 failed, 34 assertions**.
- Ledger evidence verifier + P11 listener-dedup tests: **5 passed, 0 failed**. P11 dead-letter service suite: **4 skipped** because required implementation/model behavior is absent.
- Full `vendor/bin/phpstan analyse app/Modules/Payment app/Modules/Ledger --level=8`: failed with **490 findings** across the existing modules. Targeted changed-file PHPStan at level 8 passed after adding Ledger model PHPDoc and replacing untyped event-dispatcher array access in the touched Payment provider.
- A prior attempt with `RefreshDatabase` failed because local populated Identity schema already contains `identity.users` (`SQLSTATE 42P07`). P08 test harness now avoids refresh/migration; no database reset or migration-history edit was made.

No deployment, bootstrap, publication, checkout, sales, P24 activation, P18 work or P25 implementation continuation occurred.

## P08 verification-only update — 2026-10-28

P25 remains **PARTIAL** and unchanged in implementation scope. This is a dependency evidence update only.

- Disposable `wallet_ecosystem_test` on PostgreSQL 15.19 was isolated under Compose project `p08-isolated-20261028`; the existing development database/container was not migrated or reset.
- The full migration chain first exposed Profile-before-Identity permission schema ordering, addressed in the disposable DB by running the actual Identity predecessor migrations. It then stopped at `2026_10_24_000002_add_lifecycle_to_provider_accounts`, whose `ADD CONSTRAINT IF NOT EXISTS` SQL is rejected by PostgreSQL 15. After verifying migration-created Payment predecessor tables, the actual P08 additive migration succeeded by path; all five evidence fields are nullable and the acknowledgement table exists. This is migration DDL evidence, not service acceptance.
- Four former P11 dead-letter skips pass against PostgreSQL outbox rows (**4 passed, 8 assertions**). Six P08 acknowledgement database tests pass (**6 passed, 40 assertions**), covering a two-session race, exact/conflicting replay, service-scope and organisation/purpose isolation, terminal/ineligible states and rollback fault injection. Final combined P08/P11/P06/P07 run: **39 passed, 134 assertions, 0 skipped**. Real Ledger/Payment posting transaction atomicity and crash recovery, plus authenticated replay route/re-dispatch, remain unverified.
- Current P08 changed source subset PHPStan level 8: **0 findings**. Full Payment/Ledger analysis currently fails with **485 findings**. Independent review is still pending.
- A final changed-test-only PHPStan rerun exhausted the container's configured 128 MB PHP memory limit; the earlier targeted changed-source-plus-tests run completed with zero findings. This is a verification environment limitation.
- Wallet is not proven as a supported issuance-ready path: its current contract exposes hold creation/release, not authoritative committed capture evidence. No E25 receipt is fabricated for internal Wallet. External provider capture facts remain E25-receipt-backed, but external accounting remains blocked pending AD-09. AD-08 remains unresolved too.

P25 must not consume this as an approved financial contract. It may rely on the typed P08 contract in development only after independent Payment/Ledger and Security review and required supported-path evidence. No production or P25 activation follows from these test results.

## P08 verification continuation — 2026-10-28 (dependency evidence only)

- P08 migration compatibility corrections now allow a 310-migration PostgreSQL 15.19 disposable chain to complete after bootstrapping only the empty `platform` and missing `community_services` schemas. Repeated direct migration up() checks passed. The existing development DB/history remains untouched.
- P11 authenticated route/replay/audit/dispatcher delivery passed (**2 tests, 13 assertions**); four service replay tests passed (**4 tests, 8 assertions**). P11 remains globally scoped by the existing operations permission and does not provide a tenant-scope contract.
- Actual Ledger posting/idempotency/outbox recovery and rollback passed (**2 tests, 12 assertions**) with test-only account refs. This does not verify Payment persistence or Wallet capture.
- Combined P08/P11/P06/P07 suite: **43 passed, 164 assertions, 0 skipped**. Changed-file PHPStan level 8 with 1 GB memory: **0 findings**. Full Payment/Ledger prior baseline remains 485 findings and was not rerun.
- Wallet still lacks a committed-capture operation/evidence query. AD-08 and AD-09 remain unresolved; no synthetic capture or provider callback was used. Payment commit/outbox and Events consumer-before-ack crash guarantees remain unverified.

P25 remains **PARTIAL** and unchanged. This is a P08 dependency verification update only; it does not approve Financial behavior or enable order/ticket implementation, publication, checkout, sales, P24 or production activation.
