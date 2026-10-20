# Research — E05 KYC & Identity Verification

**Date**: 2026-09-16
**Based on**: Codebase exploration of `wallet-ecosystem/services/api/app/Modules/` — Platform, Identity, Profile, Kyc stub, and config/database.php.

---

## 1. kyc Database Connection

**Decision**: Use existing `DB::connection('kyc')`.

The `database.php` already includes `'kyc' => pgsql_config('kyc')`. This sets `search_path = 'kyc'` on the same shared PostgreSQL instance used by all modules. No new connection configuration is needed.

**Rationale**: Follows the same pattern as every other module (identity, profile, wallet, ledger, etc.). All tables in this module use the `kyc.` schema prefix.

---

## 2. PiiMasker — hashNationalId() is Missing

**Finding (CRITICAL)**: `PiiMasker` (`app/Modules/Platform/Infrastructure/Security/PiiMasker.php`) has `maskNationalId()` (displays last 4 digits) but does NOT have `hashNationalId()`.

**Decision**: Add `PiiMasker::hashNationalId(string $id): string` using HMAC-SHA256 with `config('app.key')` as the secret — same approach as the existing `hashIp()` method.

```php
public static function hashNationalId(string $id): string
{
    if ($id === '') {
        return '';
    }
    return hash_hmac('sha256', $id, (string) config('app.key'));
}
```

**Rationale**: Consistent with `hashIp()`. The platform secret (`app.key`) is already used for IP hashing; using it for national ID hashing maintains the same security model. The hash is deterministic so duplicate detection works correctly.

**Where called**: `KycCaseService::capturePersonalInformation()` immediately on receipt — before any persistence.

---

## 3. File Storage Abstraction

**Decision**: Define a module-local `KycDocumentStorageContract` interface following the `ProfilePhotoStorageContract` pattern.

**Reference**: `app/Modules/Profile/Domain/Contracts/ProfilePhotoStorageContract.php` — single-method `store()` returning a `PhotoStorageResult`. E05 adapts this for KYC documents.

```php
interface KycDocumentStorageContract
{
    public function store(string $caseId, string $documentType, mixed $file): KycStorageResult;
    public function delete(string $fileReference): void;
}
```

**Concrete implementation**: `LaravelDiskKycDocumentStorage` — uses Laravel's `Storage::disk('kyc-documents')` (local disk for dev/test; configurable via `filesystems.php`). No S3 dependency in E05; the abstraction allows swap-in without code changes.

**Rationale**: The file reference (opaque storage key) returned by `store()` is the only thing stored in `kyc_documents.file_reference`. The actual storage path/URL never appears in the database.

---

## 4. Domain Event / Outbox Pattern

**Decision**: Use `OutboxEventPublisher` with `platform.outbox_events` table (already exists).

`OutboxEventPublisher::publish()` requires an active DB transaction (`transactionLevel() >= 1`). KYC services must wrap state mutation + outbox publish in a single transaction:

```php
DB::connection('kyc')->transaction(function () use (...) {
    // 1. Mutate kyc.kyc_cases
    // 2. Write platform.audit_events
    // 3. OutboxEventPublisher::publish(new KycCaseApprovedEvent(...))
    //    ↳ This writes to platform.outbox_events inside the same tx
});
```

**Naming convention** (from `BaseIntegrationEvent` comment):
- Domain integration event `eventName()` → `Kyc.Case.Approved` (PascalCase dots)
- Audit event `action` field → `kyc.case.approved` (lowercase dots, per `AuditEventData` doc comment)

**KYC domain events (outbox)**:

| `eventName()` | `aggregateType` | When |
|---|---|---|
| `Kyc.Case.Submitted` | `KycCase` | After submit → under_review |
| `Kyc.Case.Approved` | `KycCase` | After approval (auto or manual) |
| `Kyc.Case.Rejected` | `KycCase` | After rejection |
| `Kyc.Case.NeedsInfo` | `KycCase` | After request-info |
| `Kyc.Case.Resubmitted` | `KycCase` | After resubmit |
| `Kyc.Case.Expired` | `KycCase` | After expiry detection |
| `Kyc.Case.ReverificationTriggered` | `KycCase` | After reverification trigger |

---

## 5. Idempotency Service

**Decision**: Use existing `IdempotencyService` for `POST /v1/kyc/cases` and `POST /v1/kyc/cases/{id}/submit`.

`IdempotencyService` uses Redis fast path + `platform.idempotency_records` slow path. The `Idempotency-Key` header is parsed by the existing `IdempotencyMiddleware`. E05 applies this middleware on:
- `POST /v1/kyc/cases`
- `POST /v1/kyc/cases/{id}/submit`
- `POST /v1/kyc/cases/{id}/resubmit`

**Rationale**: Identical pattern to E01 auth endpoints and E04 create-admin. No new infrastructure needed.

---

## 6. KycServiceProvider — Routes File

**Finding**: `KycServiceProvider::boot()` already calls `$this->loadRoutesFrom(base_path('routes/modules/kyc.php'))`. This file does NOT exist yet — it must be created in E05.

**Route file path**: `routes/modules/kyc.php`

**Route prefix strategy**: All routes under `/v1/kyc/` using API route group with `identity.auth` middleware.

---

## 7. Authorization Middleware

**Decision**: Re-use E04's `identity.require-permission` middleware. No new middleware needed.

```php
Route::middleware(['identity.auth', 'identity.require-permission:kyc.case.create'])
    ->post('/v1/kyc/cases', [KycCaseController::class, 'start']);
```

E05 seeds `kyc.case.*` permissions via its own migration. They are assigned to the `admin` role via the same seed pattern as E04.

---

## 8. Queue / Job Infrastructure

**Decision**: Use existing Laravel Horizon + Redis queue. No new queue configuration needed.

`PerformAutomatedVerificationJob` will implement `ShouldQueue`, `InteractsWithQueue`. The `kyc` queue connection/name follows the existing project convention (check `horizon.php` for queue name).

Job signature: `PerformAutomatedVerificationJob($caseId)` — fetches case from DB inside the job to avoid stale data.

**Retry logic**: `$this->tries = (int) ConfigurationService::getOrDefault('kyc.provider_max_retries', 3)`.

---

## 9. KycVerificationProvider Interface

**Decision**: New interface in `KycModule/Domain/Contracts/KycVerificationProvider.php`.

```php
interface KycVerificationProvider
{
    public function verify(array $caseData): VerificationOutcome;
}

readonly class VerificationOutcome
{
    public function __construct(
        public KycVerificationOutcomeEnum $outcome,
        public ?string $providerReference,
        public ?float $confidenceScore,
        public string $rawResponseHash,
    ) {}
}
```

The concrete provider is bound in `KycServiceProvider::register()` based on `kyc.provider` config value. Since TBD-E05-001 is unresolved, a `NullKycVerificationProvider` is bound by default that returns `manual_review_required` for all cases.

---

## 10. Module Directory Structure

E05 creates `app/Modules/Kyc/` with the following layout (the stub already has `Providers/KycServiceProvider.php` and `Infrastructure/Migrations/.gitkeep`):

```
app/Modules/Kyc/
├── Application/
│   └── Services/
│       ├── KycCaseService.php
│       ├── KycDocumentService.php
│       └── KycVerificationService.php
├── Console/
│   └── Commands/
│       └── DetectExpiredKycCommand.php
├── Domain/
│   ├── Contracts/
│   │   ├── KycDocumentStorageContract.php
│   │   ├── KycVerificationProvider.php
│   │   └── KycCaseRepositoryInterface.php
│   ├── Enums/
│   │   ├── KycCaseStatus.php
│   │   ├── KycDocumentType.php
│   │   └── KycVerificationOutcome.php
│   ├── Events/
│   │   ├── KycCaseApprovedEvent.php
│   │   ├── KycCaseExpiredEvent.php
│   │   └── KycCaseReverificationTriggeredEvent.php
│   ├── Exceptions/
│   │   ├── KycCaseAlreadyOpenException.php
│   │   ├── KycInvalidStatusTransitionException.php
│   │   ├── KycDuplicateIdentityException.php
│   │   └── KycCaseIncompleteException.php
│   └── Services/
│       ├── KycStateMachine.php
│       └── KycDuplicateIdentityChecker.php
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── KycCaseController.php
│   │   │   ├── KycDocumentController.php
│   │   │   └── KycReviewController.php
│   │   └── Resources/
│   │       └── KycCaseResource.php
│   ├── Jobs/
│   │   └── PerformAutomatedVerificationJob.php
│   ├── Migrations/  (6 migration files)
│   ├── Models/
│   │   ├── KycCaseModel.php
│   │   ├── KycPersonalInfoModel.php
│   │   ├── KycDocumentModel.php
│   │   └── KycVerificationResultModel.php
│   ├── Persistence/
│   │   └── EloquentKycCaseRepository.php
│   ├── Providers/
│   │   └── NullKycVerificationProvider.php
│   └── Storage/
│       └── LaravelDiskKycDocumentStorage.php
└── Providers/
    └── KycServiceProvider.php  (overwrite stub)
```
