# Tasks: Provider Adapter Foundation (E1000)

**Input**: Design documents from `specs/1000-provider-adapter-foundation/`

**Prerequisites**: plan.md ✓ | spec.md ✓ | research.md ✓ | data-model.md ✓ | contracts/provider-adapter-contracts.md ✓ | quickstart.md ✓

**Tests**: Included — all contracts require compliance tests and financial-safety invariants require unit test coverage (SC-E1000-009).

**Organization**: Tasks are grouped by user story to enable independent implementation and testing. All new files are additive to `app/Modules/Platform/` — zero existing files modified except one additive registration in `PlatformServiceProvider.php`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared dependencies)
- **[Story]**: Which user story this task belongs to (US1–US5)
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Confirm directory scaffolding and namespace registration for the new Provider namespace under Platform Foundation.

- [X] T001 Create directory tree `app/Modules/Platform/Domain/Contracts/Providers/`, `Domain/Enums/`, `Domain/Exceptions/`, `Domain/ValueObjects/Providers/`, `Infrastructure/Providers/`, `tests/Unit/Platform/Providers/`, `tests/Integration/Platform/` — these directories are created implicitly when files are added; confirm `.gitkeep` is NOT needed (Laravel autoloads by PSR-4 namespace, directories must exist before `composer dump-autoload`)

- [X] T002 Verify that `composer.json` PSR-4 autoload already covers `App\Modules\Platform\Domain\Enums\`, `App\Modules\Platform\Domain\Exceptions\`, and `App\Modules\Platform\Domain\ValueObjects\Providers\` under the existing `App\\` namespace mapping; no `composer.json` change is needed if mapping is `"App\\": "app/"` (confirm and document)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Enums and exceptions that ALL interfaces, value objects, and services depend on. No user story can be fully implemented until this phase completes.

**⚠️ CRITICAL**: Phases 3–7 cannot begin until all foundational types exist and pass static analysis.

- [X] T003 Create `app/Modules/Platform/Domain/Enums/ProviderErrorCode.php` — PHP 8.1 backed string enum with cases: INSUFFICIENT_FUNDS, PROVIDER_UNAVAILABLE, INVALID_REFERENCE, INVALID_DESTINATION, TIMEOUT, RATE_LIMITED, CREDENTIAL_REJECTED, CONTENT_REJECTED, UNKNOWN; add `isTransient(): bool` helper method returning true for PROVIDER_UNAVAILABLE/TIMEOUT/RATE_LIMITED, false for all others (research §R-002, data-model §ProviderErrorCode)

- [X] T004 [P] Create `app/Modules/Platform/Domain/Enums/ProviderStatus.php` — PHP 8.1 backed string enum with cases: UP, DEGRADED, DOWN, UNKNOWN, SUCCESS, FAILURE (data-model §ProviderStatus)

- [X] T005 [P] Create `app/Modules/Platform/Domain/Enums/ReconciliationState.php` — PHP 8.1 backed string enum with cases: PENDING, MATCHED, UNMATCHED, EXCEPTION (data-model §ReconciliationState)

- [X] T006 [P] Create `app/Modules/Platform/Domain/Enums/ProviderCapabilityEnum.php` — PHP 8.1 backed string enum with cases: InitiatePayment, QueryBalance, ReceiveWebhook, ReversePriorPayment, DeliverOtp, VerifyIdentity, ScreenSanctions, ScoreRisk, GenerateUtilityToken, QueryTransactionStatus; string values are snake_case (research §R-002, data-model §ProviderCapabilityEnum)

- [X] T007 [P] Create `app/Modules/Platform/Domain/Exceptions/ProviderException.php` — extends `\RuntimeException`; message constructor param; no additional fields (data-model §Exceptions)

- [X] T008 [P] Create `app/Modules/Platform/Domain/Exceptions/WebhookSignatureException.php` — extends `ProviderException`; thrown by `ProviderWebhookContract::parse()` when signature is invalid or missing (data-model §Exceptions, contracts §6)

- [X] T009 [P] Create `app/Modules/Platform/Domain/Exceptions/ImmutableReferenceViolationException.php` — extends `\DomainException`; message must include the `provider_key` and attempted-write context; thrown by `ExternalReferenceMappingService::confirmProviderReference()` when `provider_reference` is already set (data-model §Exceptions, research §R-003)

**Checkpoint**: All four enums and three exceptions exist and can be imported. Run `./vendor/bin/phpstan analyse app/Modules/Platform/Domain/Enums app/Modules/Platform/Domain/Exceptions` with zero errors before proceeding.

---

## Phase 3: User Story 1 — Provider Adapter Contract Compliance (Priority: P1) 🎯 MVP

**Goal**: Define `ProviderAdapterInterface`, `ProviderCapabilityInterface`, `ProviderHealthInterface`, all required value objects, and the `NullProviderAdapter` test double. Downstream epic engineers can implement a concrete adapter and inject it into any domain service test.

**Independent Test**: `./vendor/bin/pest tests/Unit/Platform/Providers/` — all US1 tests pass; `NullProviderAdapter` satisfies all interface obligations; no network calls made.

### Value Objects (US1 prerequisites)

- [X] T010 [US1] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ProviderError.php` — PHP 8.4 readonly class; constructor: `ProviderErrorCode $normalizedCode`, `string $providerSpecificCode`, `bool $isTransient`, `string $message`; `isTransient` MUST match `$normalizedCode->isTransient()` if derived from enum — add `self::fromCode(ProviderErrorCode $code, string $rawCode, string $message): self` named constructor that sets `isTransient` automatically; throw `\InvalidArgumentException` if `$message` is empty (data-model §ProviderError, contracts §1)

- [X] T011 [P] [US1] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ProviderCapabilitySet.php` — PHP 8.4 readonly class wrapping a deduplicated `ProviderCapabilityEnum[]`; methods: `has(ProviderCapabilityEnum $cap): bool`, `all(): array`, `isEmpty(): bool`, `count(): int`; named constructors: `::of(ProviderCapabilityEnum ...$caps): self` (deduplicates), `::empty(): self` (data-model §ProviderCapabilitySet)

- [X] T012 [P] [US1] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ProviderHealthStatus.php` — PHP 8.4 readonly class; fields: `ProviderStatus $status`, `\DateTimeImmutable $lastCheckedAt`, `?string $reason`; named constructors: `::up(\DateTimeImmutable $checkedAt): self`, `::degraded(string $reason, \DateTimeImmutable $checkedAt): self`, `::down(string $reason, \DateTimeImmutable $checkedAt): self`, `::unknown(): self` (uses `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))` for lastCheckedAt) (data-model §ProviderHealthStatus)

- [X] T013 [P] [US1] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ProviderRequest.php` — PHP 8.4 readonly class; fields: `string $platformEntityId`, `string $platformEntityType`, `string $operationType`, `string $correlationId`, `?\App\Modules\Platform\Domain\ValueObjects\Money $amount`, `?\App\Modules\Platform\Domain\ValueObjects\Currency $currency`, `array $metadata`; named constructors: `::forFinancialOperation(Money $amount, Currency $currency, string $entityId, string $entityType, string $opType, string $correlationId, array $metadata = []): self` (throws `\InvalidArgumentException` if amount is zero), `::forNonFinancialOperation(string $entityId, string $entityType, string $opType, string $correlationId, array $metadata = []): self`; metadata blocklist check in constructor: throw `\InvalidArgumentException` if any metadata key is in `['api_key','secret','password','token','credential','private_key']` (data-model §ProviderRequest, research §R-008)

- [X] T014 [P] [US1] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ProviderResponse.php` — PHP 8.4 readonly class; fields: `ProviderStatus $status`, `?string $providerReference`, `?\App\Modules\Platform\Domain\ValueObjects\Money $amount`, `?\App\Modules\Platform\Domain\ValueObjects\Currency $currency`, `?\DateTimeImmutable $providerTimestamp`, `?ProviderError $error`, `string $rawProviderStatusCode`, `string $correlationId`; named constructors: `::success(string $providerRef, string $rawCode, string $correlationId, ?Money $amount = null, ?Currency $currency = null, ?\DateTimeImmutable $ts = null): self`, `::failure(ProviderError $error, string $rawCode, string $correlationId): self`; throw `\InvalidArgumentException` if `$correlationId` is empty (data-model §ProviderResponse)

### Contract Interfaces (US1)

- [X] T015 [US1] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderCapabilityInterface.php` — single method: `public function getCapabilities(): ProviderCapabilitySet;` (contracts §2)

- [X] T016 [P] [US1] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderHealthInterface.php` — single method: `public function getHealthStatus(): ProviderHealthStatus;`; PHPDoc MUST state: "Must NOT make a live provider API call. Returns ::unknown() as the safe initial state." (contracts §3)

- [X] T017 [US1] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderAdapterInterface.php` — methods: `send(ProviderRequest $request): ProviderResponse`, `getCapabilities(): ProviderCapabilitySet`, `getProviderIdentifier(): string`; PHPDoc on `send()` MUST enumerate all implementor obligations: emit audit event, emit metrics, normalize errors, no credentials in response, throw `ProviderException` only for unrecoverable/un-normalizable errors (contracts §1)

### Test Double (US1)

- [X] T018 [US1] Create `app/Modules/Platform/Infrastructure/Providers/NullProviderAdapter.php` — `final class NullProviderAdapter implements ProviderAdapterInterface, ProviderHealthInterface`; constructor: `private readonly ProviderResponse $responseToReturn`, `private readonly ProviderCapabilitySet $capabilities`, `private readonly string $providerIdentifier = 'null'`; named constructors: `::alwaysSucceed(ProviderCapabilitySet $caps): self` (creates a ProviderResponse::success with providerReference='null-ref'), `::alwaysFail(ProviderError $error, ProviderCapabilitySet $caps): self`; `send()` returns `$this->responseToReturn` without any network call; `getHealthStatus()` always returns `ProviderHealthStatus::up(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))`; PHPDoc: "MUST NOT be registered as the live container binding in production." (contracts §NullProviderAdapter, research §R-007)

### Tests (US1)

- [X] T019 [P] [US1] Create `tests/Unit/Platform/Providers/ProviderErrorTest.php` — test: `fromCode()` sets `isTransient` correctly for all transient and permanent codes; test: empty `$message` throws `\InvalidArgumentException`; test: `providerSpecificCode` is preserved verbatim (spec §SC-E1000-009)

- [X] T020 [P] [US1] Create `tests/Unit/Platform/Providers/ProviderCapabilitySetTest.php` — test: `::of()` deduplicates identical capabilities; test: `has()` returns true/false correctly; test: `::empty()` produces `isEmpty() === true` and `count() === 0`; test: `::of()` with all ten capability enum cases (spec §SC-E1000-009)

- [X] T021 [P] [US1] Create `tests/Unit/Platform/Providers/ProviderHealthStatusTest.php` — test: each named constructor sets correct status and lastCheckedAt; test: `::unknown()` has non-null lastCheckedAt; test: `::down()` requires non-empty reason (spec §SC-E1000-009)

- [X] T022 [P] [US1] Create `tests/Unit/Platform/Providers/ProviderRequestTest.php` — test: `::forFinancialOperation()` with zero amount throws `\InvalidArgumentException`; test: metadata blocklist rejects keys `['api_key','secret','password','token']`; test: non-financial constructor sets amount/currency to null; test: empty correlationId on `::forFinancialOperation()` throws `\InvalidArgumentException` (spec §FR-E1000-025, §SC-E1000-009)

- [X] T023 [P] [US1] Create `tests/Unit/Platform/Providers/ProviderResponseTest.php` — test: `::success()` sets status to SUCCESS and error to null; test: `::failure()` sets status to FAILURE and error to the given ProviderError; test: empty correlationId throws `\InvalidArgumentException` (spec §SC-E1000-009)

- [X] T024 [US1] Create `tests/Unit/Platform/Providers/NullProviderAdapterTest.php` — test: `::alwaysSucceed()` returns the configured response on `send()`; test: `::alwaysFail()` returns a failure response; test: `getProviderIdentifier()` returns `'null'`; test: `getCapabilities()` returns the configured capability set; test: `getHealthStatus()` returns UP; test: `send()` does NOT make any HTTP call (verify by asserting no Guzzle/Http client calls via spy or by checking no HTTP client is injected) (spec §SC-E1000-007, quickstart §Scenario 1)

**Checkpoint**: `./vendor/bin/pest tests/Unit/Platform/Providers/` — all T019–T024 pass. US1 independently complete.

---

## Phase 4: User Story 2 — Provider External Reference Mapping (Priority: P1)

**Goal**: Define `ExternalReferenceMapping` entity, `platform.provider_external_references` migration, `ProviderExternalReferenceModel`, `ProviderReconciliationContract`, and `ExternalReferenceMappingService`. Financial operations can create speculative records, confirm provider references, and query by provider reference or platform entity.

**Independent Test**: `./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php` — speculative creation, one-time-write enforcement, surrogate reference creation, all query methods; plus concurrency test passes.

### Domain Entity (US2)

- [X] T025 [US2] Create `app/Modules/Platform/Domain/ValueObjects/Providers/ExternalReferenceMapping.php` — PHP 8.4 readonly class (domain entity, not Eloquent); fields: `string $id`, `string $platformEntityId`, `string $platformEntityType`, `string $providerKey`, `?string $providerReference`, `?\DateTimeImmutable $referenceConfirmedAt`, `string $operationType`, `ReconciliationState $reconciliationState`, `?string $reconciliationReason`, `?string $reconciliationOwner`, `?\DateTimeImmutable $reconciledAt`, `\DateTimeImmutable $createdAt`; static factory `::fromArray(array $row): self` for hydration from model (data-model §ExternalReferenceMapping entity)

### Migration and Eloquent Model (US2)

- [X] T026 [P] [US2] Create `app/Modules/Platform/Infrastructure/Migrations/2026_09_01_001000_create_provider_external_references.php` — `$connection = 'platform'`; `Schema::connection('platform')->create('platform.provider_external_references', ...)` with all columns from data-model §platform.provider_external_references; unique index on `(provider_key, provider_reference)` using a partial index excluding NULLs: `$table->rawIndex("(provider_key, provider_reference) WHERE provider_reference IS NOT NULL", 'provider_external_refs_key_ref_unique')`; **speculative idempotency unique index** (FR-E1000-026): `$table->rawIndex("(platform_entity_id, platform_entity_type, provider_key, operation_type) WHERE reconciliation_state = 'PENDING'", 'provider_external_refs_speculative_unique')` — this MUST be a UNIQUE index (use `DB::statement()` with `CREATE UNIQUE INDEX` if `rawIndex()` does not support UNIQUE partial indexes in the Laravel version); composite index on `(platform_entity_id, platform_entity_type)`; partial index on `(reconciliation_state, created_at)` where state='PENDING'; partial index on `reconciliation_state` where state='EXCEPTION' (data-model §platform.provider_external_references, research §R-003)

- [X] T027 [P] [US2] Create `app/Modules/Platform/Infrastructure/Persistence/ProviderExternalReferenceModel.php` — Eloquent model; `$connection = 'platform'`; `$table = 'platform.provider_external_references'`; `$keyType = 'string'`; `$incrementing = false`; cast `reconciliation_state` to `ReconciliationState`; `$guarded = ['provider_reference', 'reference_confirmed_at']` to prevent mass-assignment of immutable fields; `$casts` for all timestamp fields to `'immutable_datetime'`; `toEntity(): ExternalReferenceMapping` method using `ExternalReferenceMapping::fromArray($this->toArray())` (data-model §ExternalReferenceMapping, research §R-006)

### Reconciliation Contract (US2 prerequisite for US5)

- [X] T028 [P] [US2] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderReconciliationContract.php` — interface with three methods: `getState(string $providerKey, string $providerReference): ReconciliationState`, `match(string $providerKey, string $providerReference): void`, `exception(string $providerKey, string $providerReference, string $reason, string $owner): void`; PHPDoc on `match()` MUST state: "Valid source states: PENDING, EXCEPTION (when reconciliation_reason and reconciliation_owner are already set). Throws \DomainException if record is already MATCHED. Throws \DomainException if source is EXCEPTION and required fields are null. MATCHED is terminal within E1000."; PHPDoc on `exception()` MUST state: "Valid source states: PENDING, EXCEPTION (idempotent re-set). Prohibited: MATCHED. Throws \InvalidArgumentException if \$reason or \$owner is empty — no-silent-resolution rule (OKI-ARCH-2026-003 §10). Throws \DomainException if record is in MATCHED state."; PHPDoc on the interface MUST include: "The UNMATCHED state is reserved for future reconciliation epics. No method in this interface transitions a record to UNMATCHED." (contracts §7, spec §FR-E1000-007)

### Application Service (US2)

- [X] T029 [US2] Create `app/Modules/Platform/Application/Services/ExternalReferenceMappingService.php` — implements `ProviderReconciliationContract`; constructor: inject `\Illuminate\Database\ConnectionInterface` (or `ProviderExternalReferenceModel` for queries); implement all public methods from `contracts/provider-adapter-contracts.md §ExternalReferenceMappingService`:
  - `createSpeculative(string $platformEntityId, string $platformEntityType, string $providerKey, string $operationType): ExternalReferenceMapping` — idempotent find-or-create: first attempts to find an existing PENDING record for `(platformEntityId, platformEntityType, providerKey, operationType)`; if found, returns it; if not found, inserts a new row with `provider_reference = NULL`, `reconciliation_state = PENDING`; if a concurrent insert races and hits the partial unique constraint violation, catches the exception and re-queries to return the winner's record
  - `confirmProviderReference(string $id, string $providerReference, \DateTimeImmutable $confirmedAt): ExternalReferenceMapping` — checks existing `provider_reference !== null` → throw `ImmutableReferenceViolationException`; checks global uniqueness of `(provider_key, providerReference)` → throw `ImmutableReferenceViolationException` on duplicate; atomically updates `provider_reference` and `reference_confirmed_at`
  - `createWithSurrogateReference(...)` — generates UUID v7 surrogate, persists, emits audit warning via `AuditServiceInterface`
  - `findByProviderReference(string $providerKey, string $providerReference): ?ExternalReferenceMapping`
  - `findByPlatformEntity(string $platformEntityId, string $platformEntityType): array`
  - `findStalePending(\DateInterval $threshold): array`
  - `findAllExceptions(): array`
  - `getState(...)` — throws `\DomainException` if record not found
  - `match(...)` — sets `reconciled_at = now(UTC)`; valid source states: PENDING, EXCEPTION; throws `\DomainException` if already MATCHED; throws `\DomainException` if source is EXCEPTION and `reconciliation_reason` or `reconciliation_owner` is null on the record
  - `exception(...)` — requires non-empty reason and owner (throws `\InvalidArgumentException`); valid source states: PENDING, EXCEPTION; throws `\DomainException` if record is MATCHED

### Service Registration (US2)

- [X] T030 [US2] Additive registration in `app/Modules/Platform/Providers/PlatformServiceProvider.php` — add `$this->app->singleton(ExternalReferenceMappingService::class)` in the existing `register()` method; add `$this->app->bind(ProviderReconciliationContract::class, ExternalReferenceMappingService::class)`; do NOT modify any other existing binding (plan §Phase 2 — one permitted additive change)

### Tests (US2)

- [X] T031 [P] [US2] Create `tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php` — use in-memory SQLite or `RefreshDatabase` trait; test: `createSpeculative()` inserts row with `provider_reference = NULL`; **test: `createSpeculative()` called twice with identical parameters returns the existing PENDING record (idempotent — no duplicate row inserted)**; test: first `confirmProviderReference()` sets `provider_reference` and `reference_confirmed_at`; test: second `confirmProviderReference()` on same record throws `ImmutableReferenceViolationException`; test: `confirmProviderReference()` with a `providerReference` already used by another record (same provider_key) throws `ImmutableReferenceViolationException`; test: `findByProviderReference()` returns the entity after confirmation; test: `findByPlatformEntity()` returns all records for entity; test: `findStalePending()` returns only PENDING records older than threshold; test: `findAllExceptions()` returns only EXCEPTION records; test: `match()` transitions PENDING→MATCHED and sets `reconciled_at`; test: `match()` on already MATCHED record throws `\DomainException`; test: `match()` on EXCEPTION record without reason/owner throws `\DomainException`; test: `exception()` with empty reason throws `\InvalidArgumentException`; test: `exception()` with empty owner throws `\InvalidArgumentException`; test: `exception()` on a MATCHED record throws `\DomainException`; test: `getState()` throws `\DomainException` for non-existent pair; test: `createWithSurrogateReference()` creates record with non-null `provider_reference` and emits audit warning (spec §SC-E1000-009, §FR-E1000-026, quickstart §Scenarios 2 and 5)

- [X] T032 [US2] Create `tests/Integration/Platform/ExternalReferenceMappingConcurrencyTest.php` — uses `RefreshDatabase` and real PostgreSQL; **test 1 — confirmProviderReference() race**: two concurrent `confirmProviderReference()` calls for the same `(provider_key, providerReference)` — one succeeds, the other throws `ImmutableReferenceViolationException`; verify exactly one row with the given pair exists after both calls complete; **test 2 — createSpeculative() race (FR-E1000-026)**: two concurrent `createSpeculative()` calls with identical `(platform_entity_id, platform_entity_type, provider_key, operation_type)` — both must return the same PENDING record; verify exactly one row exists; verify no exception escapes to the caller (the service catches the unique-constraint violation internally and returns the winning row); use PHP `pcntl_fork()` or Laravel parallel test capabilities for both tests (spec §SC-E1000-008, §FR-E1000-026, quickstart §Scenario 6)

**Checkpoint**: `./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php tests/Integration/Platform/ExternalReferenceMappingConcurrencyTest.php` — all pass. US2 independently complete.

---

## Phase 5: User Story 3 — Webhook Normalization (Priority: P2)

**Goal**: Define `NormalizedWebhookEvent` and `ProviderWebhookContract`. Concrete adapter implementors can normalize provider webhooks into a platform-neutral event shape; E25 can call `parse()` without knowing the provider wire format.

**Independent Test**: `./vendor/bin/pest tests/Unit/Platform/Providers/NormalizedWebhookEventTest.php` — idempotency key generation, null platformEntityId on unresolved, raw payload preserved, signature exception thrown.

### Value Object and Contract (US3)

- [X] T033 [US3] Create `app/Modules/Platform/Domain/ValueObjects/Providers/NormalizedWebhookEvent.php` — PHP 8.4 readonly class; fields: `?string $platformEntityId`, `?string $platformEntityType`, `string $providerKey`, `string $providerReference`, `string $normalizedEventType`, `ProviderStatus $status`, `?\App\Modules\Platform\Domain\ValueObjects\Money $amount`, `?\App\Modules\Platform\Domain\ValueObjects\Currency $currency`, `?\DateTimeImmutable $providerTimestamp`, `string $correlationId`, `string $idempotencyKey`, `string $rawPayload`, `\DateTimeImmutable $receivedAt`; all fields are constructor-promoted readonly; throw `\InvalidArgumentException` if `$idempotencyKey` or `$rawPayload` is empty; throw `\InvalidArgumentException` if `$correlationId` is empty (data-model §NormalizedWebhookEvent)

- [X] T034 [P] [US3] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderWebhookContract.php` — single method: `parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent`; PHPDoc MUST enumerate all obligations: validate signature first (throw `WebhookSignatureException` on failure), preserve `$rawPayload` verbatim, set `platformEntityId = null` on unresolved lookup (not throw), generate `idempotencyKey` from the provider-supplied notification ID (if available) or a deterministic hash of the raw payload and a stable implementation-defined provider key (e.g., `hash('sha256', $rawPayload . $providerKey)`; implementations that also satisfy `ProviderAdapterInterface` MAY use `$this->getProviderIdentifier()` — this interface does not declare that method), MUST NOT log credentials/OTP/PII from payload (contracts §6)

### Tests (US3)

- [X] T035 [P] [US3] Create `tests/Unit/Platform/Providers/NormalizedWebhookEventTest.php` — test: constructor throws `\InvalidArgumentException` for empty `idempotencyKey`; test: `rawPayload` is preserved verbatim in the created object; test: `platformEntityId = null` is a valid construction (unresolved scenario); test: empty `correlationId` throws `\InvalidArgumentException`; test: all required fields are present in constructed event (spec §FR-E1000-019, quickstart §Scenario 3)

- [X] T036 [US3] Create `tests/Unit/Platform/Providers/ProviderWebhookContractTest.php` — use a test double implementing `ProviderWebhookContract`; test: invalid signature causes `WebhookSignatureException`; test: duplicate webhook (same idempotency key) when processed twice via `IdempotencyServiceInterface` produces one queued event (spy on `IdempotencyServiceInterface`); test: unresolved `ExternalReferenceMapping` lookup produces `NormalizedWebhookEvent` with `platformEntityId = null` rather than throwing (spec §SC-E1000-004, quickstart §Scenario 3)

**Checkpoint**: `./vendor/bin/pest tests/Unit/Platform/Providers/NormalizedWebhookEventTest.php tests/Unit/Platform/Providers/ProviderWebhookContractTest.php` — all pass. US3 independently complete.

---

## Phase 6: User Story 4 — Provider Health and Routing Seams (Priority: P2)

**Goal**: Define `ProviderRoutingInterface` and `ProviderFailoverInterface`. Routing implementations can select healthy, capable providers; failover implementations can step through a chain after transient errors.

**Independent Test**: `./vendor/bin/pest tests/Unit/Platform/Providers/ProviderRoutingInterfaceTest.php` — DOWN provider never selected, incapable provider never selected, failover chain exhaustion returns null.

### Contract Interfaces (US4)

- [X] T037 [US4] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderRoutingInterface.php` — single method: `selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface`; PHPDoc: "MUST NOT select a provider with ProviderHealthStatus::DOWN or whose ProviderCapabilitySet does not contain the operationType from \$context. MUST throw ProviderException('No eligible provider') if no eligible candidate exists." (contracts §4)

- [X] T038 [P] [US4] Create `app/Modules/Platform/Domain/Contracts/Providers/ProviderFailoverInterface.php` — two methods: `shouldFailover(ProviderError $error): bool` (returns true only if `$error->isTransient === true`), `selectFallback(ProviderAdapterInterface $current, array $candidates, ProviderRequest $context): ?ProviderAdapterInterface` (returns null if chain exhausted); PHPDoc obligations: MUST NOT return `$current`, MUST NOT return a DOWN or incapable provider, returns null when exhausted (contracts §5)

### Tests (US4)

- [X] T039 [P] [US4] Create `tests/Unit/Platform/Providers/ProviderRoutingInterfaceTest.php` — use two `NullProviderAdapter` instances; test: adapter with `ProviderHealthStatus::DOWN` is never returned by a reference `OrderedRoutingStrategy` implementation; test: adapter with `ProviderCapabilitySet::empty()` is never selected for an `InitiatePayment` request; test: when no eligible provider exists, `ProviderException` is thrown; test: when one DOWN and one UP capable adapter exist, the UP capable adapter is selected; the reference `OrderedRoutingStrategy` is a minimal concrete implementation created inline in the test to prove the interface is implementable (spec §SC-E1000-001, quickstart §Scenario 4)

- [X] T040 [US4] Create `tests/Unit/Platform/Providers/ProviderFailoverInterfaceTest.php` — use `NullProviderAdapter` instances; test: `shouldFailover()` returns true for `ProviderErrorCode::TIMEOUT` (transient); test: `shouldFailover()` returns false for `ProviderErrorCode::INVALID_REFERENCE` (permanent); test: `selectFallback()` never returns `$current`; test: `selectFallback()` returns null when all candidates are equal to `$current` or DOWN/incapable; test: chain of three adapters — first fails transiently, second fails transiently, third succeeds — verify selection sequence is correct; use a reference `OrderedFailoverStrategy` minimal implementation inline in the test (spec §SC-E1000-001, quickstart §Scenario 4)

**Checkpoint**: `./vendor/bin/pest tests/Unit/Platform/Providers/ProviderRoutingInterfaceTest.php tests/Unit/Platform/Providers/ProviderFailoverInterfaceTest.php` — all pass. US4 independently complete.

---

## Phase 7: User Story 5 — Reconciliation State Machine (Priority: P3)

**Goal**: Validate and harden the full reconciliation state machine in `ExternalReferenceMappingService`. All state transition rules, exception requirements, and no-silent-resolution constraints are covered.

**Independent Test**: `./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php --filter=reconciliation` — all reconciliation transitions and exception validations pass.

- [X] T041 [US5] Extend `tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php` with reconciliation-specific test cases (add as additional `describe()` block within the same file): test: PENDING→MATCHED via `match()` sets `reconciled_at` to current UTC; test: PENDING→EXCEPTION via `exception()` requires non-empty reason + owner; test: EXCEPTION→MATCHED via `match()` requires that both `reconciliation_reason` and `reconciliation_owner` are already set on the record (throws `\DomainException` if not); test: MATCHED→EXCEPTION is prohibited — calling `exception()` on a MATCHED record throws `\DomainException`; test: MATCHED→MATCHED is prohibited — calling `match()` on an already MATCHED record throws `\DomainException`; test: no-silent-resolution — `exception()` with empty reason throws `\InvalidArgumentException`; test: no-silent-resolution — `exception()` with empty owner throws `\InvalidArgumentException`; test: `getState()` throws `\DomainException` for non-existent `(providerKey, providerReference)` pair; **NOTE**: PENDING→UNMATCHED is NOT tested here — UNMATCHED semantics are deferred to E20; the `UNMATCHED` enum value exists but no E1000 service method transitions a record to it (spec §FR-E1000-007, spec §Assumptions UNMATCHED deferral)

- [X] T042 [P] [US5] Create `tests/Unit/Platform/Providers/ReconciliationStateTransitionTest.php` — test ALL E1000-managed transitions defined in data-model §Reconciliation state transitions (E1000-managed): PENDING→MATCHED (via `match()`), PENDING→EXCEPTION (via `exception()`), EXCEPTION→MATCHED (via `match()` with required fields present); test: MATCHED→EXCEPTION is PROHIBITED — verify `exception()` on a MATCHED record throws `\DomainException`; test: MATCHED→MATCHED is PROHIBITED — verify `match()` on a MATCHED record throws `\DomainException`; **NOTE**: PENDING→UNMATCHED and UNMATCHED→EXCEPTION are NOT tested — these transitions are deferred to E20 per spec §FR-E1000-007 and are outside E1000 scope (spec §US5, data-model §Reconciliation state transitions (E1000-managed), spec §Assumptions UNMATCHED deferral)

**Checkpoint**: `./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php tests/Unit/Platform/Providers/ReconciliationStateTransitionTest.php` — all pass. US5 independently complete.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Static analysis gate, cross-module dependency verification, full regression, quickstart validation.

- [X] T043 Run PHPStan static analysis on all new E1000 files: `./vendor/bin/phpstan analyse app/Modules/Platform/Domain/Contracts/Providers app/Modules/Platform/Domain/Enums app/Modules/Platform/Domain/Exceptions app/Modules/Platform/Domain/ValueObjects/Providers app/Modules/Platform/Application/Services/ExternalReferenceMappingService.php app/Modules/Platform/Infrastructure/Providers/NullProviderAdapter.php app/Modules/Platform/Infrastructure/Persistence/ProviderExternalReferenceModel.php --level=max` — zero errors required (spec §SC-E1000-006, quickstart §Scenario 7)

- [X] T044 [P] Run static dependency analysis to verify zero cross-module imports: no file under `app/Modules/Platform/Domain/Contracts/Providers/`, `Domain/Enums/`, `Domain/Exceptions/`, or `Domain/ValueObjects/Providers/` imports any class from `App\Modules\Identity`, `App\Modules\Wallet`, `App\Modules\Ledger`, or any other non-Platform module namespace; use `grep -r "use App\\\\Modules\\\\" app/Modules/Platform/Domain/Contracts/Providers app/Modules/Platform/Domain/Enums app/Modules/Platform/Domain/Exceptions app/Modules/Platform/Domain/ValueObjects/Providers | grep -v "App\\\\Modules\\\\Platform"` — must return zero results (spec §SC-E1000-010, quickstart §Scenario 7)

- [X] T045 [P] Run full Pest regression suite: `./vendor/bin/pest --stop-on-failure` — all existing E999 tests pass, plus all new E1000 tests; total passing count increases by the number of new test cases; zero regressions (spec §SC-E1000-009, quickstart §Scenario 8)

- [X] T046 Validate quickstart scenarios 1–8 per `specs/1000-provider-adapter-foundation/quickstart.md` — confirm each scenario's "Expected outcome" is met; document actual test output for each scenario as evidence for the convergence gate

- [X] T047 [P] Create `tests/Unit/Platform/Providers/CredentialExposureTest.php` — automated secret-scanning Pest test (spec §SC-E1000-005); using Pest's `expect()` and PHP file-scanning utilities, scan all files produced by E1000 (under `app/Modules/Platform/Domain/Contracts/Providers/`, `Domain/Enums/`, `Domain/Exceptions/`, `Domain/ValueObjects/Providers/`, `Application/Services/ExternalReferenceMappingService.php`, `Infrastructure/Providers/NullProviderAdapter.php`, `Infrastructure/Persistence/ProviderExternalReferenceModel.php`) for the following patterns that would indicate a credential exposure: literal strings matching `/\b(api[_-]?key|password|secret|token|credential|private[_-]?key)\s*=\s*['"][^'"]{4,}/i`, any hard-coded bearer token (`/Bearer\s+[A-Za-z0-9\-_]{10,}/`), any hard-coded basic-auth (`/Basic\s+[A-Za-z0-9+\/]{10,}/`); each scan MUST produce zero matches; test failure message MUST name the file and matching line; this test uses only PHP built-in `file_get_contents()` and `preg_match_all()` — no new framework required (spec §SC-E1000-005, constitution §P6)

---

## Phase 9: Convergence

**Source**: `speckit-converge` audit — 2026-08-27. Findings represent contradictions and partial gaps between the implementation and the authoritative contracts doc + data model. All tasks in this phase MUST be completed before re-running `/speckit-converge`.

**Note on F1/F2 (routing/failover)**: The implementation chose string-based provider keys over adapter-object parameters to avoid circular dependency at the container boundary. This is an architectural choice. The task is to align the code to the authoritative contracts doc. If the string-key approach is preferred, an ADR amendment must be raised and approved FIRST before diverging from the contract — do not invent a deviation without governance approval.

- [X] T048 Align `ProviderRoutingInterface::selectProvider()` to the authoritative signature: change from `(ProviderCapabilitySet $requiredCapabilities, array $context = []): string` to `(ProviderRequest $context, array $candidates): ProviderAdapterInterface` in `app/Modules/Platform/Domain/Contracts/Providers/ProviderRoutingInterface.php`; update all inline test implementations in `tests/Unit/Platform/Providers/ProviderRoutingInterfaceTest.php` to match the new signature per FR-E1000-004 (contradicts)

- [X] T049 Align `ProviderFailoverInterface` to the authoritative signatures: (a) `shouldFailover(ProviderError $error): bool` — remove the extra `string $providerKey` first parameter; (b) `selectFallback(ProviderAdapterInterface $current, array $candidates, ProviderRequest $context): ?ProviderAdapterInterface` — replace string-key parameters with adapter-object parameters and change return type from `string` to `?ProviderAdapterInterface`; update both method signatures in `app/Modules/Platform/Domain/Contracts/Providers/ProviderFailoverInterface.php` and all inline implementations in `tests/Unit/Platform/Providers/ProviderFailoverInterfaceTest.php` per FR-E1000-005 (contradicts)

- [X] T050 Restore `$signature` parameter to `ProviderWebhookContract::parse()`: change from `(string $rawPayload, array $headers, string $correlationId): NormalizedWebhookEvent` to `(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent` in `app/Modules/Platform/Domain/Contracts/Providers/ProviderWebhookContract.php`; update PHPDoc obligations and all inline test implementations in `tests/Unit/Platform/Providers/ProviderWebhookContractTest.php` to pass `$signature` and use `$headers` for correlation context per FR-E1000-006 (contradicts)

- [X] T051 Complete `NormalizedWebhookEvent` to match data model: (a) add 7 missing fields — `platformEntityType: string|null`, `providerKey: string`, `status: ProviderStatus`, `amount: Money|null`, `currency: Currency|null`, `providerTimestamp: DateTimeImmutable|null`, `receivedAt: DateTimeImmutable`; (b) rename `eventType` → `normalizedEventType` and `occurredAt` → `providerTimestamp` (merge with the added field); (c) update `::resolved()` and `::unresolved()` named constructors to include all new fields; (d) update `tests/Unit/Platform/Providers/NormalizedWebhookEventTest.php` to cover the new fields and renamed properties per FR-E1000-008, data-model.md (partial)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user story phases
- **US1 (Phase 3)**: Depends on Foundational — can start after T003–T009 complete
- **US2 (Phase 4)**: Depends on Foundational — can run in parallel with US1 once Foundation is complete
- **US3 (Phase 5)**: Depends on US1 (needs ProviderResponse, ProviderStatus, ExternalReferenceMapping) and US2 (needs ExternalReferenceMappingService for lookup)
- **US4 (Phase 6)**: Depends on US1 (needs ProviderAdapterInterface, ProviderRequest, ProviderError, ProviderCapabilitySet, ProviderHealthStatus, NullProviderAdapter)
- **US5 (Phase 7)**: Depends on US2 (reconciliation state machine is in ExternalReferenceMappingService)
- **Polish (Phase 8)**: Depends on all user story phases completing

### User Story Dependencies

| User Story | Priority | Can Start After | Dependencies |
|---|---|---|---|
| US1 — Adapter Contract | P1 | Phase 2 complete | Foundational enums + exceptions |
| US2 — External References | P1 | Phase 2 complete | Foundational enums + exceptions; can run parallel with US1 |
| US3 — Webhook Normalization | P2 | US1 + US2 complete | ProviderResponse, ProviderStatus (US1); ExternalReferenceMappingService (US2) |
| US4 — Routing Seams | P2 | US1 complete | ProviderAdapterInterface, NullProviderAdapter (US1) |
| US5 — Reconciliation | P3 | US2 complete | ExternalReferenceMappingService (US2) |

### Within Each Phase

- All [P]-marked tasks in the same phase operate on different files and can run in parallel
- All non-[P] tasks depend on the completion of preceding tasks in their phase
- Test tasks can be written in parallel with implementation tasks in the same phase

---

## Parallel Execution Examples

### Phase 2 — Foundational (all run in parallel after T002)

```text
T003 ProviderErrorCode enum
T004 ProviderStatus enum          [P]
T005 ReconciliationState enum     [P]
T006 ProviderCapabilityEnum       [P]
T007 ProviderException            [P]
T008 WebhookSignatureException    [P]
T009 ImmutableReferenceViolation  [P]
```

### Phase 3 — US1 Value Objects (all parallel)

```text
T010 ProviderError VO
T011 ProviderCapabilitySet VO   [P]
T012 ProviderHealthStatus VO    [P]
T013 ProviderRequest VO         [P]
T014 ProviderResponse VO        [P]
```

### Phases 3 + 4 — US1 and US2 in parallel (after Foundation)

```text
Developer A: T010–T024 (US1 interfaces, value objects, test double, tests)
Developer B: T025–T032 (US2 entity, migration, model, service, tests)
```

---

## Implementation Strategy

### MVP First (US1 + US2 — both P1)

1. Complete Phase 1: Setup (T001–T002)
2. Complete Phase 2: Foundational (T003–T009)
3. Complete Phase 3: US1 (T010–T024)
4. Complete Phase 4: US2 (T025–T032)
5. **STOP and VALIDATE**: Run quickstart Scenarios 1, 2, 6; confirm SC-E1000-001 through SC-E1000-008
6. Downstream epics (E05, E08, etc.) can now begin planning against E1000 contracts

### Incremental Delivery

1. Foundation (T001–T009) → compile gate passes
2. US1 (T010–T024) → adapter contract usable by downstream epic test doubles
3. US2 (T025–T032) → financial reference tracking operational
4. US3 (T033–T036) → webhook normalization contract available for E25
5. US4 (T037–T040) → routing/failover seams available for OtpDeliveryRouter (ADR-009)
6. US5 (T041–T042) → reconciliation state machine complete for E20 planning
7. Polish (T043–T047) → convergence gate passes

### Notes

- [P] tasks = different files, no shared state dependencies
- [Story] label traces each task to its user story for `/speckit-converge` gap analysis
- Never modify any existing file in `app/Modules/Platform/` except PlatformServiceProvider.php (T030 — one additive `singleton()` call only)
- Provider identity, timeout values, and retry counts MUST remain TBD — do not invent any configuration value
- `NullProviderAdapter` MUST carry the PHPDoc warning about production use in every commit; do not remove it
