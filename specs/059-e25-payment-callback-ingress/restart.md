<!-- Latest checkpoint: prerequisite-closure.md -->
> Local prerequisite remediation is complete: 442 passing backend tests, 45-file PHPStan, real submission/crash and ledger/outbox verification, and audited package-only batch-0 schema adoption. Live processing remains disabled. See [prerequisite closure](prerequisite-closure.md). Earlier failed findings and operations below are retained history.

# P10 restart notes

Date: 2026-10-22. Status PARTIAL. Responsible role: Integration engineer.

## Latest continuation checkpoint (supersedes earlier local-operation/results notes)

Read [readiness.md](readiness.md) first. Selected predecessor repairs now pass
82 tests on installed PHP 8.4.15; E25 feature directory 58 pass; P10 callback/mock/
real two-process receipt race 57 pass. The broader Payment unit diagnostic has
283 pass, 7 fail, 26 error, 0 skip. Scoped 39-file PHPStan passes; whole
orchestration still has 5 findings. Admin source unchanged since its prior 7-pass run.

Applied P06 operation migration plus thirteen individually reviewed local Payment
module migrations (twelve dated 2026_10_21 and the new state-check migration).
The earlier directory-wide attempt stopped at an existing provider table with
missing platform migration history; no existing table/history was fabricated or
dropped. Do not rerun module-directory migrations blindly. Runtime inventory shows
empty financial intent/attempt/refund tables. No bank activation, seed, ledger
adjustment or production deployment occurred. Default server PHP was not changed.

New safety changes: adapter exception keeps SUBMITTING/original operation for the
existing scanner; new-key UNKNOWN retry blocked even on same provider (P10-R1,
P06 FR-07 conflict recorded for review); locked payment-connection submission
recheck, timeout/recovery row locks and holds preserved; owner-scoped idempotency
lease and durable cache-outage replay. T019/T011 remain open. No provider contract,
certification, security evidence policy or release authority may be inferred.
No test/static sessions remain active at this checkpoint. Branch/base commit and
uncommitted predecessor-preservation instructions below remain applicable.

Actual Git branch: **main**. HEAD:
`a72ca74252e4ecb42e08b7a9db398491d04f4778`
(`remove community service code to apply new code with exact requirement`).
No new commit created: extensive pre-existing modified/untracked P01–P09 code
shares files with P10. Preserve it; do not reset, clean or stage the whole tree.
Spec Kit feature identifier is `059-e25-payment-callback-ingress`; this is not a
claim that Git switched branches. `.specify/feature.json` now selects this package.

Installed workflow was inspected; real setup scripts ran. No applicable AGENTS.md,
extensions.yml or package reviewer checklist was found. Source v1.1 is verified
and its unchanged Word file and paragraph text retained under docs/sources with
matching hashes; no need to ask for it
again. Sources remain at their actual paths recorded in spec.md.

P10 implementation is uncommitted: see phpstan-files.txt for scoped API classes,
plus PaymentServiceProvider bindings, PaymentOrchestrationService outcome-interface
conformance and initiate-port resolution, RefundService refund-port resolution,
timeout/crash recovery query-port resolution, admin integration API/monitor/tests,
three OpenAPI files, new tests,
source text/provenance, boundary ADR, package artifacts and P00 register update.
Predecessor modifications to orchestration/provider models/service provider and
other payment files existed before this task; do not overwrite them.

Local operation performed: only additive
`2026_10_22_000001_add_verified_webhook_receipts.php` was migrated. No blanket
migration, data reseed, real provider call, production deploy, manual charge,
ledger adjustment or entitlement issuance. Tests use transactions or uniquely
named disposable test fixtures; the two-process race cleans up its exact IDs.
Do not roll back evidence columns without approved archival/drain procedure.

Final focused evidence: final-results.xml (77 backend, zero fail/error/skip),
admin-tests.json (7), typescript.log (clean), phpstan-final.log (30 scoped files,
no errors). Broader E25 diagnostic snapshot: 93 total, 80 pass / 13 fail.
Predecessor-results.xml: 66 total, 39 pass / 2 fail / 25 error / 0 skip. Full
financial readiness remains unproven. Local PHP is 8.3.28, below approved 8.4+
baseline; test on the approved runtime before acceptance. Existing PHPStan version
and deprecated-option notices remain. YAML parser discovery initially failed,
then installed js-yaml parsed all three updated files after merging an existing
duplicate components block in integration.yaml. See evidence.md for run commands.

Unresolved operations: apply/review predecessor migrations only after their gates
pass; configure trusted account/environment mappings and external secret references
through approved ownership/change process; start/verify existing scheduler and
webhooks workers during an authorized local exercise. This task did not activate
a provider or create operational production mappings. Legacy callbacks without
unique mapping fail closed; old queued raw rows are quarantined, not replayed.
Queue restoration uses durable E25 delivery intents and original receipt IDs.

Next permitted action: repair/review P05/P06/P07 evidence and migration rollout,
and resolve Security evidence policy and provider-contract gaps. Do not simply
toggle `payment.live_callback_processing_enabled`: no live parser/transport is
installed or certified. No Bank 1/2/3 endpoints/credentials may be invented.
Do not start the next prompt automatically; do not deploy production without
existing explicit authorization. No long-running tool/test sessions remain at
handover; existing user services/workers were not deliberately stopped/restarted.
