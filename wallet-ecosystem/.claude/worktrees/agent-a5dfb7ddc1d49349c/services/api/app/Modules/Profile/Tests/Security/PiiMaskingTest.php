<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use App\Modules\Profile\Application\Commands\InitiateEmailChangeCommand;
use App\Modules\Profile\Application\Commands\InitiateMobileChangeCommand;
use App\Modules\Profile\Domain\Services\ContactChangeService;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T124 — PII masking: outbox event payloads contain masked email/mobile, not raw values
// ---------------------------------------------------------------------------

describe('PII Masking in Outbox Events (T124)', function (): void {

    // -------------------------------------------------------------------------
    // Email change initiation — Profile.User.EmailChangeInitiated
    // -------------------------------------------------------------------------
    it('Profile.User.EmailChangeInitiated payload contains masked email, not raw email', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();
        $rawEmail      = 'ada.lovelace@example.com';

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'old@example.com',
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with($rawEmail, OtpChallengeType::EMAIL_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateEmailChange(new InitiateEmailChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newEmail:       $rawEmail,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.EmailChangeInitiated')
            ->where('aggregate_id', $userId)
            ->where('correlation_id', $correlationId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.EmailChangeInitiated must be written to outbox');

        $payload = json_decode($event->payload, true);

        // Raw email must NOT appear anywhere in the payload
        $payloadJson = json_encode($payload);
        expect($payloadJson)->not->toContain(
            $rawEmail,
            'Raw email address must NOT appear in outbox event payload'
        );

        // The masked form must be present
        $expectedMasked = PiiMasker::maskEmail($rawEmail);
        expect($payload)->toHaveKey('new_email_masked');
        expect($payload['new_email_masked'])->toBe($expectedMasked);
    });

    // -------------------------------------------------------------------------
    // Contact security alert — Profile.User.ContactSecurityAlert (old email masked)
    // -------------------------------------------------------------------------
    it('Profile.User.ContactSecurityAlert payload for email change contains masked old email', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();
        $oldEmail      = 'old.address@example.com';
        $newEmail      = 'new.address@example.com';

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => $oldEmail,
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with($newEmail, OtpChallengeType::EMAIL_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateEmailChange(new InitiateEmailChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newEmail:       $newEmail,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $alertEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.ContactSecurityAlertSent')
            ->where('aggregate_id', $userId)
            ->where('correlation_id', $correlationId)
            ->first();

        expect($alertEvent)->not->toBeNull('Profile.User.ContactSecurityAlert must be written to outbox');

        $alertPayload = json_decode($alertEvent->payload, true);

        // Raw old email must NOT appear anywhere in the security alert payload
        $alertPayloadJson = json_encode($alertPayload);
        expect($alertPayloadJson)->not->toContain(
            $oldEmail,
            'Raw old email address must NOT appear in ContactSecurityAlert payload'
        );

        // The masked form must be present
        expect($alertPayload)->toHaveKey('old_contact_masked');
        expect($alertPayload['old_contact_masked'])->toBe(PiiMasker::maskEmail($oldEmail));
    });

    // -------------------------------------------------------------------------
    // Mobile change initiation — Profile.User.MobileChangeInitiated
    // -------------------------------------------------------------------------
    it('Profile.User.MobileChangeInitiated payload contains masked mobile, not raw number', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();
        $rawMobile     = '+447700900123';

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'mobile_number'  => '+447700900001',
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with($rawMobile, OtpChallengeType::MOBILE_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateMobileChange(new InitiateMobileChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newMobile:      $rawMobile,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.MobileChangeInitiated')
            ->where('aggregate_id', $userId)
            ->where('correlation_id', $correlationId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.MobileChangeInitiated must be written to outbox');

        $payload = json_decode($event->payload, true);

        // Raw mobile must NOT appear anywhere in the payload
        $payloadJson = json_encode($payload);
        expect($payloadJson)->not->toContain(
            $rawMobile,
            'Raw mobile number must NOT appear in outbox event payload'
        );

        // The masked form must be present
        $expectedMasked = PiiMasker::maskPhone($rawMobile);
        expect($payload)->toHaveKey('new_mobile_masked');
        expect($payload['new_mobile_masked'])->toBe($expectedMasked);
    });

    // -------------------------------------------------------------------------
    // Audit record — email-change-initiated audit metadata contains masked email only
    // -------------------------------------------------------------------------
    it('audit record for email-change-initiated does not expose raw email in payload', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $challengeId   = (string) Uuid::uuid7();
        $rawEmail      = 'sensitive@private.example.com';

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'old@example.com',
        ]);

        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('generate')
            ->with($rawEmail, OtpChallengeType::EMAIL_CHANGE, $userId, $correlationId)
            ->andReturn($fakeChallenge);

        $service = new ContactChangeService(
            profiles:  app(EloquentUserProfileRepository::class),
            otp:       $otp,
            identity:  app(\App\Modules\Identity\Domain\Contracts\IdentityModuleContract::class),
            events:    app(EventPublisherInterface::class),
            audit:     app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
            metrics:   app(\App\Modules\Profile\Domain\Services\ProfileMetrics::class),
            pii:       app(\App\Modules\Profile\Domain\Services\PiiMaskingHelper::class),
        );

        $service->initiateEmailChange(new InitiateEmailChangeCommand(
            userId:         $userId,
            actorId:        $userId,
            actorType:      'USER',
            newEmail:       $rawEmail,
            idempotencyKey: (string) Uuid::uuid7(),
            correlationId:  $correlationId,
        ));

        $auditRecord = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.email-change-initiated')
            ->where('correlation_id', $correlationId)
            ->first();

        expect($auditRecord)->not->toBeNull();

        // Entire audit row (both payload and metadata) must not contain raw email
        $rowJson = json_encode([
            'payload'     => json_decode($auditRecord->payload, true),
            'before_state' => $auditRecord->before_state,
            'after_state'  => $auditRecord->after_state,
        ]);

        expect($rowJson)->not->toContain(
            $rawEmail,
            'Raw email must NOT appear in audit event payload, before_state, or after_state'
        );
    });

});
