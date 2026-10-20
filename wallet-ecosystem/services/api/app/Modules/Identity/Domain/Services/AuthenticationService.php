<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\LoginWithBiometricCommand;
use App\Modules\Identity\Application\Commands\LoginWithOtpCommand;
use App\Modules\Identity\Application\Commands\LoginWithPasswordCommand;
use App\Modules\Identity\Application\Commands\LoginWithPinCommand;
use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Domain\Contracts\BiometricAssertionContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\LoginFailedEvent;
use App\Modules\Identity\Domain\Events\SessionCreatedEvent;
use App\Modules\Identity\Domain\Exceptions\AccountDisabledException;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceVerificationRequiredException;
use App\Modules\Identity\Domain\Exceptions\EmailNotVerifiedException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\MfaRequiredException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\ValueObjects\CredentialType;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Orchestrates all credential-based login paths.
 * Identity and credential-type disclosure are prevented on every failure path (BR-014).
 */
final class AuthenticationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly CredentialRepositoryInterface $credentials,
        private readonly OtpService $otpService,
        private readonly LockoutService $lockout,
        private readonly DeviceService $deviceService,
        private readonly TokenServiceContract $tokenService,
        private readonly BiometricAssertionContract $biometric,
        private readonly MfaService $mfa,
        private readonly ConfigurationSecurityService $secConfig,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
        private readonly IdentityMetrics $metrics,
    ) {}

    public function loginWithPin(LoginWithPinCommand $cmd): AuthenticatedSessionDTO
    {
        $user = $this->resolveUserByIdentifier($cmd->identifier);

        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $maskedIdentifier = PiiMasker::maskPhone($cmd->identifier);
        $this->lockout->assertNotLocked($user, $cmd->correlationId, $cmd->ipHash);

        $credential = $this->credentials->findByUserAndType($user->id, CredentialType::PIN->value);

        // AC-011: blocked PIN credential is rejected before hash verification
        if ($credential !== null && $credential->status === 'blocked') {
            throw PinBlockedException::make();
        }

        if ($credential === null || ! password_verify($cmd->pin, $credential->credential_hash)) {
            $this->lockout->recordFailure($user, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier);
            $this->emitLoginFailedAudit($user->id, $user->actor_type, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'invalid_pin');
            throw InvalidCredentialsException::make();
        }

        return $this->completeLogin($user, $cmd->rawDeviceFingerprint, $cmd->deviceName, $cmd->platform, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'pin');
    }

    public function loginWithPassword(LoginWithPasswordCommand $cmd): AuthenticatedSessionDTO
    {
        $user = $this->resolveUserByIdentifier($cmd->identifier);

        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $userStatus = (string) $user->getAttribute('status');
        if (! in_array($userStatus, ['active', 'pending_verification'], true)) {
            throw AccountDisabledException::make();
        }

        // Admin accounts in pending_verification have not yet verified their email
        $actorType = (string) $user->getAttribute('actor_type');
        if ($userStatus === 'pending_verification' && $actorType === 'ADMIN') {
            throw EmailNotVerifiedException::make();
        }

        $maskedIdentifier = $this->maskIdentifier($cmd->identifier);
        $this->lockout->assertNotLocked($user, $cmd->correlationId, $cmd->ipHash);

        $credential = $this->credentials->findByUserAndType($user->id, CredentialType::PASSWORD->value);

        if ($credential === null || ! Hash::check($cmd->password, $credential->credential_hash)) {
            $this->lockout->recordFailure($user, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier);
            $this->emitLoginFailedAudit($user->id, $user->actor_type, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'invalid_password');
            throw InvalidCredentialsException::make();
        }

        app(RuntimeSecurityPolicy::class)->assertPasswordCurrent($credential);

        return $this->completeLogin($user, $cmd->rawDeviceFingerprint, $cmd->deviceName, $cmd->platform, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'password');
    }

    public function loginWithOtp(LoginWithOtpCommand $cmd): AuthenticatedSessionDTO
    {
        // Peek at challenge user_id for lockout enforcement before consuming the OTP
        $userId = $this->otpService->peekUserId($cmd->challengeId);
        $user = $userId ? $this->users->findById($userId) : null;

        if ($user !== null) {
            $this->lockout->assertNotLocked($user, $cmd->correlationId, $cmd->ipHash);
        }

        try {
            $challenge = $this->otpService->verify($cmd->challengeId, $cmd->otp);
        } catch (OtpInvalidOrExpiredException $e) {
            if ($user !== null) {
                $this->lockout->recordFailure($user, $cmd->correlationId, $cmd->ipHash, PiiMasker::maskPhone($userId ?? ''));
            }
            throw $e;
        }

        $user = $this->users->findById($challenge->user_id);

        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $maskedIdentifier = $this->maskIdentifier($challenge->identifier);

        return $this->completeLogin($user, $cmd->rawDeviceFingerprint, $cmd->deviceName, $cmd->platform, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'otp');
    }

    public function loginWithBiometric(LoginWithBiometricCommand $cmd): AuthenticatedSessionDTO
    {
        $user = $this->users->findById($cmd->userId);

        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $maskedIdentifier = $user->mobile_number
            ? PiiMasker::maskPhone($user->mobile_number)
            : PiiMasker::maskEmail($user->email ?? '');

        $this->lockout->assertNotLocked($user, $cmd->correlationId, $cmd->ipHash);

        $credential = $this->credentials->findByUserAndType($user->id, CredentialType::BIOMETRIC_BINDING->value);

        if ($credential === null) {
            throw InvalidCredentialsException::make();
        }

        // TBD-004: BiometricAssertionContract abstracts the SDK; no SDK chosen yet
        // verify(challenge/nonce, assertion, registeredPublicKey)
        $result = $this->biometric->verify($cmd->nonce, $cmd->signedAssertion, (string) $credential->biometric_public_key);

        if (! $result->verified) {
            $this->lockout->recordFailure($user, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier);
            $this->metrics->authFailure($user->actor_type, 'invalid_biometric');
            throw InvalidCredentialsException::make();
        }

        return $this->completeLogin($user, $cmd->rawDeviceFingerprint, $cmd->deviceName, $cmd->platform, $cmd->correlationId, $cmd->ipHash, $maskedIdentifier, 'biometric');
    }

    private function completeLogin(
        User $user,
        string $rawDeviceFingerprint,
        string $deviceName,
        string $platform,
        string $correlationId,
        string $ipHash,
        string $maskedIdentifier,
        string $credentialType,
    ): AuthenticatedSessionDTO {
        $device = $this->deviceService->resolveDevice(
            userId: $user->id,
            rawClientFingerprint: $rawDeviceFingerprint,
            deviceName: $deviceName,
            platform: $platform,
            correlationId: $correlationId,
            maskedIdentifier: $maskedIdentifier,
        );

        // Credential was verified — reset lockout counter regardless of device state.
        $this->lockout->recordSuccess($user);

        // AC-015: blocked device must be rejected before token issuance.
        if ($device->status === 'blocked') {
            throw DeviceBlockedException::make();
        }

        if ($device->status !== 'trusted') {
            // Device not yet trusted (AC-009): create a device_verification OTP challenge.
            // Session is only granted after the user completes device verification.
            // Pass the pending fingerprint hash so verifyDeviceByOtp() can update the stored
            // hash on success, preventing re-detection loops (FR-E03-046).
            $mobileIdentifier = $user->mobile_number ?? ($user->email ?? $user->id);
            $pendingFingerprintHash = hash('sha256', $rawDeviceFingerprint);
            $challengeId = $this->deviceService->initiateVerification(
                userId: $user->id,
                deviceId: $device->id,
                mobileIdentifier: $mobileIdentifier,
                correlationId: $correlationId,
                pendingFingerprintHash: $pendingFingerprintHash,
            );
            throw new DeviceVerificationRequiredException($challengeId);
        }

        // MFA gate (FR-E01-036; AC-018): if user has an active MFA credential, issue
        // an mfa_pending token instead of session tokens. AC-028 prevents Admin bypass.
        $mfaMethods = $this->mfa->getMfaMethods($user->id);

        // Compute MFA enrollment gate before entering the session-creation transaction.
        // Flag is true when: actor is ADMIN, policy mandates MFA, and no method is enrolled.
        $mfaEnrollmentRequired = empty($mfaMethods)
            && ($this->secConfig->isAdminMfaRequired() || app(RuntimeSecurityPolicy::class)->requiresMfa($user->id, (string) $user->getAttribute('actor_type')));

        if (count($mfaMethods) > 0) {
            $mfaToken = $this->mfa->issueMfaPendingToken(
                userId: $user->id,
                deviceId: $device->id,
                actorType: $user->actor_type,
                correlationId: $correlationId,
            );
            throw new MfaRequiredException(
                mfaToken: $mfaToken,
                methods: $mfaMethods,
                expiresIn: 300,
            );
        }

        $dto = DB::connection('platform')->transaction(function () use ($user, $device, $correlationId, $ipHash, $maskedIdentifier, $mfaEnrollmentRequired): AuthenticatedSessionDTO {
            $this->users->updateLastLogin($user->id);

            $tokenPair = $this->tokenService->issueTokenPair(
                user: $user,
                deviceId: $device->id,
                actorType: $user->actor_type,
                ipHash: $ipHash,
                correlationId: $correlationId,
            );

            $this->events->publish(new SessionCreatedEvent(
                sessionId: $tokenPair->sessionId,
                actorId: $user->id,
                correlationId: $correlationId,
                userId: $user->id,
                actorType: $user->actor_type,
                deviceId: $device->id,
            ));

            $this->audit->record(new AuditEventData(
                action: 'identity.session.created',
                actorType: $user->actor_type,
                resourceType: 'identity.session',
                correlationId: $correlationId,
                outcome: 'success',
                actorId: $user->id,
                resourceId: $tokenPair->sessionId,
                afterState: [
                    'masked_identifier' => $maskedIdentifier,
                    'device_id' => $device->id,
                ],
                ipAddress: $ipHash,
            ));

            return new AuthenticatedSessionDTO(
                userId: $tokenPair->userId,
                sessionId: $tokenPair->sessionId,
                accessToken: $tokenPair->accessToken,
                refreshToken: $tokenPair->refreshToken,
                tokenType: $tokenPair->tokenType,
                expiresIn: $tokenPair->expiresIn,
                mfaEnrollmentRequired: $mfaEnrollmentRequired,
            );
        });

        $this->metrics->authSuccess($user->actor_type, $credentialType);

        return $dto;
    }

    private function emitLoginFailedAudit(
        string $userId,
        string $actorType,
        string $correlationId,
        string $ipHash,
        string $maskedIdentifier,
        string $reason,
    ): void {
        DB::connection('platform')->transaction(function () use ($userId, $actorType, $correlationId, $ipHash, $maskedIdentifier, $reason): void {
            $this->events->publish(new LoginFailedEvent(
                userId: $userId,
                actorId: $userId,
                correlationId: $correlationId,
                actorType: $actorType,
                maskedIdentifier: $maskedIdentifier,
                reason: $reason,
            ));

            $this->audit->record(new AuditEventData(
                action: 'identity.session.login-failed',
                actorType: $actorType,
                resourceType: 'identity.user',
                correlationId: $correlationId,
                outcome: 'failure',
                resourceId: $userId,
                afterState: ['masked_identifier' => $maskedIdentifier, 'reason' => $reason],
                ipAddress: $ipHash,
            ));
        });

        $this->metrics->authFailure($actorType, $reason);
    }

    private function resolveUserByIdentifier(string $identifier): ?User
    {
        return str_contains($identifier, '@')
            ? $this->users->findByEmail($identifier)
            : $this->users->findByMobile($identifier);
    }

    private function maskIdentifier(string $identifier): string
    {
        return str_contains($identifier, '@')
            ? PiiMasker::maskEmail($identifier)
            : PiiMasker::maskPhone($identifier);
    }
}
