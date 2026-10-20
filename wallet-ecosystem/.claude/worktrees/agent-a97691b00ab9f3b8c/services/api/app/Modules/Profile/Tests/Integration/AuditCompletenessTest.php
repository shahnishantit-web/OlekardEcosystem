<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Profile\Application\Commands\CancelClosureCommand;
use App\Modules\Profile\Application\Commands\InitiateEmailChangeCommand;
use App\Modules\Profile\Application\Commands\InitiateMobileChangeCommand;
use App\Modules\Profile\Application\Commands\PermanentlyCloseAccountCommand;
use App\Modules\Profile\Application\Commands\ReactivateAccountCommand;
use App\Modules\Profile\Application\Commands\RequestClosureCommand;
use App\Modules\Profile\Application\Commands\RequestDataExportCommand;
use App\Modules\Profile\Application\Commands\SuspendAccountCommand;
use App\Modules\Profile\Application\Commands\UpdatePersonalInfoCommand;
use App\Modules\Profile\Application\Commands\UploadProfilePhotoCommand;
use App\Modules\Profile\Application\Commands\VerifyEmailChangeCommand;
use App\Modules\Profile\Application\Commands\VerifyMobileChangeCommand;
use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Contracts\ObligationCheckResult;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;
use App\Modules\Profile\Domain\Services\AccountStatusService;
use App\Modules\Profile\Domain\Services\ClosureGateService;
use App\Modules\Profile\Domain\Services\ContactChangeService;
use App\Modules\Profile\Domain\Services\DataExportService;
use App\Modules\Profile\Domain\Services\ProfilePhotoService;
use App\Modules\Profile\Domain\Services\ProfileService;
use App\Modules\Profile\Infrastructure\Listeners\CreateProfileOnUserRegisteredListener;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T123 — Audit completeness: all 14 profile.* audit actions write to platform.audit_events
// ---------------------------------------------------------------------------

/**
 * Assert a single audit record exists matching action + correlation_id; return it.
 */
function assertAuditRecord(string $action, string $correlationId): object
{
    $record = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', $action)
        ->where('correlation_id', $correlationId)
        ->first();

    expect($record)->not->toBeNull(
        "Expected audit record for action='{$action}' correlation_id='{$correlationId}' — none found."
    );

    return $record;
}

/**
 * Build a fresh AccountStatusService with a permissive (no-obligation) FinancialObligationContract.
 * Bypasses the registered singleton so closure-path tests can reach the audit write.
 */
function makeAccountServiceWithPermissiveGate(): AccountStatusService
{
    $obligation = Mockery::mock(FinancialObligationContract::class);
    $obligation->shouldReceive('checkObligations')
        ->andReturn(new ObligationCheckResult(false, []));

    return new AccountStatusService(
        profiles: app(EloquentUserProfileRepository::class),
        history:  app(\App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository::class),
        events:   app(EventPublisherInterface::class),
        audit:    app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
        metrics:  app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
        closureGate: new ClosureGateService($obligation),
    );
}

describe('Audit Completeness (T123)', function (): void {

    // -------------------------------------------------------------------------
    // 1. profile.user.profile-created
    // -------------------------------------------------------------------------
    it('writes profile.user.profile-created when listener processes Identity.User.Registered', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        $identity = Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('getUserContactInfo')->with($userId)->andReturnNull();
        app()->instance(IdentityModuleContract::class, $identity);

        app(CreateProfileOnUserRegisteredListener::class)->handle(
            'Identity.User.Registered',
            ['user_id' => $userId, 'correlation_id' => $correlationId, 'actor_type' => 'CUSTOMER'],
        );

        $record = assertAuditRecord('profile.user.profile-created', $correlationId);
        expect($record->actor_type)->toBe('SYSTEM');
        expect($record->resource_id)->toBe($userId);
        $payload = json_decode($record->payload, true);
        expect($payload['subject_user_id'])->toBe($userId);
    });

    // -------------------------------------------------------------------------
    // 2. profile.user.personal-info-updated
    // -------------------------------------------------------------------------
    it('writes profile.user.personal-info-updated when ProfileService updates fields', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        app(ProfileService::class)->updatePersonalInfo(new UpdatePersonalInfoCommand(
            targetUserId:  $userId,
            actorId:       $userId,
            actorType:     'USER',
            fields:        ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            correlationId: $correlationId,
        ));

        $record = assertAuditRecord('profile.user.personal-info-updated', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 3. profile.user.photo-updated
    // -------------------------------------------------------------------------
    it('writes profile.user.photo-updated when ProfilePhotoService uploads a photo', function (): void {
        Storage::fake('local');
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $storageMock = Mockery::mock(ProfilePhotoStorageContract::class);
        $storageMock->shouldReceive('store')->andReturn(
            new \App\Modules\Profile\Domain\Contracts\PhotoStorageResult(
                storageKey: 'photos/test.jpg',
                sizeBytes:  12345,
                mimeType:   'image/jpeg',
                widthPx:    100,
                heightPx:   100,
            )
        );
        $storageMock->shouldReceive('delete')->andReturn(null);

        $service = new ProfilePhotoService(
            profiles:     app(EloquentUserProfileRepository::class),
            photoStorage: $storageMock,
            config:       app(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class),
            events:       app(EventPublisherInterface::class),
            audit:        app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
        );

        $service->upload(new UploadProfilePhotoCommand(
            userId:        $userId,
            actorId:       $userId,
            actorType:     'USER',
            correlationId: $correlationId,
            file:          UploadedFile::fake()->image('avatar.jpg', 100, 100),
        ));

        $record = assertAuditRecord('profile.user.photo-updated', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 4. profile.user.email-change-initiated
    // -------------------------------------------------------------------------
    it('writes profile.user.email-change-initiated when ContactChangeService initiates email change', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'old@example.com',
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with('new@example.com', OtpChallengeType::EMAIL_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateEmailChange(new InitiateEmailChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newEmail:       'new@example.com',
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $record = assertAuditRecord('profile.user.email-change-initiated', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 5. profile.user.email-changed
    // -------------------------------------------------------------------------
    it('writes profile.user.email-changed when ContactChangeService verifies email OTP', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'                    => $userId,
            'account_status'             => 'active',
            'email'                      => 'old@example.com',
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('verify')->with($challengeId, '123456')->andReturn(null);

        $identity = Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('updateUserContact')->andReturn(null);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  $identity,
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->verifyEmailChange(new VerifyEmailChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            challengeId:    $challengeId,
            otp:            '123456',
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $record = assertAuditRecord('profile.user.email-changed', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 6. profile.user.mobile-change-initiated
    // -------------------------------------------------------------------------
    it('writes profile.user.mobile-change-initiated when ContactChangeService initiates mobile change', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'mobile_number'  => '+447700900001',
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with('+447700900002', OtpChallengeType::MOBILE_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateMobileChange(new InitiateMobileChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newMobile:      '+447700900002',
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $record = assertAuditRecord('profile.user.mobile-change-initiated', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 7. profile.user.mobile-changed
    // -------------------------------------------------------------------------
    it('writes profile.user.mobile-changed when ContactChangeService verifies mobile OTP', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'                     => $userId,
            'account_status'              => 'active',
            'mobile_number'               => '+447700900001',
            'pending_mobile'              => '+447700900002',
            'pending_mobile_challenge_id' => $challengeId,
        ]);

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('verify')->with($challengeId, '654321')->andReturn(null);

        $identity = Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('updateUserContact')->andReturn(null);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  $identity,
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->verifyMobileChange(new VerifyMobileChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            challengeId:    $challengeId,
            otp:            '654321',
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $record = assertAuditRecord('profile.user.mobile-changed', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 8. profile.account.suspended  (admin path — subject_user_id in payload)
    // -------------------------------------------------------------------------
    it('writes profile.account.suspended with subject_user_id for admin actor', function (): void {
        $userId        = (string) Uuid::uuid7();
        $adminId       = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        app(AccountStatusService::class)->suspend(new SuspendAccountCommand(
            targetUserId:  $userId,
            actorId:       $adminId,
            reason:        'Policy violation.',
            correlationId: $correlationId,
        ));

        $record = assertAuditRecord('profile.account.suspended', $correlationId);
        expect($record->actor_id)->toBe($adminId);
        expect($record->actor_type)->toBe('ADMIN');
        $payload = json_decode($record->payload, true);
        expect($payload['subject_user_id'])->toBe($userId);
    });

    // -------------------------------------------------------------------------
    // 9. profile.account.reactivated  (admin path — subject_user_id in payload)
    // -------------------------------------------------------------------------
    it('writes profile.account.reactivated with subject_user_id for admin actor', function (): void {
        $userId        = (string) Uuid::uuid7();
        $adminId       = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'suspended']);

        app(AccountStatusService::class)->reactivate(new ReactivateAccountCommand(
            targetUserId:  $userId,
            actorId:       $adminId,
            reason:        'Reinstatement approved.',
            correlationId: $correlationId,
        ));

        $record = assertAuditRecord('profile.account.reactivated', $correlationId);
        expect($record->actor_id)->toBe($adminId);
        expect($record->actor_type)->toBe('ADMIN');
        $payload = json_decode($record->payload, true);
        expect($payload['subject_user_id'])->toBe($userId);
    });

    // -------------------------------------------------------------------------
    // 10. profile.account.closure-requested
    // -------------------------------------------------------------------------
    it('writes profile.account.closure-requested when user initiates closure with no obligations', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        makeAccountServiceWithPermissiveGate()->requestClosure(new RequestClosureCommand(
            userId:        $userId,
            actorId:       $userId,
            actorType:     'USER',
            correlationId: $correlationId,
        ));

        $record = assertAuditRecord('profile.account.closure-requested', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 11. profile.account.closure-cancelled
    // -------------------------------------------------------------------------
    it('writes profile.account.closure-cancelled when user cancels pending closure', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'pending_closure']);

        app(AccountStatusService::class)->cancelClosure(new CancelClosureCommand(
            userId:        $userId,
            actorId:       $userId,
            actorType:     'USER',
            correlationId: $correlationId,
            reason:        'Changed mind.',
        ));

        $record = assertAuditRecord('profile.account.closure-cancelled', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

    // -------------------------------------------------------------------------
    // 12. profile.account.closure-blocked  (uses default stub — always blocks)
    // -------------------------------------------------------------------------
    it('writes profile.account.closure-blocked when financial obligations prevent closure', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        expect(static function () use ($userId, $correlationId): void {
            // StubFinancialObligationService always blocks — no override needed
            app(AccountStatusService::class)->requestClosure(new RequestClosureCommand(
                userId:        $userId,
                actorId:       $userId,
                actorType:     'USER',
                correlationId: $correlationId,
            ));
        })->toThrow(ClosureBlockedByObligationsException::class);

        $record = assertAuditRecord('profile.account.closure-blocked', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('blocked');
    });

    // -------------------------------------------------------------------------
    // 13. profile.account.closed  (admin path — subject_user_id in payload)
    // -------------------------------------------------------------------------
    it('writes profile.account.closed with subject_user_id for admin-initiated permanent closure', function (): void {
        $userId        = (string) Uuid::uuid7();
        $adminId       = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'pending_closure']);

        makeAccountServiceWithPermissiveGate()->permanentlyClose(new PermanentlyCloseAccountCommand(
            targetUserId:  $userId,
            actorId:       $adminId,
            correlationId: $correlationId,
            reason:        'Final closure per regulatory request.',
        ));

        $record = assertAuditRecord('profile.account.closed', $correlationId);
        expect($record->actor_id)->toBe($adminId);
        expect($record->actor_type)->toBe('ADMIN');
        $payload = json_decode($record->payload, true);
        expect($payload['subject_user_id'])->toBe($userId);
    });

    // -------------------------------------------------------------------------
    // 14. profile.user.data-export-requested
    // -------------------------------------------------------------------------
    it('writes profile.user.data-export-requested when DataExportService receives request', function (): void {
        Queue::fake();

        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        app(DataExportService::class)->request(new RequestDataExportCommand(
            userId:        $userId,
            correlationId: $correlationId,
        ));

        $record = assertAuditRecord('profile.user.data-export-requested', $correlationId);
        expect($record->actor_id)->toBe($userId);
        expect($record->outcome)->toBe('success');
    });

});
