# P08 restart notes

**Status:** PARTIAL. Bounded development code is implemented; migration-backed acceptance, full static analysis and independent review remain open.  
**Branch:** `main`  
**HEAD:** `c091fe8f42580b1654348d90490918d9aad66d23` (no commit created)  
**Source:** `docs/sources/Olekard_Shared_Payment_Architecture_v1.1.docx` exists and its body identifies version 1.1.

## Completed in this continuation

- Verified the exact source document and corrected the earlier “unavailable” record. It supplies architecture, not AD-08/AD-09 account mappings or bank APIs.
- Added nullable capture amount/currency plus verified E25 receipt provenance; no expected-amount fallback. Unverified, absent or mismatched facts do not produce issuance-eligible confirmation.
- Added Ledger-owned typed posted-journal evidence and individual-line correspondence verification. Payment records journal ID/account references with `ledger_posted` and outbox in the Payment transaction. Stable idempotency key supports recovery if Ledger committed before Payment persistence.
- Added typed internal, Identity-resolved service evidence contract for `events` and exact org/purpose scopes; preserved public payer status API.
- Added Payment-owned durable fulfilment acknowledgement, idempotent exact replay and monotonic `pending → fulfilled` behavior. This does not implement the future Events inbox/order/ticket issuance.
- Updated P08 specification, research, data model, tasks and contract; updated P25 dependency proposal/tasks/restart link.
- Fixed P08 test harness issues without refreshing schema or modifying migration history.

## Verification executed

- Final P08 evidence/feature/repair tests: **19 passed, 0 failed, 51 assertions**. Includes fixture-based same-key recovery after Ledger post/evidence-query failure. Existing duplicate, contradictory and out-of-order outcome tests: **10 passed, 0 failed, 34 assertions**.
- Ledger evidence verifier and P11 Payment listener dedup: **5 passed, 0 failed**. P11 dead-letter unit tests: **4 skipped** because required replay behavior/model setup is absent.
- PHP syntax checks passed for changed PHP source and feature test files.
- Targeted changed-file PHPStan level 8 passed after fixing touched-file findings. Full Payment/Ledger module analysis failed with **490 findings** across modules; resolve/triage before P08 quality gate passes.
- `RefreshDatabase` attempt failed because `identity.users` already exists (`SQLSTATE 42P07`). No reset, schema drop or migration-history edit.

## Remaining tasks and blockers

- T033: apply additive schema and test database-backed fulfilment acknowledgement/idempotency and crash-after-Ledger-before-Payment persistence in a schema-correct isolated PostgreSQL environment.
- T034: full P08 quickstart SC-01–SC-07 and P11 replay/dead-letter scenarios. Current replay tests are skipped; do not treat them as passed.
- T035: module-wide PHPStan baseline has 490 findings; targeted changed-file analysis passed. Identify pre-existing vs newly introduced findings before claiming this gate.
- Finance AD-08 wallet credit account and AD-09 external debit/credit/custody classification remain unresolved. External payment stays `pending_ledger`; no confirmation evidence.
- Independent Payment/Ledger accounting and Security review, including service identity/scope issuance and tenant isolation, remains pending.
- P25 must implement its authoritative order, transactional issuance and consumer inbox; no resolver or Events integration exists. P25 product/Finance policy and provider evidence blockers remain as recorded in `specs/072-paid-events-ticketing/p08-dependency-checkpoint.md`.

## Working tree and next safe action

There are extensive unrelated uncommitted admin, Events, MultiCurrency, P25 and Payment-readiness changes in this shared working tree. Preserve them. P08 source changes and new P08 artifacts are also uncommitted; no commit is required or created.

Next safe step: obtain a schema-correct isolated PostgreSQL test database (without altering the shared database history), run the P08 additive migration there, and execute T033/T034. Then address the full PHPStan findings and request the established independent review. Do not execute AD-08/AD-09 bootstrap, enable live payment, create production grants, deploy, or continue P25 implementation.

## Verification-only continuation — 2026-10-28

**Current branch/HEAD:** `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; no commit created. The shared working tree remains broadly uncommitted; preserve all unrelated P25/Admin/MultiCurrency changes. This continuation changed the P11 replay test, added P08 PostgreSQL acknowledgement tests, and updated P08/P25 evidence artifacts.

### Environment and operations

- Created disposable Compose project `p08-isolated-20261028` from `wallet-ecosystem/docker-compose.test.yml`, with dedicated `wallet_ecosystem_test`, `p08-isolated-20261028_wallet_test` network and `p08-isolated-20261028_postgres_test_data` volume. PostgreSQL **15.19**, `pg_trgm` **1.6**, `plpgsql` **1.0**.
- Laravel test runner used `wallet-ecosystem-api` image and existing vendor volume on that isolated network, with DB host `postgres` and database `wallet_ecosystem_test`. Read-only check confirmed the separate dev container `wallet-ecosystem-postgres-1` remained healthy on database `wallet_ecosystem`; it was not used for migrations or tests.
- After Laravel reported no platform schema for its migration repository, created only the empty `platform` schema in the disposable database. The first full chain run failed at Profile's permission seed before Identity's timestamp-later permissions migration. Executed the actual existing Identity role/permission/role-permission schema migrations in prerequisite order on the disposable DB (no fake rows/records), then resumed the full chain.
- Resumed chain failed at `2026_10_24_000002_add_lifecycle_to_provider_accounts`: PostgreSQL 15 rejects `ADD CONSTRAINT IF NOT EXISTS` (SQLSTATE 42601). This migration and the chain were not edited or bypassed. Since `payment_intents`/`payment_attempts` were created by project migrations, executed the actual additive P08 migration by path; it succeeded. PostgreSQL confirms five P08 attempt fields nullable and the durable acknowledgement table present. The test DB has 265 executed migration records, including one P08 migration record.

### Verification completed

- Final combined P08/P11/P06/P07 regression command: **39 passed, 0 failed, 134 assertions, 0 skipped**. This includes the four formerly skipped P11 dead-letter cases (**4 passed, 8 assertions**) using PostgreSQL outbox rows and six P08 acknowledgement database cases (**6 passed, 40 assertions**).
- P08 PostgreSQL acknowledgement cases exercise nullable capture/journal persistence, exact replay, conflicting replay denial, a true separate-session concurrent same-reference race, service-scope and organisation/purpose isolation, ineligible/terminal states, and rollback after a trigger fails the fulfilment-state update. This proves atomicity only for the fulfilment acknowledgement row and fulfilment state.
- P08 orchestration/recovery and P06/P07 replay/duplicate tests use fixtures/mocks. The authenticated P11 route/re-dispatch feature was not run.
- Existing P06/P07 replay/contradiction/duplicate-capture tests: **10 passed, 34 assertions**.
- Targeted P08 production source and both changed test files PHPStan level 8: **0 findings**; full Payment/Ledger PHPStan: **485 findings** (failed; previous record was 490). PHP syntax checks passed for both changed tests. `git diff --check` emitted only existing CRLF-normalization warnings.
- The first DB-test iteration reported **3 passed, 1 failed** due to a test fixture lacking a foreign-organisation grant; authorization correctly rejected the request before the expected domain-scope assertion. The fixture was corrected; final DB and combined suites passed. Initial test-file PHPStan found four nullable-result issues; an explicit non-null assertion fixed them before final analysis.
- A final changed-test-only PHPStan rerun exhausted the API container's configured **128 MB** PHP memory limit in a parallel worker. The earlier targeted changed-source-plus-tests run completed with 0 findings; retain the rerun as an environment-limited verification gap.
- Capture evidence review: external callback facts originate from validated E25 receipts; AD-09 accounting remains unsupported. Wallet has only create/release hold APIs, no committed capture query; do not synthesize E25 evidence for wallet. Wallet issuance confirmation remains withheld pending authoritative Wallet/Ledger evidence; AD-08 is unresolved.

### Still open

- T033: the Payment DB acknowledgement replay/concurrency, conflict, ineligible states, service scope/tenant-purpose isolation and rollback path are now tested; still missing real Payment posting status/journal/outbox atomicity and process crash after Ledger commit before Payment persistence.
- T034: full P08 SC-01–SC-07 and authenticated P11 route/re-dispatch feature. The dead-letter service cases pass; route-level replay and re-dispatch remain unverified.
- T035: full module PHPStan (485 findings) and a reviewed baseline diff. Targeted changed P08 production/test files currently have zero findings.
- Migration-chain owner: Payment/ProviderRegistry migration `2026_10_24_000002` is PostgreSQL-incompatible; Identity/Profile ordering also required executing existing Identity schema migrations before the Profile permission seed. Follow approved migration repair governance before changing migration history or project migrations.
- Independent Finance/Ledger, Security and operational review remains pending. AD-08 and AD-09 remain unresolved; no test mapping is Finance approval.

**Next safe step:** resolve and independently review the PostgreSQL 15-compatible migration dependency/order through the repository migration-change process, then run the full migration chain from a fresh disposable DB and verify the actual Ledger-commit/Payment-reference crash window and posting/outbox commit boundary. Do not touch dev migration history, P25 implementation, bootstrap, paid publication, sales, deployment or P24.

## P08 migration and integration verification continuation — 2026-10-28

**Branch/HEAD:** `main` / `c091fe8f42580b1654348d90490918d9aad66d23` (no commit). Preserve broad unrelated uncommitted Admin, Events, MultiCurrency and P25 work. P25 implementation was not resumed.

### Completed

- Kept legacy Profile migration identity; added explicit missing-prerequisite deferral and follow-up `2026_09_09_010000_seed_profile_permissions_after_identity`. Repeated follow-up up() preserved four Profile permissions and four admin role links.
- Replaced PostgreSQL-invalid constraint syntax in Payment migrations `2026_10_24_000002` and encountered next blocker `2026_10_24_000005` with exact-relation `pg_constraint` guards. No down/drop/reset/schema-history operation was run against the existing development database.
- Clean test DB `wallet_ecosystem_p08_clean_20261028`, PostgreSQL 15.19: full **310 migration** chain completed. It needed only empty `platform` (Laravel migration repository) and `community_services` (omitted by current schema bootstrap) schemas created in that disposable DB. No tables or migration rows were fabricated. Direct repeated `up()` checks passed; exact constraint/seed counts were checked afterward.
- Added real PostgreSQL Ledger tests. Actual Ledger post followed by injected worker failure before any Payment journal-ref write, then idempotent retry, produced one journal and one Ledger outbox event. A trigger failure during actual Ledger outbox insert rolled back journal, lines, idempotency and outbox writes.
- Replaced skipped P11 replay feature. Actual route permission checks, audit row, duplicate replay while pending, dispatcher and one delivery passed. Existing P11 service DB cases also passed.
- Corrected P08 acknowledgement DB cases that had omitted capture-evidence setup in conflict/concurrency/rollback cases. The earlier combined attempt failed those 2 cases; after fixture correction the final suite passed.

### Checks actually run

- Combined P08/P11/P06/P07 command on the fully migrated disposable DB: **43 passed, 0 failed, 164 assertions, 0 skipped**. Includes 6 Payment acknowledgement DB cases, 2 real Ledger DB cases, 2 P11 authenticated route cases, 4 P11 dead-letter service cases and existing fixture-backed payment replay/verifier tests.
- Changed-file PHPStan level 8 with `--memory-limit=1G`: **0 findings**. Two initial `GenericUser::$id` findings were corrected using a checked `getAuthIdentifier()` value before the passing run. Full Payment/Ledger PHPStan was not rerun; prior recorded result is 485 findings.
- PHP syntax checks passed for all seven changed migrations/test files.
- One P11 run initially failed because runner Redis used development address `127.0.0.1:6380`; rerun with isolated `REDIS_HOST=redis`, `REDIS_PORT=6379` passed. That failed run did not write successful test data.

### Remaining blockers and exact next safe step

- T033 remains open: no actual Payment `repairAccountingForIntent()` database crash/recovery path or Payment-state+journal-reference+Payment-outbox transaction was run. Wallet provides holds only and no authoritative committed-capture evidence; do not synthesize it. The Wallet/Finance-owned committed capture operation/evidence and AD-08 account mapping are required. AD-09 external accounting remains separate and unresolved.
- T033 consumer crash after domain persistence before acknowledgement remains blocked until an Events order consumer/inbox exists; do not resume P25 here.
- T034 full P08 quickstart remains incomplete. P11 route/service replay slices pass, but global P11 permission has no tenant-scope contract, so cross-scope denial is not established.
- T035 changed slice has no findings; full Payment/Ledger baseline remains 485 findings from prior analysis and independent review remains pending.
- Clean chain depended on empty `community_services` schema bootstrap because `create_all_schemas` omits it. Record/assign the schema-bootstrap owner before claiming unassisted first-boot migration is clean.
- AD-08 and AD-09 remain unresolved. Test-only account refs are not Finance approval. Security/Payment-Ledger independent review remains pending.

**Next permitted action:** obtain the Wallet/Finance-approved committed-capture contract and AD-08 evidence, then run actual Payment capture-to-Ledger-to-Payment persistence/recovery verification in another disposable database. Do not resume P25, activate payments/P24, deploy, or touch the existing development DB/history.

## Superseding sustained execution checkpoint — 2026-10-28

**Branch/HEAD:** `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. The working tree contains many unrelated pre-existing Admin, Events, MultiCurrency and P25 changes; they were preserved.

The bounded Wallet committed-capture mechanism and its Payments integration have now been implemented for development and verified on isolated PostgreSQL. See the latest `verification-traceability.md` section. The earlier “Wallet lacks a capture path” statements above are superseded for development; AD-08 is still an operational Finance blocker. The supported internal Wallet path is not external provider certification.

**Actual checks:** disposable clean bootstrap plus two migration runs (313 rows); focused P08/P11/P25 suite **75 passed / 0 failed / 0 skipped / 411 assertions**; Wallet capture/release and capture/expiry races passed with independent backend sessions; P25 inventory and currency-coordination PostgreSQL scripts passed; changed Wallet/Payment/Ledger PHPStan level 8 found **0**. No development DB schema/history was modified. P25 concurrency is development evidence only.

**Still open:** T034 operator quickstart SC-01–SC-07 reconciliation/execution; full module-wide PHPStan baseline and independent review; AD-08 Wallet accounting mapping and AD-09 provider/external mappings; P25 B-06 fee/tax/rounding and T004 financial issuance contract; P25 T010 durable Events order/checkout and T011 durable entitlement/inbox; T013 refund/cancellation policy; T014 P18/B-11 payout only. P11 replay remains intentionally platform-wide under its existing operations permission; no tenant-scoping guarantee is claimed.

**Next safe step:** reconcile P08 SC-01–SC-07 documentation and run the remaining approved operator scenarios without enabling operational capture. After that, the next cross-package work requires the exact Finance/Payments approvals listed above; no production mapping, grant, publication, checkout, deployment, P24 activation or bootstrap execution is authorized.

P08 quickstart correction: T034 automated SC-01–SC-07 plus P11 replay regression is now checked in tasks.md. SC-06 route authentication/permission/output has explicit route tests; SC-07 repair exhaustion has PostgreSQL integration tests. The first run found and fixed the missing authoritative provider_code on accounting_failure records. Targeted rerun is 8/8 passing, 51 assertions. T028 still records the full documented operator script as not manually executed; no live provider call was made. T026 full module-wide static analysis remains a pre-existing 490-finding failure; targeted changed code is clean.

Final count correction: combined regression after the SC-06/SC-07 additions and repair-exhaustion fix is **80 passed / 0 failed / 0 skipped / 441 assertions**. Pint checked 14 files; targeted changed-code PHPStan plus the new P08 feature tests reports 0 errors. Earlier 75-test checkpoint is superseded. P08 T028 still means the literal operator walkthrough, separate from its automated scenario acceptance; T026 full module PHPStan remains open at the recorded baseline.

Final combined suite correction - 2026-10-28: after adding persisted Payment confirmation outbox dispatch retry coverage, the P08/P11/P25 combined regression is **81 passed, 0 failed, 0 skipped, 446 assertions**. `WalletCapturePaymentIntegrationTest::test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried` asserts the outbox row remains pending and no notification exists after Payment commit, then dispatches that row in a later worker invocation and asserts one delivered event/notification. This is restart/re-dispatch evidence, not a forced OS process termination. The remaining consumer commit-before-ack flow needs a P25 durable order/entitlement effect.

## P08 final execution checkpoint - 2026-10-28

Branch `main`, HEAD `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. P08 is PARTIAL: development Wallet capture, typed posted-journal evidence, Payment persistence/outbox, repair and acknowledgement are implemented. T028 is complete for developer walkthrough execution; manual/live-provider operation and independent operator acceptance are not claimed. T026 remains open: full Payment/Ledger PHPStan reports 485 findings (matches latest baseline); changed-slice analysis is 0. AdminFxManagementTest passes 22/22 in disposable Postgres. Test results and the initially misconfigured shared-container invocation are reconciled in `verification-traceability.md`.

P25 handoff: the approved issuance invariant is not awaiting re-approval. The next engineering slice, after B-06 owner inputs, is an authoritative Events order snapshot with order-bound Payments purpose reference, atomic first-obligation currency lock, quote/customer confirmation evidence, and confirmation consumer that verifies P08 fields before durable per-ticket issuance/acknowledgement. P08 does not itself create the Events order or durable ticket effect. External AD-09 remains pending; AD-08 operational mapping and independent Finance/Security review remain absent.

No P25, P18, production mapping, bootstrap, publication, checkout/sales activation, deployment or P24 work was started in this P08 closeout.
