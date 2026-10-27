# P10 prerequisite closure — 22 October 2026

**Status: COMPLETED for the requested local prerequisite remediation.** Overall
P10 remains **PARTIAL**: real provider onboarding/certification, approved evidence
policy and release authorization are external gates. Live callback processing
remains **disabled**; no next prompt or production deployment was started.

This supersedes the failing prerequisite counts and pending technical findings in
[readiness.md](readiness.md), retaining those files as an audit trail.
Source authority remains architecture v1.1, verified original/copy SHA256 in
[spec.md](spec.md); FD06, LC01/LC03/LC05 and sections 1/8 apply.

## Changes and source-to-test links

| Source / story / tasks | Change | Completion evidence |
|---|---|---|
| FD06/LC03; US3 T019 | [Orchestration](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php) locks/rechecks Payment rows in the actual Payment transaction. SUBMITTING commits before external execution. Adapter-result persistence cannot replace an already advanced outcome. Original operation survives exceptions. | [Two-process test](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10PaymentGateConcurrencyTest.php): simultaneous distinct keys execute one test operation; independent PostgreSQL session observes committed SUBMITTING; hard process exit recovers original operation without another initiation |
| FD06/LC01; US1/US3 T019 | Server resolves one eligible provider account from authoritative intent context; explicit selection is revalidated. Missing/ambiguous/ineligible account rejects before execution. Query selects account columns, preventing provider join IDs from replacing account IDs. Configuration version is persisted. Restricted module/country context without authoritative information fails closed. | [Account tests](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10AccountSelectionTest.php): automatic selection/version, unknown account, cross-organisation rejection, live flag false |
| LC03/LC05; US3 T019 | Existing common [OutboxEventPublisher](../../wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/OutboxEventPublisher.php) gains an additive [connection-aware contract](../../wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/ConnectionAwareEventPublisherInterface.php). Payment events use the Payment transaction to insert the same Platform outbox. Existing publish/publishAll Platform behavior remains compatible. | [Ledger gate tests](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10LedgerGateTest.php): Payment state and outbox roll back together; actual ledger API replay produces one journal, two balanced 100-minor-unit lines and one confirmation event |
| LC03; US3 T019 | Fix PaymentConfirmedEvent readonly default-allocation assignment; PaymentCircuitOpenEvent implements the existing integration event contract. Accurate createIntent/intentShape return types remove three static findings. | Actual ledger acknowledgement/repair and full orchestration PHPStan pass; allocation reference defaults without a second readonly assignment |
| Constitution Identity/Security; prerequisite regression T019 | Refund rejection imports the proper StepUpRequiredException. A high-value approval with no verifier fails closed. | StepUpEnforcementTest checks rejection, no save without verifier, invalid proof, valid proof and low-value behavior |
| P05/P06/P07; T019 | Repair stale controller/facade/constructor fixtures, instantiate real final services, roll back fixture rows instead of RefreshDatabase, and ignore comments when checking the permission assignment source. Replace mirrored obligation validation with actual orchestration calls. | All 317 Payment module unit tests pass in final run; no dynamic types, suppression rules, removed guards or dependency upgrades |
| LC01/LC05; T020 | Verify and explicitly adopt the existing local Payment schema, including P06 and P10 receipt additions, with a source/hash audit. | [Audit](gates-schema-audit.json), [adoption record](gates-schema-adoption.json), [migration verification](gates-migration-status.log), [runtime](gates-runtime.json) |

All development operation controls are confined to
[P10PaymentGateWorker.php](../../wallet-ecosystem/services/api/tests/Support/P10PaymentGateWorker.php).
It checks the development guard, implements explicit initiate/query scenarios,
uses no network and invokes no bank API. The effect file is a test observation,
not evidence of actual bank execution or certification. Wallet contract locators
in the ledger fixture are explicit test doubles; balances and journal lines come
from the actual authoritative Ledger API. Financial fixture writes roll back;
no committed journal or wallet balance is edited/deleted. Fulfilment remains
pending and no entitlement, settlement, refund or payout completion is invented.

## Verification actually run

Installed PHP **8.4.15**, PostgreSQL extensions enabled per invocation. Default
server runtime unchanged. No global php.ini or dependency updates.

| Check | Pass | Fail | Error | Skip | Evidence |
|---|---:|---:|---:|---:|---|
| Final combined backend regression | **442** | **0** | **0** | **0** | [JUnit](gates-final-results.xml), [log](gates-final-tests.log), 1081 assertions |
| PHPStan including full orchestration and common publisher | **45 files** | **0** | — | — | [Manifest](gates-phpstan-files.txt), [log](gates-phpstan.log) |
| Existing local schema audit | 192 columns / 29 constraints / 37 indexes | 0 missing | — | — | [Audit](gates-schema-audit.json) |
| Package migration verification after local baseline | Nothing to migrate | 0 | 0 | — | [Log](gates-migration-status.log) |

The combined command covers app/Modules/Payment/Tests/Unit; P10 contract and mock
adapter tests; P10 verified ingress and receipt race; PostgreSQL recovery and
idempotency; new two-process submission/hard-crash, actual-ledger/outbox and
account-selection tests; and the entire affected E25 feature directory. Individual
earlier runs overlap and must not be added to 442. PHPUnit reports two metadata
deprecations; the installed PHPStan/config also emits existing deprecation/version
notices. These are not ignored failures. Prior admin Jest/TypeScript evidence
remains in evidence.md; this remediation changes no UI.

## Schema reconciliation and operational boundary

The default Platform migration repository was missing Payment records despite
the schema being present. The old RefreshDatabase fixture could rebuild the
default migration repository without consistently rebuilding module schemas;
that fixture now uses scoped DatabaseTransactions. Historical execution cannot
be reconstructed reliably from the repository, so no original execution dates
or history were invented.

The standalone [audit/adoption script](audit-local-schema.php) verifies the actual
local schema against source-declared columns, named constraints and indexes,
including supplemental raw/dynamic DDL fields. It records source SHA256 hashes,
schema fingerprint and zero financial-table counts. Its explicitly selected
`--adopt-local` operation recorded **23 package migration identities at batch 0**
as an **observed schema baseline**, not as a claim that their historical up methods
ran. It changes migration metadata only; it runs no financial DDL or seeds and
does not insert permissions, activate providers or invent bank configuration.
The command refuses non-local/non-loopback environments, enabled live processing,
missing schema objects or nonempty financial tables. Audit JSON is retained.

After this local adoption, the real Payment module migrate command reports
“Nothing to migrate.” Do not roll back adopted batch 0 or infer that it is a
deployment history for any other environment. Other modules' migration history
was not rewritten. Production/environment rollout still needs its own schema
and data migration plan; this local baseline is not a portable release approval.

## Requirement clarification and decisions

P10-R1 is reflected in [P06 FR-07](../055-payment-state-engine/spec.md): an UNKNOWN
operation blocks a fresh key even on the same provider. Original-key replay and
original-operation query remain available. A replacement requires verified
resolution under normal state rules. This follows v1.1's unresolved-charge rule
and the user's no-duplicate-charge requirement; no provider idempotency guarantee
is assumed. Historical conflicting wording remains identified in readiness.md.
Business/provider certification and release sign-off are not implied.

## Flow and operator handover

| Flow / trigger | Prerequisites and state | Responsibilities / success | Failure, recovery and completion evidence |
|---|---|---|---|
| Submit or retry from mobile | Authoritative intent and one eligible account; original request key | Mobile reuses key; Payments locks and persists SUBMITTING before E25 adapter execution; account/version/operation retained | Unknown or pending blocks replacement. Refresh/query original operation. Evidence: one attempt and test effect, original operation identity; no ACK-as-paid behavior |
| Crash after provider acceptance | Durable SUBMITTING and trusted query-capable adapter | Foundation worker queries original operation; Payments handles verified result; admin sees unresolved accounting independently | Missing/ambiguous result preserves UNKNOWN/hold and reconciliation. Hard-exit test proves no second initiation. No missing bank result is fabricated |
| Ledger acknowledgement lost | Verified capture; approved accounting inputs; original ledger key | Ledger owns balanced posting; Payments records ledger_posted and one shared outbox confirmation; mobile reads independent accounting/fulfilment states | Repair uses same ledger key. Actual test verifies one balanced journal and one confirmation after replay; fulfillment remains pending |
| Payment transaction fails | Shared-database monolith and connection-aware publisher | Foundation stores outbox on same connection as Payment state | Transaction rolls both back. Operator retries original operation/receipt, never manually edits balances or journals |
| Schema/history mismatch | Local, live disabled, audited schema and empty financial tables | Foundation operator reviews audit, explicitly adopts package baseline, then checks scoped migration status | Stop on mismatch; do not reset/reseed or replay old create migrations. Preserve adoption JSON and never rollback baseline 0. Other environments require separate review |

## DoD

| Criterion | Result |
|---|---|
| Previously failing prerequisite unit tests | PASS — 317/317 |
| Five orchestration static findings and affected scope | PASS — 45-file PHPStan |
| Real concurrent distinct-key submission and persist-before-execute | PASS |
| Hard process crash, original query identity, no duplicate initiation | PASS |
| Actual ledger balance/idempotent acknowledgement recovery | PASS |
| Payment state/common outbox atomic rollback | PASS |
| Local migration bookkeeping reconciled with explicit audit | PASS for this package/local baseline |
| P10 invalid/duplicate/account mismatch controls and E25 regressions | PASS |
| Live processing remains disabled | PASS — runtime flag false |
| Real provider contract/certification and Security/Finance release policy | BLOCKED — external evidence absent |

Next permitted action: review the uncommitted package and obtain real provider
and policy evidence for a separately authorized onboarding/release decision.
Do not enable live processing, start P11 or deploy production automatically.
Branch `main`, base `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no new commit.
Existing unrelated predecessor changes remain intact.
