# Research — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Date**: 2026-08-27 | **Status**: Complete

---

## Decision: Cross-Module Contact Update Coordination (TBD-E02-002) — RESOLVED

**Decision**: Synchronous service call via a new `ProfileIdentityServiceContract` interface, added to `IdentityModuleContract`'s public surface. E01 implements the two new methods; E02 calls them via dependency injection.

**Methods required** (added to `App\Modules\Identity\Domain\Contracts\IdentityModuleContract`):

```php
/**
 * Fetch email and mobile_number for a registered user.
 * Called by E02 at profile creation time after Identity.User.Registered.
 * Returns null if user_id is not found (should not happen in normal flow).
 */
public function getUserContactInfo(string $userId): ?UserContactInfo;

/**
 * Atomically update email and/or mobile_number on identity.users.
 * Called by E02 after successful OTP verification.
 * Throws IdentityContactUpdateException on failure (causes E02 to rollback).
 */
public function updateUserContact(string $userId, ?string $email, ?string $mobile): void;
```

**Rationale**:
- Consistent with E999 module boundary: E01 defines and implements; E02 calls via interface
- Enables DB-level rollback: E02 wraps its own table update + `IdentityModuleContract::updateUserContact()` in a `DB::transaction()`; failure triggers rollback of both sides
- No new event infrastructure or saga pattern needed
- Direct SQL across schemas is prohibited by ADR-003

**Alternatives considered**:
- Saga/outbox event chain: overkill for a synchronous user-initiated flow; rollback semantics harder
- E02 emitting a command event for E01 to consume: reverses the dependency direction (E01 consuming E02 events is architecturally wrong)
- Direct SQL across schemas: prohibited

**E01 impact**: E01 must implement two new methods on `IdentityModuleContract`. E02 contact-change verification phase cannot be fully integration-tested until this extension is delivered. E02 tests use a mock/stub of `IdentityModuleContract`.

---

## Decision: Profile Photo Storage Abstraction (TBD-E02-001) — PARTIALLY RESOLVED

**Decision**: Abstract behind `ProfilePhotoStorageContract`. Dev/test: Laravel `Storage::disk('local')`. Production provider: TBD.

**Interface (to implement)**:
```php
namespace App\Modules\Profile\Domain\Contracts;

interface ProfilePhotoStorageContract
{
    public function store(string $userId, mixed $uploadedFile, string $mimeType): PhotoStorageResult;
    public function delete(string $storageKey): void;
    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string;
}

readonly class PhotoStorageResult
{
    public function __construct(
        public string $storageKey,   // Internal key; never exposed in API responses
        public int    $sizeBytes,
        public string $mimeType,
        public int    $widthPx,
        public int    $heightPx,
    ) {}
}
```

**Dev/test implementation**: `LocalDiskProfilePhotoStorage` — stores to `storage/app/profiles/{userId}/photo.{ext}`; generates a signed URL via `URL::temporarySignedRoute()` (Laravel signed routes).

**Production**: Bound via `profile.photo.storage_driver` config key. Provider implementation not in E02 scope.

**Package**: `intervention/image-laravel` (image dimension extraction and validation). Verify presence in `composer.json`; add if absent.

---

## Decision: Financial Obligation Gate (TBD until E06/E11) — RESOLVED with stub

**Decision**: `FinancialObligationContract` interface with a `StubFinancialObligationService` that **always blocks** (fail-safe) until E06/E11 supply real implementations.

```php
namespace App\Modules\Profile\Domain\Contracts;

interface FinancialObligationContract
{
    public function checkObligations(string $userId): ObligationCheckResult;
}

readonly class ObligationCheckResult
{
    public function __construct(
        public bool  $hasObligations,
        public array $blockedBy, // e.g. ['wallet_balance', 'pending_transactions', 'active_loan', 'open_dispute']
    ) {}
}
```

**Stub**: Always returns `hasObligations: true, blockedBy: ['obligations_check_unavailable']`. E06/E11 bind a real implementation when available. Fail-safe guarantees no accidental closures while gate is not wired.

---

## Decision: Data Export Format (TBD-E02-003) — PARTIALLY RESOLVED

**Decision**:
- **Format**: JSON (single structured object; format not a product decision)
- **Storage**: Reuses `ProfilePhotoStorageContract` with a separate export storage key
- **Delivery**: Signed URL delivered via `Profile.User.DataExportReady` event → E17

**Export JSON structure**:
```json
{
  "generated_at": "ISO-8601",
  "user_id": "uuid",
  "profile": { /* all profile fields */ },
  "status_history": [ /* account_status_history records */ ],
  "audit_summary": { "total_mutations": N, "last_mutation_at": "..." }
}
```

Excludes: credential hashes, OTP values, raw IP addresses, session tokens.

**Delivery mechanism TBD**: The spec allows either download link via poll endpoint OR notification event. Plan implements async `ProcessDataExportJob` (Horizon) that stores JSON, then emits `Profile.User.DataExportReady` with a signed URL payload for E17 to deliver. A poll endpoint `GET /v1/users/profile/data-export/{export_id}` is also included for completeness.

---

## Decision: Configuration Key Names (TBD-E02-004 partial)

**Status**: Key names RESOLVED; default values remain TBD (product decisions).

| Key | Type | Seeded Default | Notes |
|---|---|---|---|
| `profile.minimum_user_age_years` | integer | NULL (TBD) | Product decision; FR-E02-008 blocks on TBD if not set |
| `profile.photo.max_size_bytes` | integer | NULL (TBD) | Product decision |
| `profile.photo.accepted_mime_types` | json | NULL (TBD) | Product decision |
| `profile.photo.min_width_px` | integer | NULL (TBD) | Product decision |
| `profile.photo.min_height_px` | integer | NULL (TBD) | Product decision |
| `profile.photo.storage_driver` | string | `'local'` | Dev default; prod TBD |
| `profile.photo.signed_url_ttl_seconds` | integer | `3600` | 1-hour signed URL; safe dev default |
| `profile.contact_change.otp_rate_limit_per_hour` | integer | NULL (TBD) | Product decision |
| `profile.mutation_rate_limit_per_minute` | integer | NULL (TBD) | Product decision |
| `profile.data_export.download_link_ttl_seconds` | integer | NULL (TBD) | Product decision |
| `profile.maker_checker.enabled` | boolean | `false` | TBD-E02-005 hook point; defaults off |

Migration 004 uses `insertOrIgnore` — idempotent; never overwrites existing values. TBD values seeded as `null`.

---

## Decision: Maker-Checker for Privileged Operations (TBD-E02-005) — DEFERRED

**Decision**: Not implemented in E02. Config key `profile.maker_checker.enabled` defaults to `false`. All privileged operations (suspend, close) execute on single-actor approval. The `AccountStatusService` includes a commented hook point for future maker-checker workflow integration.

---

## Decision: Profile PostgreSQL Schema Ownership

**Decision**: E02 creates the `profile` schema. Migration 001 begins with `CREATE SCHEMA IF NOT EXISTS profile`. Named connection `profile` added to `config/database.php` (same server as `identity`; `search_path=profile`).

**Note**: The E999 data model lists `identity` schema as tables created by "E01, E02, E03." This appears to be a documentation artifact from early planning. The E02 spec (authoritative per Principle 1) is unambiguous: E02 owns the `profile` schema. E02 does not create any tables in `identity.*`.

---

## Decision: Dual-Event Security Alert on Contact Change

**Decision**: `ContactChangeService::initiateEmailChange()` and `initiateEmailChange()` emit TWO events within the same DB transaction:
1. `Profile.User.EmailChangeInitiated` (or `MobileChangeInitiated`) — triggers E17 to dispatch the OTP
2. `Profile.User.ContactSecurityAlertSent` — triggers E17 to send a security alert to the OLD contact

Both events are written to `platform.outbox_events` atomically. If DB write fails, both roll back. If E17 fails to deliver the alert after the event is stored, that is a delivery-side failure — non-blocking per FR-E02-014a/018a.

No new event name is needed beyond what the spec already defines — the security alert is conveyed as a distinct event payload on the existing `EmailChangeInitiated`/`MobileChangeInitiated` events with a `security_alert_target` field, OR as a second event `Profile.User.ContactSecurityAlertSent`. Using a second event is cleaner — keeps event semantics narrow. Second event chosen.

---

## Packages

| Package | Purpose | Status |
|---|---|---|
| `intervention/image-laravel` | Photo dimension extraction + MIME validation | Add if absent |
| All other: Laravel built-ins | Storage, Queue, Events, Validation, etc. | Already present |

No new framework or ORM is required. E02 stays within the approved stack.
