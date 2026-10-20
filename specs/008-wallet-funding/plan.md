# Implementation Plan — E08 Wallet Funding

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new technologies introduced; Eloquent, raw SQL, Pest, PHPStan all established project tools
- [x] No TBD value invented — TBD-E08-001 (provider selection) left as `MockFundingProviderAdapter`; TBD-E08-002 (fee rates) defaults to 0; TBD-E08-003 (limit schedule) defaults to permissive; TBD-E08-004 (float account reference) left as NULL config stub; TBD-E08-005 (adapter scope) resolved: E08 defines interface + mock, E1000 owns real adapters
- [x] Authorization remains server-side — all endpoints guarded by `identity.require-permission` middleware (E04); agent cash-in requires two permissions; IDOR returns 404
- [x] Financial integrity preserved — double-entry enforced via `LedgerModuleContract::postEntry()`; `FundingTransaction.status = completed` and `ledger_journal_id` set atomically in same DB transaction; `FundingReceipt` is immutable; direct wallet balance mutation prohibited
- [x] Security/audit requirements incorporated — SR-E08-001 through SR-E08-010 mapped; provider HMAC verified before any state change; agent PIN hashed; all mutations audited; no PII in events
- [x] UX/design-system requirements incorporated — E08 is backend service-to-service + mobile API; no Admin UI screens for funding in scope (Admin unmatched deposit review is an API + future E19/E23 UI)
- [x] QA strategy incorporated — unit, feature, concurrency, architecture tests specified; all 12 success criteria (SC-E08-001 through SC-E08-012) map to automated test scenarios in `quickstart.md`

---

## Technical Context

| Item | Decision | Source |
|------|----------|--------|
| Backend | Laravel / PHP 8.4+ | Constitution |
| Database | PostgreSQL; `funding` schema on same physical DB as `platform`, `wallet`, `ledger` | `config/database.php` |
| DB connection for models | `platform` (OutboxEventPublisher atomicity) | `research.md §1` |
| DB connection for migrations | `ledger` | `research.md §1` |
| Explicit table prefix | `funding.funding_transactions`, `funding.funding_receipts`, etc. | `research.md §1` |
| Transaction scope | `DB::connection('platform')->transaction()` wrapping all state + outbox + idempotency | `research.md §2` |
| Provider callback processing | Inline (not queued) — returns 200 only after successful wallet credit | `research.md §3` |
| UUID | UUID v7 via `Ramsey\Uuid\Uuid::uuid7()` | `research.md` (E07 pattern) |
| Provider adapter pattern | `FundingProviderAdapter` interface; `MockFundingProviderAdapter` for E08; real adapters in E1000 | `research.md §4` |
| Agent PIN verification | `IdentityModuleContract::verifyTransactionPin($actorId, $pin)` | `research.md §5` |
| Customer pocket account ref | `WalletModuleContract::getPocketLedgerAccountRef($walletId, $currencyCode)` | `research.md §6` |
| Float account ref | `PlatformConfigurationService::get("ledger.float_account.{$currency}")` | `research.md §7` |
| Agent float account ref | Deterministic: `agent_float_{agentId}_{currencyCode}` | `research.md §8` |
| postEntry() pattern | Two variants: digital credit (`entry_type=credit`) and agent cash-in (`entry_type=debit`) | `research.md §9` |
| Bank reference format | `OLK-{6CHAR}` (unambiguous alphabet, no I/O/1/0) with up to 5 retry-on-collision | `research.md §10` |
| Idempotency namespaces | Three: `funding:`, `funding_callback:`, `funding_bank:` | `research.md §11` |
| Expiry command scheduling | Every 5 minutes via `FundingServiceProvider::boot()` | `research.md §12` |
| Metrics | `MetricsCollectorInterface` injected into 5 service classes; 9 metrics (FR-E08-051) | `research.md §16` |
| Architecture test | `tests/Architecture/Modules/FundingBoundaryTest.php` | `research.md §14` |

---

## Module Structure

```
app/Modules/Funding/
├── Application/
│   ├── FundingService.php                      (FundingModuleContract adapter)
│   ├── Services/
│   │   ├── FundingInitiationService.php        (US-FUND-001–003)
│   │   ├── FundingConfirmationService.php      (US-FUND-006; dispatches adapter)
│   │   ├── FundingCancellationService.php      (US-FUND-009)
│   │   ├── FundingLimitService.php             (US-FUND-004)
│   │   ├── FundingFeeService.php               (US-FUND-005)
│   │   ├── FundingCreditService.php            (US-FUND-008; postEntry() call)
│   │   ├── ProviderCallbackService.php         (US-FUND-007, 009, 010)
│   │   ├── BankNotificationService.php         (US-FUND-012)
│   │   ├── BankMatchingService.php             (US-FUND-012, 013)
│   │   ├── BankReconciliationService.php       (US-FUND-013)
│   │   └── AgentCashInService.php              (US-FUND-015–019)
│   └── Jobs/
│       └── FundingInitiationJob.php            (queued; calls FundingProviderAdapter::initiate())
├── Console/
│   └── ExpireStaleTransactionsCommand.php      (US-FUND-007 timeout; US-FUND-011 expiry)
├── Domain/
│   ├── Contracts/
│   │   ├── FundingModuleContract.php
│   │   └── FundingProviderAdapter.php
│   ├── Enums/
│   │   ├── FundingMethod.php
│   │   ├── FundingStatus.php
│   │   └── FundingFailureReason.php
│   ├── Events/
│   │   ├── FundingTransactionInitiated.php
│   │   ├── FundingTransactionConfirmed.php
│   │   ├── FundingTransactionCompleted.php
│   │   ├── FundingTransactionFailed.php
│   │   ├── FundingTransactionCancelled.php
│   │   ├── FundingTransactionExpired.php
│   │   ├── FundingTransactionAmountMismatch.php
│   │   ├── FundingTransactionLateCallback.php
│   │   ├── FundingAgentCashInCompleted.php
│   │   ├── FundingBankDepositReconciled.php
│   │   └── FundingUnmatchedDepositReceived.php
│   └── Exceptions/
│       ├── FundingTransactionNotFoundException.php
│       ├── FundingLimitExceededException.php
│       ├── InsufficientFloatException.php
│       ├── TransactionNotModifiableException.php
│       ├── DuplicateCallbackException.php
│       ├── FloatAccountNotConfiguredException.php
│       ├── FeeChangedException.php
│       └── BankReferenceGenerationException.php
├── Infrastructure/
│   ├── Adapters/
│   │   └── MockFundingProviderAdapter.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── FundingTransactionController.php
│   │   │   ├── ProviderCallbackController.php
│   │   │   ├── BankNotificationController.php
│   │   │   ├── AgentTransactionController.php
│   │   │   ├── FundingReceiptController.php
│   │   │   └── AdminUnmatchedDepositController.php
│   │   ├── Requests/
│   │   │   ├── InitiateFundingRequest.php
│   │   │   ├── ConfirmFundingRequest.php
│   │   │   ├── CancelFundingRequest.php
│   │   │   ├── ProviderCallbackRequest.php
│   │   │   ├── BankNotificationRequest.php
│   │   │   └── AgentCashInRequest.php
│   │   └── Resources/
│   │       ├── FundingTransactionResource.php
│   │       ├── FundingReceiptResource.php
│   │       └── UnmatchedDepositResource.php
│   └── Models/
│       ├── FundingTransactionModel.php
│       ├── FundingProviderCallbackModel.php
│       ├── FundingReceiptModel.php         (immutable — overrides save/delete)
│       ├── BankReconciliationModel.php
│       └── UnmatchedDepositModel.php
└── Providers/
    └── FundingServiceProvider.php
```

Routes file: `routes/modules/funding.php`

---

## Phase 0 — Research (Complete)

All technical unknowns resolved. See `research.md` for decisions on:
- DB connection strategy (§1)
- Transaction scope (§2)
- Provider callback inline vs queue (§3)
- FundingProviderAdapter pattern (§4)
- Agent PIN verification (§5)
- Customer pocket ledger reference (§6)
- Platform float account reference (§7)
- Agent float account reference convention (§8)
- postEntry() payload shape for digital credit and agent cash-in (§9)
- Bank reference generation algorithm (§10)
- Idempotency namespacing (§11)
- Expiry command scheduling (§12)
- Module structure (§13)
- Architecture test (§14)
- Testing structure (§15)
- Metrics pattern (§16)

---

## Phase 1 — Design (Complete)

Artifacts produced:
- `data-model.md` — 5 entities (`funding_transactions`, `funding_provider_callbacks`, `funding_receipts`, `bank_reconciliations`, `unmatched_deposits`), relationships, state machine, migration naming, indexes
- `contracts/funding-module-contract.php` — 2-method PHP interface (`getFundingTransaction`, `isFundingCompleted`)
- `contracts/funding-provider-adapter.php` — 2-method PHP interface (`initiate`, `verifyCallback`)
- `contracts/initiate-funding-request.php` — `POST /v1/funding/transactions` full request/response shapes
- `contracts/agent-cashin-request.php` — `POST /v1/funding/agent-transactions` full request/response shapes
- `contracts/api-contract.md` — all 12 REST endpoints with request/response shapes, error codes
- `quickstart.md` — 12 runnable validation scenarios mapped to SC-E08-001 through SC-E08-012

---

## Implementation Approach

1. **Foundation**: Migrations → Models → Enums → Exceptions → Domain events
2. **Module contract**: Define `FundingModuleContract` + `FundingProviderAdapter`; implement `FundingService` adapter; implement `MockFundingProviderAdapter`
3. **Application services**: Initiation → Limits → Fee → Confirmation → Credit posting → Cancellation → Callbacks → Bank matching → Agent cash-in
4. **Artisan command**: `ExpireStaleTransactionsCommand` (`funding:expire-stale`)
5. **HTTP layer**: Requests → Controllers → Resources → Routes → `FundingServiceProvider`
6. **Tests**: Unit (fee calc, limit check, bank reference gen, expiry) → Feature (HTTP endpoints, idempotency, callbacks, bank matching, agent cash-in) → Concurrency → Architecture
7. **E06 integration**: Add `getPocketLedgerAccountRef()` to `WalletModuleContract`; verify `FundingCreditService` can resolve pocket reference
8. **WalletModuleContract extension**: Document the new method in the E06 contract stub; actual implementation deferred to E06 patch task

---

## Data / Financial Controls

- **No direct wallet balance mutation**: All credits through `LedgerModuleContract::postEntry()` (constitution Principle 4)
- **No partial posting**: `FundingTransaction.status = completed` and `ledger_journal_id` committed atomically with the ledger journal
- **Immutable receipt**: `FundingReceipt` model overrides `save()` / `delete()` to throw `LogicException` after insert
- **Unique constraint race guard**: `funding_transactions.idempotency_key` UNIQUE + `IdempotencyService` fast path
- **Agent float overdraft guard**: `SELECT ... FOR UPDATE` on agent float ledger account inside the DB transaction (CC-E08-002)
- **Duplicate callback guard**: `IdempotencyService` keyed on `provider_reference_id`; `funding_provider_callbacks.provider_reference_id` index for lookup
- **Agent cash-in fee**: Always `fee_amount = 0` enforced in `AgentCashInService` before `postEntry()` (FR-E08-050)
- **Float account config fail-fast**: `FloatAccountNotConfiguredException` thrown if config key absent; validated at module boot

---

## Observability

- Correlation IDs propagated through all services and events
- Metrics: 9 counters/histograms (FR-E08-051) — `funding.transactions.initiated`, `funding.transactions.completed`, `funding.transactions.failed`, `funding.transactions.cancelled`, `funding.transactions.expired`, `funding.callbacks.processing.duration`, `funding.agent_cashin.posted`, `funding.bank.deposits.matched`, `funding.bank.deposits.unmatched`
- Audit events: every mutation writes to `platform.audit_events` (FR-E08-046)
- Outbox events: all 11 domain events emitted inside the same DB transaction as the triggering state change (FR-E08-044)

---

## Dependency Gaps

| Gap | Impact | Action in E08 |
|-----|--------|---------------|
| `WalletModuleContract::getPocketLedgerAccountRef()` missing | E08 cannot resolve customer pocket account reference without it | Add method stub to existing `WalletModuleContract` interface; E06 provides concrete implementation |
| `AuthModuleContract::verifyTransactionPin()` missing | Agent PIN verification unimplemented | Add method stub to existing `AuthModuleContract` interface; verify E01/E03 provides concrete implementation |
| `ledger.float_account.BWP` config value NULL (TBD-E07-001) | Credit posting fails at runtime | Boot-time validation; test with seeded mock float reference |
| Agent float account not pre-registered | First cash-in must call `registerAccount()` | `AgentCashInService` calls `LedgerModuleContract::registerAccount()` before `postEntry()` (idempotent) |

---

## Complexity Tracking

Any architecture deviation (e.g., switching to queued callback processing, adding a real provider adapter in E08, polling-based bank integration) requires an ADR and a constitution check update before implementation.
