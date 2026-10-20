# Tasks — E07 Wallet Ledger

**Input**: `specs/007-wallet-ledger/` (plan.md, spec.md, data-model.md, contracts/, research.md, quickstart.md)

**Prerequisites**: All E01–E06 implementations live; `platform` connection, `IdempotencyService`, `AuditService`, `OutboxEventPublisher`, `BaseIntegrationEvent`, E04 permission middleware all available.

**Tests**: Included — the spec explicitly mandates tests (CC-E07-004, FR-E07-046, SC-E07-001 through SC-E07-012).

**Organization**: Tasks grouped by user story. All 14 user stories (US-LEDGER-001 through US-LEDGER-014). P1 stories first; P2 stories last.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks in same phase)
- **[USN]**: Maps to user story N (US-LEDGER-00N in spec.md)
- All paths relative to `wallet-ecosystem/` (the operational Docker/dev source)

---

## Phase 1: Setup (Module Scaffolding)

**Purpose**: Create the Ledger module directory structure, service provider, routes, and config stub. Nothing compiles until this phase is done.

- [X] T001 Create module directory tree `app/Modules/Ledger/{Application/Services,Domain/Contracts,Domain/Enums,Domain/Events,Domain/Exceptions,Domain/Permissions,Infrastructure/Console/Commands,Infrastructure/Http/Controllers,Infrastructure/Http/Requests,Infrastructure/Http/Resources,Infrastructure/Migrations,Infrastructure/Models,Infrastructure/NullImplementations,Infrastructure/Repositories,Providers}` per `research.md §6`
- [X] T002 Create `app/Modules/Ledger/Providers/LedgerServiceProvider.php` with `register()` binding `LedgerModuleContract::class` → `LedgerService::class` (placeholder; real binding wired in Phase 10); `boot()` calling `loadMigrationsFrom()` and `loadRoutesFrom()` per `research.md §11`
- [X] T003 Register `LedgerServiceProvider::class` in `bootstrap/providers.php`
- [X] T004 Create `routes/modules/ledger.php` with placeholder comment block and route group `prefix('v1/ledger')->middleware(['identity.auth'])` — all route registrations added as stories are implemented
- [X] T005 Create `config/ledger.php` stub with key `ledger.system` array (all values `null`, `is_tbd = true`); entries: `float_account_ref`, `fee_account_ref`, `revenue_account_ref`, `commission_account_ref`, `suspense_account_ref` — TBD-E07-001 placeholder (no values invented)

**Checkpoint**: `php artisan route:list` runs without errors; `LedgerServiceProvider` boots cleanly.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Database schema, models, enums, exceptions, domain events, contract expansion, repositories, form requests, and HTTP resources that all user story phases depend on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T006 Create migration `app/Modules/Ledger/Infrastructure/Migrations/2026_09_17_001100_create_ledger_accounts_table.php` — connection=`ledger`; creates `ledger.ledger_accounts` with all columns; `owner_reference uuid NULLABLE` and `owner_type varchar(50) NULLABLE` (NULL = auto-registered without ownership context per FR-E07-003); UNIQUE on `ledger_account_reference`; INDEX on `(owner_reference, owner_type)` where `owner_reference IS NOT NULL`; INDEX on `(currency_code, status)` per `data-model.md §1`
- [X] T007 Create migration `app/Modules/Ledger/Infrastructure/Migrations/2026_09_17_001200_create_ledger_journals_table.php` — connection=`ledger`; creates `ledger.journals` with all columns, UNIQUE on `idempotency_key`, INDEX on `transaction_reference`, INDEX on `reversed_journal_id`, INDEX on `(entry_type, status)`, INDEX on `posted_at DESC`; NO `updated_at`; self-referential FK `reversed_journal_id → journals(id)` per `data-model.md §2`
- [X] T008 Create migration `app/Modules/Ledger/Infrastructure/Migrations/2026_09_17_001300_create_ledger_journal_lines_table.php` — connection=`ledger`; creates `ledger.journal_lines` with all columns, FK `journal_id → journals(id)`, FK `ledger_account_id → ledger_accounts(id)`, CHECK `amount > 0`, INDEX `(journal_id)`, INDEX `(ledger_account_id, direction, amount)`, INDEX `(ledger_account_id, currency_code)`; NO `updated_at` per `data-model.md §3`
- [X] T009 Create migration `app/Modules/Ledger/Infrastructure/Migrations/2026_09_17_001400_seed_ledger_permissions.php` — connection=`ledger`; seeds 5 permissions (`ledger.post`, `ledger.read`, `ledger.read.any`, `ledger.account.register`, `ledger.entry.reverse`) into E04 permission store with role assignments per spec §15
- [X] T010 Create migration `app/Modules/Ledger/Infrastructure/Migrations/2026_09_17_001500_seed_ledger_system_accounts_stub.php` — connection=`ledger`; inserts TBD-E07-001 config keys into `platform.configuration_values` with `value = null, is_tbd = true` for all 5 system account refs; adds seeder comment per FR-E07-005
- [X] T011 [P] Create `app/Modules/Ledger/Domain/Enums/AccountType.php` — backed string enum: `Asset = 'asset'`, `Liability = 'liability'`, `Income = 'income'`, `Expense = 'expense'`, `Equity = 'equity'`; add `normalBalance(): NormalBalance` method returning `credit` for liability/income/equity, `debit` for asset/expense
- [X] T012 [P] Create `app/Modules/Ledger/Domain/Enums/NormalBalance.php` — backed string enum: `Debit = 'debit'`, `Credit = 'credit'`
- [X] T013 [P] Create `app/Modules/Ledger/Domain/Enums/JournalEntryType.php` — backed string enum: `Debit`, `Credit`, `Fee`, `Revenue`, `Commission`, `Reversal`
- [X] T014 [P] Create `app/Modules/Ledger/Domain/Enums/JournalStatus.php` — backed string enum: `Posted = 'posted'`
- [X] T015 [P] Create `app/Modules/Ledger/Domain/Enums/JournalLineDirection.php` — backed string enum: `Debit = 'debit'`, `Credit = 'credit'`
- [X] T016 [P] Create `app/Modules/Ledger/Domain/Enums/LedgerAccountStatus.php` — backed string enum: `Active = 'active'`, `Closed = 'closed'`
- [X] T017 [P] Create all 6 domain exception classes in `app/Modules/Ledger/Domain/Exceptions/`: `UnbalancedJournalException.php`, `CurrencyMismatchException.php`, `InvalidAmountException.php`, `AccountClosedException.php`, `JournalNotFoundException.php`, `JournalAlreadyReversedException.php` — each extends `\RuntimeException`; each with a named constructor accepting a context message
- [X] T018 [P] Create `app/Modules/Ledger/Domain/Permissions/LedgerPermissions.php` — class with PHP constants: `POST = 'ledger.post'`, `READ = 'ledger.read'`, `READ_ANY = 'ledger.read.any'`, `ACCOUNT_REGISTER = 'ledger.account.register'`, `ENTRY_REVERSE = 'ledger.entry.reverse'`
- [X] T019 [P] Create `app/Modules/Ledger/Domain/Events/LedgerEntryPostedEvent.php` extending `BaseIntegrationEvent`; event name `Ledger.Entry.Posted`; payload: `journal_id`, `entry_type`, `currency_code`, `transaction_reference`, `actor_id` per spec §17
- [X] T020 [P] Create `app/Modules/Ledger/Domain/Events/LedgerEntryReversedEvent.php` extending `BaseIntegrationEvent`; event name `Ledger.Entry.Reversed`; payload: `journal_id` (reversal), `reversed_journal_id` (original), `actor_id`
- [X] T021 [P] Create `app/Modules/Ledger/Domain/Events/LedgerAccountRegisteredEvent.php` extending `BaseIntegrationEvent`; event name `Ledger.Account.Registered`; payload: `ledger_account_reference`, `account_type`, `currency_code`, `owner_type`
- [X] T022 [P] Create `app/Modules/Ledger/Domain/Events/LedgerImbalanceDetectedEvent.php` extending `BaseIntegrationEvent`; event name `Ledger.Imbalance.Detected`; payload: `currency_code`, `debit_total`, `credit_total`, `discrepancy`, `detected_at`
- [X] T023 Update `app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php` — add `registerAccount(string $ledgerAccountReference, string $accountType, string $currencyCode, string $ownerReference, string $ownerType): void` and `getAccountHistory(string $ledgerAccountReference, int $page, int $perPage): array` methods per FR-E07-043; match `contracts/ledger-module-contract.php` exactly
- [X] T024 Update `app/Modules/Ledger/Infrastructure/NullImplementations/NullLedgerModule.php` — add the 2 new methods each throwing `ModuleNotImplementedException` per FR-E07-043; all 5 methods now implemented (3 original + 2 new)
- [X] T025 Create `app/Modules/Ledger/Infrastructure/Models/LedgerAccountModel.php` — `connection = 'platform'`; `table = 'ledger.ledger_accounts'`; `$incrementing = false`; `$keyType = 'string'`; `$guarded = []`; casts for `account_type`, `normal_balance`, `status` enums; `$fillable` for all columns per `data-model.md §1`
- [X] T026 Create `app/Modules/Ledger/Infrastructure/Models/JournalModel.php` — `connection = 'platform'`; `table = 'ledger.journals'`; `$incrementing = false`; `$keyType = 'string'`; `$guarded = []`; NO `updated_at` (`const UPDATED_AT = null`); casts for `entry_type`, `status` enums; `hasMany(JournalLineModel::class)` relationship; cast `posted_at` as `datetime`
- [X] T027 Create `app/Modules/Ledger/Infrastructure/Models/JournalLineModel.php` — `connection = 'platform'`; `table = 'ledger.journal_lines'`; `$incrementing = false`; `$keyType = 'string'`; NO `updated_at` (`const UPDATED_AT = null`); override `save()` to throw `LogicException('JournalLine records are append-only')` when `$this->exists === true`; override `delete()` to always throw `LogicException('JournalLine records are immutable and cannot be deleted')` per FR-E07-012, SR-E07-005
- [X] T028 Create `app/Modules/Ledger/Infrastructure/Repositories/EloquentLedgerAccountRepository.php` — methods: `findByReference(string $ref): ?LedgerAccountModel`, `create(array $attributes): LedgerAccountModel`, `findOrAutoRegister(string $ref, string $currencyCode): LedgerAccountModel` (auto-register with `account_type = liability`, `normal_balance = credit`, `owner_reference = NULL`, `owner_type = NULL` per FR-E07-003; `currencyCode` used for the new account record)
- [X] T029 Create `app/Modules/Ledger/Infrastructure/Repositories/EloquentJournalRepository.php` — methods: `findById(string $id): ?JournalModel`, `findByIdOrFail(string $id): JournalModel`, `findByIdempotencyKey(string $key): ?JournalModel`, `findByTransactionReference(string $ref, int $page, int $perPage): array`, `create(array $attributes): JournalModel`; all queries use `DB::connection('platform')` with `ledger.*` table prefix
- [X] T030 Create `app/Modules/Ledger/Infrastructure/Http/Requests/PostEntryRequest.php` — FormRequest; validate: `currency_code` required string, `correlation_id` required string, `transaction_reference` nullable string, `description` nullable string, `lines` required array min:2, `lines.*.ledger_account_reference` required string, `lines.*.direction` required in:debit,credit, `lines.*.amount` required integer min:1; validates `Idempotency-Key` header present (returns 422 `MISSING_IDEMPOTENCY_KEY` if absent)
- [X] T031 Create `app/Modules/Ledger/Infrastructure/Http/Requests/RegisterAccountRequest.php` — FormRequest; validate: `ledger_account_reference` required string, `account_type` required in:asset,liability,income,expense,equity, `currency_code` required string, `owner_reference` required string uuid, `owner_type` required in:customer_pocket,system_float,system_revenue,system_fee,agent_commission,system_suspense; validates `Idempotency-Key` header
- [X] T031a Create `app/Modules/Ledger/Infrastructure/Http/Requests/ReverseJournalRequest.php` — FormRequest; validate: `description` nullable string max:500, `correlation_id` required string; validates `Idempotency-Key` header present (returns 422 `MISSING_IDEMPOTENCY_KEY` if absent); does NOT validate `lines` (reversal lines are built by `LedgerReverseService` from the original journal)
- [X] T032 [P] Create `app/Modules/Ledger/Infrastructure/Http/Resources/JournalResource.php` — JSON resource mapping `JournalModel` to API envelope per `contracts/api-contract.md §GET /v1/ledger/journals/{id}`; include embedded `JournalLineResource` collection in `lines`
- [X] T033 [P] Create `app/Modules/Ledger/Infrastructure/Http/Resources/JournalLineResource.php` — JSON resource mapping `JournalLineModel` to line object including `id`, `direction`, `amount`, `currency_code`, `ledger_account_reference`, `created_at`
- [X] T034 [P] Create `app/Modules/Ledger/Infrastructure/Http/Resources/LedgerAccountResource.php` — JSON resource mapping `LedgerAccountModel` to account object per `contracts/api-contract.md §POST /v1/ledger/accounts`

**Checkpoint**: `php artisan migrate` runs without errors; models instantiate; `LedgerModuleContract` has 5 methods; `NullLedgerModule` throws for all 5; PHPStan passes.

---

## Phase 3: US1 + US2 — Post Debit & Credit Journal Entry (P1)

**Goal**: Post a balanced debit or credit journal; idempotent replay returns original; unbalanced journals rejected; Ledger.Entry.Posted event emitted; audit event recorded.

**Independent Test**: `POST /v1/ledger/entries/debit` with balanced lines → HTTP 201, journal_id returned, `getBalance()` decreases, outbox event written. Replay with same Idempotency-Key → HTTP 200, same journal_id, no duplicate row.

- [X] T035 [US1] Create `app/Modules/Ledger/Application/Services/LedgerAccountService.php` — implement `registerAccount(string $ref, string $accountType, string $currencyCode, string $ownerRef, string $ownerType): LedgerAccountModel` using `EloquentLedgerAccountRepository::findOrAutoRegister()`; emit `LedgerAccountRegisteredEvent` via `OutboxEventPublisher` when a new account is created; write `platform.audit_events` via `AuditService` with `action = ledger.account.registered`; all within `DB::connection('platform')->transaction()` per `research.md §2`
- [X] T036 [US1] Create `app/Modules/Ledger/Application/Services/LedgerPostingService.php` — implement `post(array $request, string $actorId, string $entryType): string`; Step 1: `IdempotencyService::findCompletedResponse()` fast path; Step 2: validate lines (SUM debit == SUM credit, all amounts > 0, all lines same currency_code as request, at least one debit + one credit); Step 3: for each `ledger_account_reference`, call `LedgerAccountService::findOrAutoRegister()`; check account status (throw `AccountClosedException` if closed); Step 4: wrap in `DB::connection('platform')->transaction()` — insert `Journal`, insert all `JournalLine` records, call `OutboxEventPublisher::publish(new LedgerEntryPostedEvent(...))`, call `IdempotencyService::acquireLock()`, call `AuditService::record(action=ledger.entry.posted, ...)`; Step 5: `IdempotencyService::recordResponse()`; return `$journal->id`; catch unique constraint on `idempotency_key` and return existing journal per CC-E07-003 per `research.md §1, §2, §5`
- [X] T037 [US1] Create `app/Modules/Ledger/Infrastructure/Http/Controllers/LedgerEntryController.php` — thin controller; inject `LedgerPostingService`; method `debit(PostEntryRequest $request): JsonResponse` — extract actor from `$request->attributes->get('identity.user_id')`, call `LedgerPostingService::post()` with `entry_type = debit`, return 201 on new / 200 on replay per platform envelope; method `credit(PostEntryRequest $request): JsonResponse` — same pattern with `entry_type = credit`
- [X] T038 [US1] Add routes to `routes/modules/ledger.php`: `POST /v1/ledger/entries/debit` → `LedgerEntryController@debit` (middleware: `identity.require-permission:ledger.post`); `POST /v1/ledger/entries/credit` → `LedgerEntryController@credit` (same permission)
- [X] T039 [US1] Register `LedgerEntryController` routes in `LedgerServiceProvider::boot()`; verify `php artisan route:list | grep ledger` shows debit and credit routes
- [X] T040 [US1] Write feature test `tests/Feature/Modules/Ledger/PostDebitJournalTest.php` — tests: (a) balanced debit post → 201, journal_id returned, outbox event in `platform.outbox_events`; (b) same Idempotency-Key replayed → 200, same journal_id, COUNT(journals) = 1; (c) without Idempotency-Key header → 422 MISSING_IDEMPOTENCY_KEY; (d) without `ledger.post` permission → 403; covers US-LEDGER-001 acceptance scenarios 1–5
- [X] T041 [US2] Write feature test `tests/Feature/Modules/Ledger/PostCreditJournalTest.php` — tests: (a) balanced credit post → 201, `getBalance()` increases; (b) credit to zero-balance auto-registers account; (c) zero amount line → 422 INVALID_AMOUNT; (d) unknown currency_code → 422 CURRENCY_MISMATCH; (e) two distinct Idempotency-Keys with same payload → two separate journals; covers US-LEDGER-002 acceptance scenarios 1–5
- [X] T042 [US6] Write feature test `tests/Feature/Modules/Ledger/BalancedJournalEnforcementTest.php` — tests: (a) three-line journal (debit A 1000 + credit B 600 + credit C 400) → commits; (b) SUM(debits)=1000 vs SUM(credits)=999 → 422 UNBALANCED_JOURNAL, 0 lines persisted; (c) single debit no credit → 422 UNBALANCED_JOURNAL; (d) all amounts = 0 → 422 INVALID_AMOUNT; (e) 10 concurrent balanced posts → `ledger:check-balance` passes; covers US-LEDGER-006
- [X] T043 [US7] Write feature test `tests/Feature/Modules/Ledger/UnbalancedJournalRejectionTest.php` — tests: (a) debit_total 500 / credit_total 600 → 422 UNBALANCED_JOURNAL, 0 Journal rows; (b) empty lines array → 422 UNBALANCED_JOURNAL; (c) mixed currencies in lines → 422 CURRENCY_MISMATCH; (d) negative amount → 422 INVALID_AMOUNT; (e) crash after header insert mid-transaction → header rolled back, no orphan; covers US-LEDGER-007

**Checkpoint**: `php artisan test --filter PostDebit` and `--filter PostCredit` pass; debit/credit posting is fully operational.

---

## Phase 4: US3 — Post Fee Journal Entry (P1)

**Goal**: Fee journal entry posts successfully; customer pocket debited, fee account credited (both sides supplied by caller); `entry_type = fee` classification label stored.

**Independent Test**: `POST /v1/ledger/entries/fee` with balanced fee lines → HTTP 201; `entry_type = fee` in journal; atomic failure → neither line persisted.

- [X] T044 [US3] Add `fee(PostEntryRequest $request): JsonResponse` method to `LedgerEntryController` — delegates to `LedgerPostingService::post()` with `entry_type = fee`; same response pattern as debit/credit
- [X] T045 [US3] Add route to `routes/modules/ledger.php`: `POST /v1/ledger/entries/fee` → `LedgerEntryController@fee` (middleware: `identity.require-permission:ledger.post`)
- [X] T046 [US3] Write feature test `tests/Feature/Modules/Ledger/PostFeeJournalTest.php` — tests: (a) fee entry posts normally; (b) DB rollback → neither customer pocket debit nor fee credit persisted, outbox event not emitted; (c) unknown fee account reference → auto-registered, posts normally; (d) without `ledger.post` permission → 403; (e) zero amount → 422 INVALID_AMOUNT; covers US-LEDGER-003

**Checkpoint**: `POST /v1/ledger/entries/fee` returns 201 with `entry_type = fee`.

---

## Phase 5: US4 — Post Revenue Journal Entry (P1)

**Goal**: Revenue journal entry posts with `entry_type = revenue`; entry appears in account history.

**Independent Test**: `POST /v1/ledger/entries/revenue` → revenue account balance increases; entry visible in account history.

- [X] T047 [US4] Add `revenue(PostEntryRequest $request): JsonResponse` to `LedgerEntryController`; add `POST /v1/ledger/entries/revenue` route with `ledger.post` permission
- [X] T048 [US4] Write feature test `tests/Feature/Modules/Ledger/PostRevenueJournalTest.php` — tests: (a) revenue entry posts; `GET /v1/ledger/accounts/{ref}/entries` shows the revenue line; (b) unbalanced revenue entry → 422; (c) same Idempotency-Key replayed → original journal_id returned; (d) journal includes `transaction_reference` in response; (e) permission check; covers US-LEDGER-004

**Checkpoint**: Revenue posting live.

---

## Phase 6: US5 — Post Commission Journal Entry (P1)

**Goal**: Commission journal entry posts with `entry_type = commission`; commission account credited, source debited.

**Independent Test**: `POST /v1/ledger/entries/commission` → agent commission account balance increases; audit event written.

- [X] T049 [US5] Add `commission(PostEntryRequest $request): JsonResponse` to `LedgerEntryController`; add `POST /v1/ledger/entries/commission` route with `ledger.post` permission
- [X] T050 [US5] Write feature test `tests/Feature/Modules/Ledger/PostCommissionJournalTest.php` — tests: (a) commission entry posts; net balance zero across both accounts; (b) first commission to auto-registered agent account succeeds; (c) audit event contains `action = ledger.entry.posted`; (d) unbalanced commission → 422; (e) currency mismatch → 422 CURRENCY_MISMATCH; covers US-LEDGER-005

**Checkpoint**: All five posting entry types (debit, credit, fee, revenue, commission) are live.

---

## Phase 7: US8 — Ledger Entries Immutable (P1)

**Goal**: No application code path can update or delete a posted journal line or change a journal status; architecture test enforces module boundary.

**Independent Test**: Post a journal → call `save()` on existing `JournalLineModel` → `LogicException` thrown; call `delete()` → `LogicException`. Architecture test passes.

- [X] T051 [US8] Write unit test `tests/Unit/Modules/Ledger/JournalLineImmutabilityTest.php` — tests: (a) `save()` on existing JournalLineModel throws `LogicException`; (b) `delete()` always throws `LogicException`; (c) `save()` on new (not-yet-persisted) JournalLineModel does NOT throw (insert path); (d) Journal model `status` cannot be changed via direct save on existing record (throw `LogicException`); covers US-LEDGER-008 scenarios 1–3
- [X] T052 [US8] Write feature test `tests/Feature/Modules/Ledger/LedgerImmutabilityHttpTest.php` — tests: (a) no HTTP PATCH/PUT/DELETE endpoint exists for `/v1/ledger/entries/*` → 405 Method Not Allowed; (b) no HTTP PATCH/DELETE exists for `/v1/ledger/journals/*`; (c) attempt any write to `journal_lines` via Eloquent save → caught by application layer; covers US-LEDGER-008 scenarios 4–5
- [X] T053 [US8] Write architecture test `tests/Architecture/Modules/LedgerBoundaryTest.php` — Pest `arch()` assertion: `App\Modules\Ledger\Infrastructure\Models` (JournalModel, JournalLineModel, LedgerAccountModel) are NOT used in `App\Modules\Identity`, `App\Modules\Kyc`, `App\Modules\Platform`, `App\Modules\Wallet` or any other non-Ledger module namespace; per FR-E07-046, SC-E07-010

**Checkpoint**: `php artisan test --filter Immutability` and `--filter LedgerBoundary` pass.

---

## Phase 8: US9 — Reverse Entry via Compensating Journal (P1)

**Goal**: POST a reversal → new journal created with mirrored lines; original unchanged; `reversed_journal_id` set; `Ledger.Entry.Reversed` emitted; already-reversed rejection works.

**Independent Test**: POST reversal of journal J1 → new journal J2 has exact mirror lines; `getBalance()` restores pre-J1 values; second reversal of J1 → 422 JOURNAL_ALREADY_REVERSED.

- [X] T054 [US9] Create `app/Modules/Ledger/Application/Services/LedgerReverseService.php` — `reverse(string $journalId, string $actorId, string $idempotencyKey, string $correlationId, ?string $description): string`; Step 1: `IdempotencyService::findCompletedResponse()` fast path; Step 2: fetch original journal via `EloquentJournalRepository::findByIdOrFail()` (throw `JournalNotFoundException`); Step 3: check no reversal exists for this journal (query `reversed_journal_id = $journalId`, throw `JournalAlreadyReversedException`); Step 4: build mirror lines (swap direction on each original line); Step 5: wrap in `DB::connection('platform')->transaction()` — insert reversal `Journal` (`entry_type = reversal`, `reversed_journal_id = $journalId`), insert mirror `JournalLine` records, `OutboxEventPublisher::publish(new LedgerEntryReversedEvent(...))`, `AuditService::record(action=ledger.entry.reversed, ...)`, `IdempotencyService::acquireLock()`; return reversal journal ID
- [X] T055 [US9] Add `reverse(ReverseJournalRequest $request, string $id): JsonResponse` to `LedgerEntryController` — `Idempotency-Key` header validated by `ReverseJournalRequest`; inject `LedgerReverseService`; return 201 on new reversal / 200 on replay; map `JournalNotFoundException` → 404 `JOURNAL_NOT_FOUND`; `JournalAlreadyReversedException` → 422 `JOURNAL_ALREADY_REVERSED`
- [X] T056 [US9] Add route to `routes/modules/ledger.php`: `POST /v1/ledger/entries/{id}/reverse` → `LedgerEntryController@reverse` (middleware: `identity.require-permission:ledger.entry.reverse`)
- [X] T057 [US9] Write feature test `tests/Feature/Modules/Ledger/ReverseJournalTest.php` — tests: (a) reverse J1 → J2 has mirrored lines; J2.reversed_journal_id = J1.id; J1 unchanged; `getBalance()` for both accounts back to pre-J1 values; (b) second reversal of J1 → 422 JOURNAL_ALREADY_REVERSED; (c) without `ledger.entry.reverse` permission → 403; (d) same reversal Idempotency-Key replayed → 200, original reversal journal_id; (e) audit event `action = ledger.entry.reversed` contains both journal IDs and actor_id; covers US-LEDGER-009

**Checkpoint**: `POST /v1/ledger/entries/{id}/reverse` is live; reversal and idempotency work correctly.

---

## Phase 9: US12 — Calculate Balance from Ledger (P1)

**Goal**: `LedgerModuleContract::getBalance()` returns authoritative settled balance from journal lines; `entryExists()` works; E06 integration can drop `wallet.stub_ledger_balance`.

**Independent Test**: Post N journals to account R → `getBalance(R)` = SUM(credits) − SUM(debits). Unknown reference → 0. After reversal → net of both journals.

- [X] T058 [US12] Create `app/Modules/Ledger/Application/Services/LedgerBalanceService.php` — `getBalance(string $ledgerAccountReference): int`: find account by reference (return 0 if not found per FR-E07-022); compute balance via raw SQL `DB::connection('platform')->selectOne(...)` using direction-conditional `COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END), 0)` for normal_balance=credit (reverse for debit) per `research.md §4`; `entryExists(string $transactionReference): bool`: query `ledger.journals WHERE transaction_reference = ? AND status = 'posted'` via `DB::connection('platform')`
- [X] T059 [US12] Create `app/Modules/Ledger/Infrastructure/Http/Controllers/LedgerAccountController.php` — `balance(Request $request, string $reference): JsonResponse`: require `ledger.read` permission; IDOR check (unless actor has `ledger.read.any`, verify account owner_reference matches actor or return 404); call `LedgerBalanceService::getBalance()`; return platform envelope with `ledger_account_reference`, `currency_code`, `balance`, `normal_balance`, `computed_at`
- [X] T060 [US12] Add route to `routes/modules/ledger.php`: `GET /v1/ledger/accounts/{reference}/balance` → `LedgerAccountController@balance` (middleware: `identity.require-permission:ledger.read`)
- [X] T061 [US12] Create `app/Modules/Ledger/Application/LedgerService.php` (the `LedgerModuleContract` concrete adapter) — inject `LedgerPostingService`, `LedgerBalanceService`, `LedgerAccountService`; implement all 5 interface methods by delegating to the respective services; this is the class bound in `LedgerServiceProvider::register()`
- [X] T062 [US12] Update `LedgerServiceProvider::register()` — change placeholder binding to bind `LedgerModuleContract::class → LedgerService::class` (the concrete adapter from T061)
- [X] T063 [US12] Write feature test `tests/Feature/Modules/Ledger/CalculateBalanceTest.php` — tests: (a) credit 10000 + debit 3000 + credit 5000 + debit 1000 → `getBalance()` = 11000; (b) account with no lines → 0; (c) unknown reference → 0 (not 404); (d) after reversal, balance reflects both journals; (e) concurrent `getBalance()` with in-flight post → no dirty read (READ COMMITTED); covers US-LEDGER-012
- [X] T064 [US12] Remove `WALLET_STUB_LEDGER_BALANCE=true` from `phpunit.xml` (FR-E07-045, SC-E07-011); verify all E06 balance tests pass without the stub by running `php artisan test --filter WalletBalance`

**Checkpoint**: `LedgerModuleContract` binding is live; `getBalance()` returns real values; E06 stub removed from CI.

---

## Phase 10: US10 — Retrieve Wallet Ledger / Account History (P1)

**Goal**: `GET /v1/ledger/accounts/{reference}/entries` returns paginated journal lines in `posted_at` descending order; IDOR prevention works.

**Independent Test**: Post 3 journals against account R → retrieve entries → 3 lines returned with pagination meta.

- [X] T065 [US10] Implement `getAccountHistory(string $ref, int $page, int $perPage): array` in `LedgerAccountService` — query `ledger.journal_lines` joined with `ledger.journals` for the account reference, ordered by `posted_at DESC`, paginated; return `['data' => [...], 'meta' => ['total' => ..., 'page' => ..., 'per_page' => ..., 'pages' => ...]]` per `contracts/ledger-module-contract.php`; `LedgerService` adapter (T061) MUST inject `LedgerAccountService` and delegate `getAccountHistory()` to it
- [X] T066 [US10] Add `entries(Request $request, string $reference): JsonResponse` to `LedgerAccountController` — validate `per_page` max 100; IDOR check; call `getAccountHistory()`; return platform paginated envelope; each line includes `journal_id`, `entry_type`, `direction`, `amount`, `currency_code`, `transaction_reference`, `posted_at` per FR-E07-032
- [X] T067 [US10] Add route: `GET /v1/ledger/accounts/{reference}/entries` → `LedgerAccountController@entries` (middleware: `identity.require-permission:ledger.read`)
- [X] T068 [US10] Write feature test `tests/Feature/Modules/Ledger/AccountHistoryTest.php` — tests: (a) 20 lines, page=1, per_page=10 → 10 lines, meta.total=20, meta.pages=2; (b) account with 0 lines → empty array, meta.total=0, HTTP 200; (c) without `ledger.read` → 403; (d) actor without `ledger.read.any` querying non-owned account → 404 IDOR; (e) each line includes all required fields; covers US-LEDGER-010

**Checkpoint**: Account history pagination and IDOR prevention verified.

---

## Phase 11: US11 — Retrieve Transaction Ledger (P1)

**Goal**: `GET /v1/ledger/journals?transaction_reference=X` returns all journals for that reference; `GET /v1/ledger/journals/{id}` returns full journal with lines.

**Independent Test**: POST journal with `transaction_reference = TXN-001` → query by reference → journal + all lines returned.

- [X] T069 [US11] Create `app/Modules/Ledger/Infrastructure/Http/Controllers/LedgerJournalController.php` — `index(Request $request): JsonResponse`: require `transaction_reference` query param; query `EloquentJournalRepository::findByTransactionReference()`; return paginated journals with embedded lines; `show(Request $request, string $id): JsonResponse`: find journal by ID (throw `JournalNotFoundException` → 404); return full journal with all lines via `JournalResource`
- [X] T070 [US11] Add routes: `GET /v1/ledger/journals/{id}` → `LedgerJournalController@show`; `GET /v1/ledger/journals` → `LedgerJournalController@index`; both with middleware `identity.require-permission:ledger.read`
- [X] T071 [US11] Write feature test `tests/Feature/Modules/Ledger/TransactionLedgerQueryTest.php` — tests: (a) two journals with same transaction_reference → both returned; (b) unknown transaction_reference → empty array, HTTP 200; (c) without `ledger.read` → 403; (d) transaction reference spanning original + reversal → both journals returned; (e) pagination params honored; (f) `GET /v1/ledger/journals/{id}` returns all line fields; covers US-LEDGER-011

**Checkpoint**: Journal queries by ID and transaction_reference are live.

---

## Phase 12: US1b — Register Ledger Account Endpoint (P1, FR-E07-025)

**Goal**: `POST /v1/ledger/accounts` enables explicit account registration before first posting; idempotent re-registration with same params is a no-op.

**Independent Test**: `POST /v1/ledger/accounts` → 201; replay with same Idempotency-Key → 200, same account; replay with conflicting account_type → 409.

- [X] T072 Add `register(RegisterAccountRequest $request): JsonResponse` to `LedgerAccountController` — call `LedgerAccountService::registerAccount()`; return 201 on new / 200 on idempotent replay; map conflict → 409 per `contracts/api-contract.md`
- [X] T073 Add route: `POST /v1/ledger/accounts` → `LedgerAccountController@register` (middleware: `identity.require-permission:ledger.account.register`)
- [X] T074 Write feature test `tests/Feature/Modules/Ledger/RegisterAccountTest.php` — tests: (a) new registration → 201, account fields returned; (b) same Idempotency-Key → 200, same account record; (c) conflicting account_type → 409; (d) without `ledger.account.register` permission → 403; (e) `Ledger.Account.Registered` event in outbox

**Checkpoint**: All 11 API endpoints are live and verified by feature tests.

---

## Phase 13: US13 — Detect Ledger Imbalance (P2)

**Goal**: `ledger:check-balance` verifies per-currency balance; emits `Ledger.Imbalance.Detected` on discrepancy; exits 0 on clean ledger.

**Independent Test**: Run on clean ledger → exit 0. Inject orphan debit line (test helper) → command detects imbalance, emits event, exits non-zero.

- [X] T075 [US13] Create `app/Modules/Ledger/Infrastructure/Console/Commands/CheckLedgerBalanceCommand.php` — Artisan command `ledger:check-balance`; Step 1: query `ledger.journal_lines` grouped by `currency_code` using per-currency SUM (direction-conditional) per `research.md §10`; Step 2: for each currency where debit_total ≠ credit_total: call `OutboxEventPublisher::publish(new LedgerImbalanceDetectedEvent(...))` (in a `platform` transaction), `AuditService::record(action=ledger.imbalance.detected, ...)`, emit CRITICAL structured log; Step 3: return exit code 0 if no imbalances, non-zero if any; command is READ-ONLY — no writes to `journal_lines` per FR-E07-037
- [X] T076 [US13] Register `CheckLedgerBalanceCommand` in `LedgerServiceProvider::boot()` via `$this->commands([CheckLedgerBalanceCommand::class])`; add `Schedule` callback hook for TBD-E07-004 cadence (registered but cadence left null/TBD — do not hardcode a schedule)
- [X] T077 [US13] Write feature test `tests/Feature/Modules/Ledger/LedgerBalanceCheckCommandTest.php` — tests: (a) clean ledger → exits 0, no `Ledger.Imbalance.Detected` outbox event; (b) manually insert orphan debit line via `DB::connection('platform')->table('ledger.journal_lines')->insert(...)` → command detects imbalance, emits event, exits non-zero, logs CRITICAL; (c) run command twice → same result, idempotent; (d) no deadlock with concurrent posting during check (use parallel test helper); (e) no auto-correction applied after imbalance detected; covers US-LEDGER-013

**Checkpoint**: `php artisan ledger:check-balance` exits 0 on clean database.

---

## Phase 14: US14 — Generate Ledger Audit Trail (P2)

**Goal**: Every ledger mutation (post, reverse, register, imbalance) produces a `platform.audit_events` entry with actor_id, resource_id, correlation_id, occurred_at.

**Independent Test**: POST a journal → reverse it → query `platform.audit_events` → two events found: `ledger.entry.posted` and `ledger.entry.reversed`.

- [X] T078 [US14] Write feature test `tests/Feature/Modules/Ledger/AuditTrailTest.php` — tests: (a) journal post → `platform.audit_events` row with `action = ledger.entry.posted`, `actor_id`, `resource_id = journal_id`, `correlation_id`, `occurred_at`; (b) reversal → row with `action = ledger.entry.reversed`, both journal IDs in metadata; (c) account registration → `action = ledger.account.registered`, account reference in metadata; (d) imbalance detection → `action = ledger.imbalance.detected`, severity = CRITICAL in metadata; (e) verify all audit events contain non-null `actor_id`, `correlation_id`; covers US-LEDGER-014
- [X] T079 [US14] Verify no PII in ledger audit payloads — review `AuditService` calls in `LedgerPostingService`, `LedgerReverseService`, `LedgerAccountService`, `CheckLedgerBalanceCommand`; assert `owner_reference` (UUID) is used, never name/email/national ID; per SR-E07-007

**Checkpoint**: All audit events verified; no PII found in ledger payloads.

---

## Phase 15: Polish & Cross-Cutting Concerns

**Purpose**: Concurrency tests, architecture integrity test, PHPStan, metrics, E06 integration verification, and spec artifacts update.

- [X] T080 [P] Write concurrency test `tests/Concurrency/Modules/Ledger/ConcurrentIdempotentPostTest.php` — 10 goroutines posting same Idempotency-Key simultaneously → exactly 1 journal record in `ledger.journals`; 9 callers return idempotent replay response; verifies CC-E07-003, SC-E07-007(a)
- [X] T081 [P] Write concurrency test `tests/Concurrency/Modules/Ledger/ConcurrentDistinctPostTest.php` — 10 concurrent posts with distinct Idempotency-Keys to same pair of accounts → all 10 commit; `getBalance()` reflects exact aggregate sum; no double-counting; `ledger:check-balance` exits 0; verifies CC-E07-001, SC-E07-007(b)
- [X] T082 [P] Write concurrency test `tests/Concurrency/Modules/Ledger/ConcurrentBalanceQueryTest.php` — concurrent `getBalance()` calls during in-flight postings → all returned values are valid pre- or post-posting committed states; no dirty read observed; verifies CC-E07-002
- [X] T083 Run PHPStan at level 8 on `app/Modules/Ledger/` — `./vendor/bin/phpstan analyse app/Modules/Ledger --level=8`; fix all errors before marking complete
- [X] T084 [P] Verify `tests/Architecture/Modules/LedgerBoundaryTest.php` (T053) passes in CI — `php artisan test tests/Architecture/Modules/LedgerBoundaryTest.php`; fix any boundary violation found; SC-E07-010
- [X] T085 [P] Write E06 integration test `tests/Integration/Ledger/WalletLedgerIntegrationTest.php` — seed E06 wallet + pocket with `ledger_account_reference`; post credits via E07 `LedgerModuleContract`; call E06 `GET /v1/wallets/{id}/pockets/{currency}` → `ledger_balance` reflects real E07 balance; `available_balance` computed correctly; SC-E07-008
- [X] T086 [P] Verify `WALLET_STUB_LEDGER_BALANCE` absent from `phpunit.xml` (completed in T064) and run full E06 test suite to confirm no regressions: `php artisan test tests/Feature/Modules/Wallet/`; SC-E07-011
- [X] T087 Verify metrics emission in `LedgerPostingService` and `LedgerBalanceService` — `ledger.entries.posted` counter (tagged `entry_type`) emitted on each commit; `ledger.balance.query.duration` histogram recorded in `LedgerBalanceService::getBalance()`; `ledger.imbalance.detected` counter in `CheckLedgerBalanceCommand`; `ledger.reversals.posted` counter in `LedgerReverseService`; per FR-E07-047, SC-E07-012
- [X] T088 [P] Run full ledger feature test suite and ensure all 12 success criteria from spec.md §20 are verifiably met: `php artisan test tests/Feature/Modules/Ledger/`; document pass/fail against SC-E07-001 through SC-E07-012 in checklist
- [X] T089 Update `specs/007-wallet-ledger/checklists/requirements.md` — mark any remaining unchecked items; add post-implementation notes confirming SC-E07-001 through SC-E07-012 and all FRs covered by tests
- [X] T090 [P] Write feature test `tests/Feature/Modules/Ledger/MultiCurrencyPostingTest.php` — for each of BWP, USD, EUR, ZAR, CNY, USDT: (a) register two accounts (`ledger.post` + `ledger.account.register`), (b) post a balanced credit entry with that `currency_code`, (c) assert `getBalance()` returns the posted amount, (d) assert `ledger:check-balance` exits 0 with zero imbalance for all 6 currencies after all postings complete; verifies SC-E07-006 (no currency-specific code paths in `LedgerPostingService` or `LedgerBalanceService`)
- [X] T091 [P] Write unit test `tests/Unit/Modules/Ledger/EntryExistsTest.php` covering FR-E07-027: (a) `entryExists('TXN-001')` returns `true` when a `posted` journal with `transaction_reference = 'TXN-001'` exists; (b) returns `false` for an unknown reference; (c) returns `true` when the original journal exists and a reversal journal also exists for the same reference (original entry still counts as posted — documents authoritative semantics for E11 callers)
- [X] T092 [P] Guard the internal posting contract against `entry_type = reversal` — add an early guard in `app/Modules/Ledger/Application/Services/LedgerPostingService.php` and in the `postEntry()` method of `app/Modules/Ledger/Application/LedgerService.php` that throws `InvalidArgumentException` / returns 422 `INVALID_ENTRY_TYPE` if `entry_type = reversal` is received (`PostEntryRequest` is NOT the correct layer — entry_type for HTTP calls is URL-path-encoded, not a body field); add a comment to `JournalEntryType::Reversal` in `app/Modules/Ledger/Domain/Enums/JournalEntryType.php` marking it "internal only — not accepted via postEntry()"; add a unit test asserting the rejection via `LedgerPostingService::post()`; resolves FR-E07-044 vs enum inconsistency
- [X] T093 [P] Guard `LedgerReverseService` against re-reversing a reversal journal and add the corresponding test — (a) update `app/Modules/Ledger/Application/Services/LedgerReverseService.php` Step 3: after fetching the journal, add a guard `if ($journal->entry_type === JournalEntryType::Reversal) throw new JournalAlreadyReversedException(...)` before the existing `reversed_journal_id` lookup (the existing lookup only detects if a journal *has been* reversed, not if it *is itself* a reversal); (b) add test case to `tests/Feature/Modules/Ledger/ReverseJournalTest.php`: given J1 reversed to produce J2, attempt `POST /v1/ledger/entries/{J2.id}/reverse` → assert HTTP 422 `JOURNAL_ALREADY_REVERSED`; verifies FR-E07-031 for the reversal journal itself
- [ ] T094 [P] Re-run PHPStan level 8 and full ledger test suite after T091–T093 post-analyze implementation — `./vendor/bin/phpstan analyse app/Modules/Ledger --level=8`; then `php artisan test tests/Unit/Modules/Ledger/ tests/Feature/Modules/Ledger/`; verify 0 PHPStan errors and all T091–T093 test cases pass; mark [X] when clean

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all user story phases
- **Phase 3+ (User Stories)**: All depend on Phase 2 completion
  - P1 stories (Phases 3–12) can proceed in priority order or partially in parallel after Phase 2
  - Phase 3 (posting core) MUST complete before Phases 4–6 (fee/revenue/commission share `LedgerPostingService`)
  - Phase 8 (reversal) MUST complete before Phase 9 (balance references reversal journals)
  - Phase 8 (reversal) MUST complete before Phase 14 (audit trail references reversal events)
  - Phase 9 (balance) MUST complete before Phase 12 (register endpoint needs full `LedgerAccountController`)
  - Phases 13–14 (P2 stories) can start after Phases 3–12 (P1) are done
- **Phase 15 (Polish)**: Depends on all P1+P2 story phases

### User Story Dependencies

| Story | Phase | Depends On |
|---|---|---|
| US1 + US2 (post debit/credit) | 3 | Phase 2 |
| US6 + US7 (balance enforcement) | 3 | Phase 2 (validation within LedgerPostingService) |
| US3 (fee) | 4 | Phase 3 (LedgerPostingService) |
| US4 (revenue) | 5 | Phase 3 |
| US5 (commission) | 6 | Phase 3 |
| US8 (immutability) | 7 | Phase 2 (model tests), Phase 3 (arch test) |
| US9 (reversal) | 8 | Phase 2 (JournalModel, repositories) |
| US12 (balance) | 9 | Phase 2, Phase 3 (binding LedgerService) |
| US10 (account history) | 10 | Phase 9 (LedgerBalanceService) |
| US11 (transaction query) | 11 | Phase 2 (repositories) |
| US1b (register endpoint) | 12 | Phase 2 (LedgerAccountService) |
| US13 (imbalance detection) | 13 | Phase 2 (migrations) |
| US14 (audit trail) | 14 | Phase 3, 8, 13 |

### Parallel Opportunities

Within Phase 2: T006–T010 (migrations), T011–T022 (enums + events), T023–T024 (contract update), T025–T027 (models), T028–T034 (repos + requests + resources) can all run in parallel.

Within Phase 3: T035–T036 (LedgerAccountService + LedgerPostingService) must be sequential (service uses account service); T040–T043 (tests) can run in parallel after T039.

---

## Parallel Examples

### Phase 2 Parallel Batch

```bash
# All of these can run simultaneously:
Task T006: Create ledger_accounts migration
Task T007: Create journals migration
Task T008: Create journal_lines migration
Task T011: Create AccountType enum
Task T012: Create NormalBalance enum
Task T013: Create JournalEntryType enum
Task T017: Create all 6 exception classes
Task T018: Create LedgerPermissions constants
Task T019: Create LedgerEntryPostedEvent
Task T020: Create LedgerEntryReversedEvent
Task T021: Create LedgerAccountRegisteredEvent
Task T022: Create LedgerImbalanceDetectedEvent
Task T032: Create JournalResource
Task T033: Create JournalLineResource
Task T034: Create LedgerAccountResource
```

### Phase 15 Parallel Batch

```bash
# All of these can run simultaneously after story phases complete:
Task T080: ConcurrentIdempotentPostTest
Task T081: ConcurrentDistinctPostTest
Task T082: ConcurrentBalanceQueryTest
Task T083: PHPStan analysis
Task T084: Architecture boundary test
Task T085: E06 integration test
Task T086: E06 regression suite
```

---

## Implementation Strategy

### MVP First (US1 + US2 + US12 + US8)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: US1 + US2 (debit/credit posting)
4. Complete Phase 9: US12 (balance — so `LedgerModuleContract` binding goes live)
5. Complete Phase 7: US8 (immutability tests)
6. **STOP AND VALIDATE**: `LedgerModuleContract` is live; E06 `wallet.stub_ledger_balance` can be removed; core ledger is operational

### Incremental Delivery After MVP

7. Phase 4: Fee entries
8. Phase 5: Revenue entries
9. Phase 6: Commission entries
10. Phase 8: Reversal
11. Phase 10: Account history
12. Phase 11: Transaction ledger query
13. Phase 12: Account registration endpoint
14. Phase 13: Imbalance detection (P2)
15. Phase 14: Audit trail verification (P2)
16. Phase 15: Polish, concurrency, PHPStan

---

## Notes

- `[P]` = different files, no dependencies within the same phase — safe to parallelize
- All database writes go through `DB::connection('platform')` with explicit `ledger.*` table prefixes — see `research.md §1`
- Migrations use `protected $connection = 'ledger'` — see `research.md §8`
- No TBD values may be invented; system account references in `config/ledger.php` remain `null` until TBD-E07-001 is resolved
- JournalLine `delete()` always throws; `save()` throws on `$this->exists === true` — both tested in T051
- `wallet.stub_ledger_balance` removed from `phpunit.xml` in T064; verify E06 tests in T086
- Architecture test (T053 / T084) must remain GREEN at all times; never reference Ledger models from outside `app/Modules/Ledger/`
