<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceVerifiedEvent;
use App\Modules\Identity\Domain\Events\NewDeviceDetectedEvent;
use App\Modules\Identity\Domain\Events\SessionCreatedEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Detects new devices and manages device trust state.
 * device_fingerprint stored as SHA-256(clientFingerprint) — never raw fingerprint.
 */
final class DeviceService
{
    public function __construct(
        private readonly DeviceRepositoryInterface  $devices,
        private readonly EventPublisherInterface    $events,
        private readonly OtpService                 $otpService,
        private readonly ConfigurationSecurityService $config,
        private readonly TokenServiceContract       $tokenService,
        private readonly UserRepositoryInterface    $users,
        private readonly AuditServiceInterface      $audit,
    ) {}

    /**
     * Resolve the device for a login attempt.
     * Returns the existing trusted device or creates a new (untrusted) record.
     * Emits NewDeviceDetected when a device is seen for the first time.
     */
    public function resolveDevice(
        string $userId,
        string $rawClientFingerprint,
        string $deviceName,
        string $platform,
        string $correlationId,
        string $maskedIdentifier,
    ): Device {
        $fingerprintHash = hash('sha256', $rawClientFingerprint);

        $device = $this->devices->findByUserAndFingerprint($userId, $fingerprintHash);

        if ($device !== null) {
            $this->devices->updateLastSeen($device->id);
            return $device->fresh();
        }

        // First time seeing this device
        return DB::connection('platform')->transaction(function () use ($userId, $fingerprintHash, $deviceName, $platform, $correlationId, $maskedIdentifier): Device {
            $device = $this->devices->create([
                'user_id'            => $userId,
                'device_fingerprint' => $fingerprintHash,
                'device_name'        => $deviceName,
                'platform'           => $platform,
                'status'             => 'unverified',
                'last_seen_at'       => now(),
            ]);

            $this->events->publish(new NewDeviceDetectedEvent(
                userId:           $userId,
                actorId:          null,
                correlationId:    $correlationId,
                deviceId:         $device->id,
                maskedIdentifier: $maskedIdentifier,
            ));

            return $device;
        });
    }

    public function isDeviceTrusted(string $userId, string $rawClientFingerprint): bool
    {
        $fingerprintHash = hash('sha256', $rawClientFingerprint);
        $device = $this->devices->findByUserAndFingerprint($userId, $fingerprintHash);

        return $device !== null && $device->status === 'trusted';
    }

    /**
     * T096 (Phase 9): Generate a device_verification OTP challenge for a new device.
     * Called by AuthenticationService when an untrusted device is detected after credential verification.
     * Stores device_id in Redis keyed by challenge_id for lookup during verification.
     *
     * Returns the challenge_id to be surfaced to the client.
     */
    public function initiateVerification(
        string $userId,
        string $deviceId,
        string $mobileIdentifier,
        string $correlationId,
    ): string {
        $challenge = $this->otpService->generate(
            identifier:    $mobileIdentifier,
            challengeType: OtpChallengeType::DEVICE_VERIFICATION,
            userId:        $userId,
            correlationId: $correlationId,
        );

        // Map challenge_id → device_id so verifyDeviceByOtp() knows which device to trust.
        $ttl   = $this->config->getOtpTtl() + 60;
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $redis->setex(IdentityRedisKeys::deviceVerifyChallenge($challenge->id), $ttl, $deviceId);

        return $challenge->id;
    }

    /**
     * T096 (Phase 9): Verify the device OTP, promote device to trusted, create full session.
     * On success: device.status = 'trusted', trusted_at = now(), session issued.
     * Emits Identity.Device.Verified + Identity.Session.Created outbox events.
     * Emits identity.device.registered + identity.session.created audit records.
     */
    public function verifyDeviceByOtp(
        string $challengeId,
        string $otpInput,
        string $correlationId,
        string $ipHash,
    ): AuthenticatedSessionDTO {
        // 1. Verify OTP (throws OtpInvalidOrExpiredException on failure)
        $challenge = $this->otpService->verify($challengeId, $otpInput);

        // 2. Resolve the device_id stored during initiateVerification()
        $redis    = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $deviceId = $redis->get(IdentityRedisKeys::deviceVerifyChallenge($challengeId));

        if ($deviceId === null || $deviceId === '') {
            throw InvalidCredentialsException::make();
        }

        // 3. Find device and verify ownership
        $device = $this->devices->findById($deviceId);
        if ($device === null || (string) $device->user_id !== (string) $challenge->user_id) {
            throw InvalidCredentialsException::make();
        }

        // 5. Resolve user before opening the transaction
        $user = $this->users->findById((string) $challenge->user_id);
        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        // 4–8. Promote device, create session, emit events — all atomic (FR-050)
        $result = DB::connection('platform')->transaction(function () use ($user, $deviceId, $correlationId, $ipHash): AuthenticatedSessionDTO {
            $this->devices->updateStatus($deviceId, 'trusted', ['trusted_at' => now()]);
            $this->users->updateLastLogin($user->id);

            $tokenPair = $this->tokenService->issueTokenPair(
                user:          $user,
                deviceId:      $deviceId,
                actorType:     $user->actor_type,
                ipHash:        $ipHash,
                correlationId: $correlationId,
            );

            $this->events->publish(new DeviceVerifiedEvent(
                userId:        $user->id,
                actorId:       $user->id,
                correlationId: $correlationId,
                deviceId:      $deviceId,
            ));

            $this->events->publish(new SessionCreatedEvent(
                sessionId:     $tokenPair->sessionId,
                actorId:       $user->id,
                correlationId: $correlationId,
                userId:        $user->id,
                actorType:     $user->actor_type,
                deviceId:      $deviceId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.new-device-detected',
                actorType:     $user->actor_type,
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $user->id,
                resourceId:    $deviceId,
                afterState:    ['status' => 'trusted'],
                ipAddress:     $ipHash,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.session.created',
                actorType:     $user->actor_type,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $user->id,
                resourceId:    $tokenPair->sessionId,
                afterState:    ['device_id' => $deviceId, 'via' => 'device_verification'],
                ipAddress:     $ipHash,
            ));

            return new AuthenticatedSessionDTO(
                userId:       $user->id,
                sessionId:    $tokenPair->sessionId,
                accessToken:  $tokenPair->accessToken,
                refreshToken: $tokenPair->refreshToken,
                tokenType:    $tokenPair->tokenType,
                expiresIn:    $tokenPair->expiresIn,
            );
        });

        // 9. Clean up Redis key after transaction commits (consumed)
        $redis->del(IdentityRedisKeys::deviceVerifyChallenge($challengeId));

        return $result;
    }
}
