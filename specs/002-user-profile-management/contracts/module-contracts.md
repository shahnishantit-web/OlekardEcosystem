# Module Contracts — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Date**: 2026-08-27

PHP interface contracts that define (a) how E02 integrates with E01 for contact updates and (b) how E02 abstracts external dependencies (photo storage, financial obligation gate). All interfaces belong to `App\Modules\Profile\Domain\Contracts\`.

---

## IdentityModuleContract Extensions (E01 additions)

E02 requires two new methods on `App\Modules\Identity\Domain\Contracts\IdentityModuleContract`. These are implemented by E01 and called by E02.

```php
// Addition to: App\Modules\Identity\Domain\Contracts\IdentityModuleContract

/**
 * Fetch email and mobile_number for a registered user.
 * Called by E02's CreateProfileOnUserRegisteredListener at profile creation time.
 * Returns null if user_id is not found.
 */
public function getUserContactInfo(string $userId): ?UserContactInfo;

/**
 * Atomically update email and/or mobile_number on identity.users.
 * Called by E02's ContactChangeService after successful OTP verification.
 * Pass null for a field to leave it unchanged.
 *
 * @throws IdentityContactUpdateException if user not found or DB update fails
 */
public function updateUserContact(string $userId, ?string $email, ?string $mobile): void;
```

### UserContactInfo DTO (defined in E01 contracts)

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class UserContactInfo
{
    public function __construct(
        public string  $userId,
        public ?string $email,
        public ?string $mobileNumber,
        public string  $actorType,    // CUSTOMER | AGENT | ADMIN
    ) {}
}
```

### IdentityContactUpdateException

```php
namespace App\Modules\Identity\Domain\Exceptions;

class IdentityContactUpdateException extends \RuntimeException {}
```

---

## ProfilePhotoStorageContract

Abstracts photo storage so the production provider (TBD-E02-001) is swappable without touching domain logic.

```php
namespace App\Modules\Profile\Domain\Contracts;

interface ProfilePhotoStorageContract
{
    /**
     * Persist an uploaded photo and return storage metadata.
     * Validates MIME type and dimensions before storing.
     *
     * @param string $userId       Owner of the photo
     * @param mixed  $file         Framework-agnostic file reference (e.g., UploadedFile)
     * @throws PhotoStorageException on write failure
     */
    public function store(string $userId, mixed $file): PhotoStorageResult;

    /**
     * Delete a stored photo by its internal key.
     * Silent no-op if key does not exist.
     */
    public function delete(string $storageKey): void;

    /**
     * Generate a time-limited signed URL for a stored photo.
     * The returned URL must NOT embed raw storage credentials or bucket paths.
     *
     * @param int $expiresInSeconds  Duration until the URL becomes invalid
     */
    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string;
}
```

### PhotoStorageResult DTO

```php
namespace App\Modules\Profile\Domain\Contracts;

readonly class PhotoStorageResult
{
    public function __construct(
        public string $storageKey,   // Internal reference; never exposed in API responses
        public int    $sizeBytes,
        public string $mimeType,
        public int    $widthPx,
        public int    $heightPx,
    ) {}
}
```

### PhotoStorageException

```php
namespace App\Modules\Profile\Domain\Exceptions;

class PhotoStorageException extends \RuntimeException {}
```

### Dev/Test Implementation

```php
// App\Modules\Profile\Infrastructure\Storage\LocalDiskProfilePhotoStorage
// implements ProfilePhotoStorageContract
// Uses Laravel Storage::disk('local')
// generateSignedUrl() uses URL::temporarySignedRoute('profile.photo.serve', ...)
```

---

## FinancialObligationContract

Abstracts the closure obligation check so E06/E11 can wire real implementations without modifying E02.

```php
namespace App\Modules\Profile\Domain\Contracts;

interface FinancialObligationContract
{
    /**
     * Check whether a user has any unresolved financial obligations.
     * Called before any account closure operation (self-service and admin).
     *
     * Obligation categories: wallet_balance, pending_transactions, active_loan, open_dispute
     */
    public function checkObligations(string $userId): ObligationCheckResult;
}
```

### ObligationCheckResult DTO

```php
namespace App\Modules\Profile\Domain\Contracts;

readonly class ObligationCheckResult
{
    public function __construct(
        public bool  $hasObligations,
        public array $blockedBy,  // string[] of obligation category strings
    ) {}
}
```

### Stub Implementation (fail-safe)

```php
// App\Modules\Profile\Infrastructure\Stubs\StubFinancialObligationService
// implements FinancialObligationContract
//
// Returns: hasObligations: true, blockedBy: ['obligations_check_unavailable']
//
// This stub is bound until E06 and E11 provide real implementations.
// Fail-safe: always blocks closure, preventing accidental closures when gate is not wired.
```

---

## ProfileModuleContract

The public surface through which other modules may consume E02 functionality in future epics. Defined now for extraction-readiness.

```php
namespace App\Modules\Profile\Domain\Contracts;

interface ProfileModuleContract
{
    /**
     * Get the current account status for a user.
     * Used by downstream modules (e.g., E17 for notification targeting).
     * Returns null if no profile record exists.
     */
    public function getAccountStatus(string $userId): ?string;

    /**
     * Check whether a user's profile is complete enough for downstream flows (e.g., KYC).
     * Returns true if the profile has at least first_name, last_name, and date_of_birth.
     */
    public function isProfileComplete(string $userId): bool;
}
```

---

## Exception Types

```php
namespace App\Modules\Profile\Domain\Exceptions;

class ProfileNotFoundException extends \RuntimeException {}
class ContactChangeInProgressException extends \RuntimeException {}
class SameContactValueException extends \RuntimeException {}
class InvalidStatusTransitionException extends \RuntimeException {}
class ClosureBlockedByObligationsException extends \RuntimeException
{
    public function __construct(
        public readonly array $blockedBy,
        string $message = '',
    ) { parent::__construct($message); }
}
class ExportInProgressException extends \RuntimeException {}
class MinimumAgeViolationException extends \RuntimeException {}
class FieldNotPermittedException extends \RuntimeException {}
```

---

## Event Contracts

All E02 integration events implement `IntegrationEventInterface` from Platform Foundation and are published via `EventPublisherInterface`. Events follow `{Module}.{Aggregate}.{Verb}` naming in dot-notation.

| Event Class | Event Name | Key Payload Fields |
|---|---|---|
| `ProfileCreatedEvent` | `Profile.User.ProfileCreated` | `user_id`, `actor_type` |
| `PersonalInfoUpdatedEvent` | `Profile.User.PersonalInfoUpdated` | `user_id`, `changed_fields[]` |
| `PhotoUpdatedEvent` | `Profile.User.PhotoUpdated` | `user_id` |
| `EmailChangeInitiatedEvent` | `Profile.User.EmailChangeInitiated` | `user_id`, `challenge_id`, `new_email_masked` |
| `ContactSecurityAlertEvent` (email) | `Profile.User.ContactSecurityAlertSent` | `user_id`, `contact_type: email`, `old_contact_masked` |
| `EmailChangedEvent` | `Profile.User.EmailChanged` | `user_id`, `new_email_masked` |
| `MobileChangeInitiatedEvent` | `Profile.User.MobileChangeInitiated` | `user_id`, `challenge_id`, `new_mobile_masked` |
| `ContactSecurityAlertEvent` (mobile) | `Profile.User.ContactSecurityAlertSent` | `user_id`, `contact_type: mobile`, `old_contact_masked` |
| `MobileChangedEvent` | `Profile.User.MobileChanged` | `user_id`, `new_mobile_masked` |
| `AccountSuspendedEvent` | `Profile.Account.Suspended` | `user_id`, `reason`, `actor_id` |
| `AccountReactivatedEvent` | `Profile.Account.Reactivated` | `user_id`, `actor_id` |
| `ClosureRequestedEvent` | `Profile.Account.ClosureRequested` | `user_id` |
| `ClosureCancelledEvent` | `Profile.Account.ClosureCancelled` | `user_id`, `actor_id`, `actor_type` |
| `ClosureBlockedEvent` | `Profile.Account.ClosureBlocked` | `user_id`, `blocked_by[]` |
| `AccountClosedEvent` | `Profile.Account.Closed` | `user_id`, `actor_id` |
| `DataExportRequestedEvent` | `Profile.User.DataExportRequested` | `user_id`, `export_id` |
| `DataExportReadyEvent` | `Profile.User.DataExportReady` | `user_id`, `export_id`, `signed_url`, `expires_at` |

**PII masking rule**: Email masked as `j*****@domain.com`; mobile masked as `+267*****678`. Raw values MUST NOT appear in any event payload.
