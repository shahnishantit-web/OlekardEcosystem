<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\RegisterUserCommand;
use App\Modules\Identity\Application\Commands\ResendOtpCommand;
use App\Modules\Identity\Application\Commands\VerifyOtpCommand;
use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Domain\Events\RegistrationOtpRequestedEvent;
use App\Modules\Identity\Domain\Events\RegistrationOtpResendRequestedEvent;
use App\Modules\Identity\Domain\Events\UserRegisteredEvent;
use App\Modules\Identity\Domain\Exceptions\IdentifierAlreadyRegisteredException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;

final class RegistrationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly OtpChallengeRepositoryInterface $challenges,
        private readonly OtpService $otpService,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
        private readonly RoleRepositoryInterface $roles,
    ) {}

    /**
     * Initiate registration: create a pending user record and send OTP.
     * Identifier uniqueness is validated without disclosing which identifier triggered the conflict (BR-014).
     */
    public function initiateRegistration(RegisterUserCommand $cmd): OtpChallenge
    {
        return DB::connection('platform')->transaction(function () use ($cmd): OtpChallenge {
            // Uniqueness check inside the transaction prevents TOCTOU race conditions (BR-014).
            if ($cmd->mobileNumber !== null && $this->users->findByMobile($cmd->mobileNumber) !== null) {
                throw IdentifierAlreadyRegisteredException::make();
            }
            if ($cmd->email !== null && $this->users->findByEmail($cmd->email) !== null) {
                throw IdentifierAlreadyRegisteredException::make();
            }

            $identifier = $cmd->mobileNumber ?? $cmd->email;

            $customerRole = $this->roles->findBySlug('customer');

            $user = $this->users->create([
                'mobile_number'   => $cmd->mobileNumber,
                'email'           => $cmd->email,
                'status'          => 'pending_verification',
                'actor_type'      => $cmd->actorType,
                'default_role_id' => $customerRole?->id,
            ]);

            $challenge = $this->otpService->generate(
                identifier:    $identifier,
                challengeType: OtpChallengeType::REGISTRATION,
                userId:        $user->id,
                correlationId: $cmd->correlationId,
            );

            $maskedIdentifier = $this->maskIdentifier($identifier);

            $this->events->publishAll([
                new UserRegisteredEvent(
                    userId:           $user->id,
                    actorId:          null,
                    correlationId:    $cmd->correlationId,
                    actorType:        $cmd->actorType,
                    maskedIdentifier: $maskedIdentifier,
                    status:           'pending_verification',
                ),
                new RegistrationOtpRequestedEvent(
                    userId:            $user->id,
                    actorId:           null,
                    correlationId:     $cmd->correlationId,
                    challengeId:       $challenge->id,
                    channelType:       str_contains($identifier, '@') ? 'email' : 'sms',
                    maskedDestination: $maskedIdentifier,
                ),
            ]);

            $this->audit->record(new AuditEventData(
                action:        'identity.user.registered',
                actorType:     $cmd->actorType,
                resourceType:  'identity.user',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                resourceId:    $user->id,
                afterState:    ['status' => 'pending_verification', 'masked_identifier' => $maskedIdentifier],
                ipAddress:     $cmd->ipHash,
            ));

            return $challenge;
        });
    }

    /**
     * Verify OTP to complete the registration step.
     * Returns the OTP challenge on success; does NOT issue access tokens (PIN must be set next).
     */
    public function verifyOtp(VerifyOtpCommand $cmd): OtpChallenge
    {
        return $this->otpService->verify($cmd->challengeId, $cmd->otp);
    }

    /**
     * Resend OTP for an existing pending registration challenge.
     */
    public function resendOtp(ResendOtpCommand $cmd): OtpChallenge
    {
        $challenge = $this->challenges->findById($cmd->challengeId);

        if ($challenge === null || $challenge->status !== 'pending') {
            throw OtpInvalidOrExpiredException::challengeNotFound();
        }

        return DB::connection('platform')->transaction(function () use ($cmd, $challenge): OtpChallenge {
            $updated = $this->otpService->resend($cmd->challengeId, $cmd->correlationId);

            $maskedDestination = $this->maskIdentifier($challenge->identifier);

            $this->events->publish(new RegistrationOtpResendRequestedEvent(
                userId:            (string) ($challenge->user_id ?? '00000000-0000-0000-0000-000000000000'),
                actorId:           null,
                correlationId:     $cmd->correlationId,
                challengeId:       $updated->id,
                channelType:       str_contains($challenge->identifier, '@') ? 'email' : 'sms',
                maskedDestination: $maskedDestination,
            ));

            // identity.otp.sent: justified extension (FR-E01-003) — tracks OTP delivery for resend auditing; not in Section 16 18-action table.
            $this->audit->record(new AuditEventData(
                action:        'identity.otp.sent',
                actorType:     'CUSTOMER',
                resourceType:  'identity.otp_challenge',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                resourceId:    $updated->id,
                afterState:    ['challenge_type' => $challenge->challenge_type, 'masked_destination' => $maskedDestination],
                ipAddress:     $cmd->ipHash,
            ));

            return $updated;
        });
    }

    private function maskIdentifier(string $identifier): string
    {
        return str_contains($identifier, '@')
            ? PiiMasker::maskEmail($identifier)
            : PiiMasker::maskPhone($identifier);
    }
}
