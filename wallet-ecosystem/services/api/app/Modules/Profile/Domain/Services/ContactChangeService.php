<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Application\Commands\InitiateEmailChangeCommand;
use App\Modules\Profile\Application\Commands\InitiateMobileChangeCommand;
use App\Modules\Profile\Application\Commands\VerifyEmailChangeCommand;
use App\Modules\Profile\Application\Commands\VerifyMobileChangeCommand;
use App\Modules\Profile\Domain\Events\ContactSecurityAlertEvent;
use App\Modules\Profile\Domain\Events\EmailChangeInitiatedEvent;
use App\Modules\Profile\Domain\Events\EmailChangedEvent;
use App\Modules\Profile\Domain\Events\MobileChangeInitiatedEvent;
use App\Modules\Profile\Domain\Events\MobileChangedEvent;
use App\Modules\Profile\Domain\Exceptions\ChallengeAlreadyCompletedException;
use App\Modules\Profile\Domain\Exceptions\ChallengeNotFoundException;
use App\Modules\Profile\Domain\Exceptions\ContactChangeInProgressException;
use App\Modules\Profile\Domain\Exceptions\ContactOtpExpiredException;
use App\Modules\Profile\Domain\Exceptions\ContactOtpInvalidException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Exceptions\SameContactValueException;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;

final class ContactChangeService
{
    public function __construct(
        private readonly EloquentUserProfileRepository $profiles,
        private readonly OtpServiceContract            $otp,
        private readonly IdentityModuleContract        $identity,
        private readonly EventPublisherInterface       $events,
        private readonly AuditServiceInterface         $audit,
        private readonly ProfileMetrics                $metrics,
        private readonly PiiMaskingHelper              $pii,
    ) {}

    /**
     * Initiate an email address change for the authenticated user.
     * Generates an OTP challenge, stores the pending state, and emits dual events.
     *
     * @return string The challenge_id the client must supply when verifying the OTP.
     *
     * @throws ProfileNotFoundException
     * @throws SameContactValueException
     * @throws ContactChangeInProgressException
     */
    public function initiateEmailChange(InitiateEmailChangeCommand $command): string
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException('Profile not found.');
        }

        if ($profile->email === $command->newEmail) {
            throw new SameContactValueException('New email matches the current email.');
        }

        if ($profile->pending_email !== null) {
            throw new ContactChangeInProgressException('An email change is already in progress.');
        }

        // Silent uniqueness check — identical response whether the email is in use or not.
        // A unique constraint at the DB level prevents commits of duplicate emails.

        // Generate OTP challenge via E01 OtpService (delivery to $command->newEmail).
        $challenge = $this->otp->generate(
            identifier:    $command->newEmail,
            challengeType: OtpChallengeType::EMAIL_CHANGE,
            userId:        $command->userId,
            correlationId: $command->correlationId,
        );

        $newEmailMasked   = $this->pii->maskEmail($command->newEmail);
        $oldEmailMasked   = $this->pii->maskEmail($profile->email ?? '');

        // Atomically persist pending state and publish outbox events within the same transaction
        // so a crash between the profile write and event publication cannot leave a silent gap.
        // Note: EventPublisher writes to platform.outbox_events (separate schema, same PostgreSQL
        // instance). PHP PDO does not support cross-connection transactions, so true XA atomicity
        // would require an ADR. For now, events are published inside the profile transaction to
        // minimise the window; a separate ADR can evaluate XA if stricter guarantees are required.
        DB::connection('profile')->transaction(function () use ($command, $challenge, $newEmailMasked, $oldEmailMasked): void {
            $this->profiles->update($command->userId, [
                'pending_email'              => $command->newEmail,
                'pending_email_challenge_id' => $challenge->id,
            ]);

            // Dual-event pattern: OTP notification to new address + security alert to old address.
            $this->events->publish(new EmailChangeInitiatedEvent(
                userId:         $command->userId,
                actorId:        $command->actorId,
                correlationId:  $command->correlationId,
                challengeId:    $challenge->id,
                newEmailMasked: $newEmailMasked,
            ));

            $this->events->publish(new ContactSecurityAlertEvent(
                userId:           $command->userId,
                actorId:          $command->actorId,
                correlationId:    $command->correlationId,
                contactType:      'email',
                oldContactMasked: $oldEmailMasked,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.user.email-change-initiated',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      ['new_email_masked' => $this->pii->maskEmail($command->newEmail)],
        ));

        $this->metrics->contactChangeInitiated('email');

        return $challenge->id;
    }

    /**
     * Verify the OTP and atomically promote the pending email to the live email.
     * Rolls back the profile record if the Identity module update fails — preserving the
     * pending challenge so the user may retry without re-initiating.
     *
     * @return string The masked new email address (for the API response).
     *
     * @throws ProfileNotFoundException
     * @throws ChallengeNotFoundException
     * @throws ContactOtpExpiredException  OTP expired or max-attempts reached; pending fields cleared.
     * @throws ContactOtpInvalidException  OTP code is wrong; pending fields preserved for retry.
     * @throws \App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException  Retryable; profile rolls back.
     */
    public function verifyEmailChange(VerifyEmailChangeCommand $command): string
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException('Profile not found.');
        }

        // Assert the pending challenge belongs to this verify request.
        if ($profile->pending_email_challenge_id !== $command->challengeId) {
            // Distinguish ALREADY_COMPLETED from NOT_FOUND: if this challenge was previously
            // consumed by a successful verify, return 409 instead of 404.
            if ($profile->last_completed_email_challenge_id === $command->challengeId) {
                throw new ChallengeAlreadyCompletedException(
                    'This email change challenge has already been verified.'
                );
            }
            throw new ChallengeNotFoundException(
                'No active email change challenge matches the provided challenge_id.'
            );
        }

        $newEmail = (string) $profile->pending_email;

        // Verify the OTP code via E01's OtpService.
        try {
            $this->otp->verify($command->challengeId, $command->otp);
        } catch (OtpInvalidOrExpiredException $e) {
            // expired() and maxAttemptsReached() both signal the challenge is consumed —
            // clear pending fields so the user must re-initiate.
            if (str_contains($e->getMessage(), 'expired') || str_contains($e->getMessage(), 'Maximum')) {
                $this->profiles->update($command->userId, [
                    'pending_email'              => null,
                    'pending_email_challenge_id' => null,
                ]);
                throw new ContactOtpExpiredException($e->getMessage(), 0, $e);
            }
            // invalidCode() — pending fields preserved; user may retry.
            throw new ContactOtpInvalidException($e->getMessage(), 0, $e);
        }

        // Atomically update the live email, clear pending fields, and record the completed challenge.
        // last_completed_email_challenge_id enables CHALLENGE_ALREADY_COMPLETED detection on retry.
        // If IdentityContactUpdateException is thrown, the transaction rolls back:
        // profile.email is restored and pending_email_challenge_id is preserved for retry.
        $completedChallengeId = $command->challengeId;
        DB::connection('profile')->transaction(function () use ($command, $newEmail, $completedChallengeId): void {
            $this->profiles->update($command->userId, [
                'email'                              => $newEmail,
                'pending_email'                      => null,
                'pending_email_challenge_id'         => null,
                'last_completed_email_challenge_id'  => $completedChallengeId,
            ]);
            $this->identity->updateUserContact($command->userId, $newEmail, null);
        });

        $maskedEmail = $this->pii->maskEmail($newEmail);

        $this->events->publish(new EmailChangedEvent(
            userId:         $command->userId,
            actorId:        $command->actorId,
            correlationId:  $command->correlationId,
            newEmailMasked: $maskedEmail,
        ));

        $this->audit->record(new AuditEventData(
            action:        'profile.user.email-changed',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      ['new_email_masked' => $maskedEmail],
        ));

        $this->metrics->contactChangeVerified('email');

        return $maskedEmail;
    }

    /**
     * Initiate a mobile number change for the authenticated user.
     * Validates E.164 format, generates OTP challenge, stores pending state, emits dual events.
     *
     * @return string The challenge_id the client must supply when verifying the OTP.
     *
     * @throws ProfileNotFoundException
     * @throws SameContactValueException
     * @throws ContactChangeInProgressException
     */
    public function initiateMobileChange(InitiateMobileChangeCommand $command): string
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException('Profile not found.');
        }

        if ($profile->mobile_number === $command->newMobile) {
            throw new SameContactValueException('New mobile matches the current mobile number.');
        }

        if ($profile->pending_mobile !== null) {
            throw new ContactChangeInProgressException('A mobile change is already in progress.');
        }

        // Silent uniqueness check — identical response whether the mobile is in use or not.
        // A unique constraint at the DB level prevents commits of duplicate mobile numbers.

        // Generate OTP challenge via E01 OtpService (delivery to $command->newMobile via SMS).
        $challenge = $this->otp->generate(
            identifier:    $command->newMobile,
            challengeType: OtpChallengeType::MOBILE_CHANGE,
            userId:        $command->userId,
            correlationId: $command->correlationId,
        );

        $newMobileMasked = $this->pii->maskMobile($command->newMobile);
        $oldMobileMasked = $this->pii->maskMobile($profile->mobile_number ?? '');

        // Atomically persist pending state and publish outbox events within the same transaction.
        // Cross-connection XA constraint applies identically to email change (see initiateEmailChange note).
        DB::connection('profile')->transaction(function () use ($command, $challenge, $newMobileMasked, $oldMobileMasked): void {
            $this->profiles->update($command->userId, [
                'pending_mobile'              => $command->newMobile,
                'pending_mobile_challenge_id' => $challenge->id,
            ]);

            // Dual-event pattern: OTP notification to new number + security alert to old number.
            $this->events->publish(new MobileChangeInitiatedEvent(
                userId:          $command->userId,
                actorId:         $command->actorId,
                correlationId:   $command->correlationId,
                challengeId:     $challenge->id,
                newMobileMasked: $newMobileMasked,
            ));

            $this->events->publish(new ContactSecurityAlertEvent(
                userId:           $command->userId,
                actorId:          $command->actorId,
                correlationId:    $command->correlationId,
                contactType:      'mobile',
                oldContactMasked: $oldMobileMasked,
            ));
        });

        $this->audit->record(new AuditEventData(
            action:        'profile.user.mobile-change-initiated',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      ['new_mobile_masked' => $newMobileMasked],
        ));

        $this->metrics->contactChangeInitiated('mobile');

        return $challenge->id;
    }

    /**
     * Verify the OTP and atomically promote the pending mobile to the live mobile number.
     * Rolls back the profile record if the Identity module update fails — preserving the
     * pending challenge so the user may retry without re-initiating.
     *
     * @return string The masked new mobile number (for the API response).
     *
     * @throws ProfileNotFoundException
     * @throws ChallengeNotFoundException
     * @throws ChallengeAlreadyCompletedException
     * @throws ContactOtpExpiredException  OTP expired or max-attempts reached; pending fields cleared.
     * @throws ContactOtpInvalidException  OTP code is wrong; pending fields preserved for retry.
     * @throws \App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException  Retryable; profile rolls back.
     */
    public function verifyMobileChange(VerifyMobileChangeCommand $command): string
    {
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException('Profile not found.');
        }

        // Assert the pending challenge belongs to this verify request.
        if ($profile->pending_mobile_challenge_id !== $command->challengeId) {
            // Distinguish ALREADY_COMPLETED from NOT_FOUND: if this challenge was previously
            // consumed by a successful verify, return 409 instead of 404.
            if ($profile->last_completed_mobile_challenge_id === $command->challengeId) {
                throw new ChallengeAlreadyCompletedException(
                    'This mobile change challenge has already been verified.'
                );
            }
            throw new ChallengeNotFoundException(
                'No active mobile change challenge matches the provided challenge_id.'
            );
        }

        $newMobile = (string) $profile->pending_mobile;

        // Verify the OTP code via E01's OtpService.
        try {
            $this->otp->verify($command->challengeId, $command->otp);
        } catch (OtpInvalidOrExpiredException $e) {
            // expired() and maxAttemptsReached() both signal the challenge is consumed —
            // clear pending fields so the user must re-initiate.
            if (str_contains($e->getMessage(), 'expired') || str_contains($e->getMessage(), 'Maximum')) {
                $this->profiles->update($command->userId, [
                    'pending_mobile'              => null,
                    'pending_mobile_challenge_id' => null,
                ]);
                throw new ContactOtpExpiredException($e->getMessage(), 0, $e);
            }
            // invalidCode() — pending fields preserved; user may retry.
            throw new ContactOtpInvalidException($e->getMessage(), 0, $e);
        }

        // Atomically update the live mobile, clear pending fields, and record the completed challenge.
        // last_completed_mobile_challenge_id enables CHALLENGE_ALREADY_COMPLETED detection on retry.
        // If IdentityContactUpdateException is thrown, the transaction rolls back:
        // profile.mobile_number is restored and pending_mobile_challenge_id is preserved for retry.
        $completedChallengeId = $command->challengeId;
        DB::connection('profile')->transaction(function () use ($command, $newMobile, $completedChallengeId): void {
            $this->profiles->update($command->userId, [
                'mobile_number'                        => $newMobile,
                'pending_mobile'                       => null,
                'pending_mobile_challenge_id'          => null,
                'last_completed_mobile_challenge_id'   => $completedChallengeId,
            ]);
            $this->identity->updateUserContact($command->userId, null, $newMobile);
        });

        $maskedMobile = $this->pii->maskMobile($newMobile);

        $this->events->publish(new MobileChangedEvent(
            userId:          $command->userId,
            actorId:         $command->actorId,
            correlationId:   $command->correlationId,
            newMobileMasked: $maskedMobile,
        ));

        $this->audit->record(new AuditEventData(
            action:        'profile.user.mobile-changed',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      ['new_mobile_masked' => $maskedMobile],
        ));

        $this->metrics->contactChangeVerified('mobile');

        return $maskedMobile;
    }
}
