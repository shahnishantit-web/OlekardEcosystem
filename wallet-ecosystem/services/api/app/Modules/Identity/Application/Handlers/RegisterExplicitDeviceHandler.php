<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\RegisterExplicitDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceRegisteredEvent;
use App\Modules\Identity\Domain\Exceptions\DeviceLimitExceededException;
use App\Modules\Identity\Domain\Services\DeviceService;
use App\Modules\Identity\Domain\Services\IdentityMetrics;
use App\Modules\Identity\Infrastructure\Jobs\SuspiciousDeviceDetectionJob;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class RegisterExplicitDeviceHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface           $devices,
        private readonly DeviceTrustHistoryRepositoryInterface $trustHistory,
        private readonly EventPublisherInterface             $events,
        private readonly AuditServiceInterface               $audit,
        private readonly ConfigurationServiceInterface       $config,
        private readonly IdempotencyServiceInterface         $idempotency,
        private readonly IdentityMetrics                     $metrics,
        private readonly DeviceService                      $deviceService,
        private readonly UserRepositoryInterface            $users,
    ) {}

    public function handle(RegisterExplicitDeviceCommand $command): array
    {
        // (1) Check idempotency cache
        $cached = $this->idempotency->findCompletedResponse($command->idempotencyKey, $command->userId);
        if ($cached !== null) {
            return array_merge($cached['body'], ['_idempotent' => true]);
        }

        // (2) Check if device already registered for this user+fingerprint
        $existing = $this->devices->findByUserAndFingerprint($command->userId, $command->fingerprintHash);
        if ($existing !== null) {
            $result = [
                'device_id'    => $existing->id,
                'status'       => $existing->status,
                'challenge_id' => null,
                '_idempotent'  => true,
            ];

            $lock = $this->idempotency->acquireLock(
                $command->idempotencyKey,
                $command->userId,
                hash('sha256', json_encode($command) ?: ''),
                'POST',
                '/v1/devices',
                $command->correlationId,
            );
            $this->idempotency->recordResponse($lock, 200, $result);

            return $result;
        }

        // (3) Check device limit
        $maxDevices = (int) $this->config->getOrDefault('device.max_devices_per_user', '10');
        $count      = $this->devices->countForUser($command->userId);

        if ($count >= $maxDevices) {
            throw DeviceLimitExceededException::make();
        }

        // Acquire idempotency lock before transaction
        $lock = $this->idempotency->acquireLock(
            $command->idempotencyKey,
            $command->userId,
            hash('sha256', json_encode($command) ?: ''),
            'POST',
            '/v1/devices',
            $command->correlationId,
        );

        $result = null;

        // (4) DB transaction: create device, trust history, publish event, audit
        DB::connection('platform')->transaction(function () use ($command, &$result): void {
            $device = $this->devices->create([
                'user_id'            => $command->userId,
                'device_fingerprint' => $command->fingerprintHash,
                'device_name'        => $command->deviceName,
                'platform'           => $command->platform,
                'status'             => 'pending_verification',
                'last_seen_at'       => now(),
            ]);

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $device->id,
                'user_id'        => $command->userId,
                'from_status'    => null,
                'to_status'      => 'pending_verification',
                'actor_id'       => $command->userId,
                'actor_type'     => $command->actorType,
                'reason'         => 'device_registration',
                'correlation_id' => $command->correlationId,
                'created_at'     => now(),
            ]);

            $this->events->publish(new DeviceRegisteredEvent(
                deviceId:      $device->id,
                actorId:       $command->userId,
                correlationId: $command->correlationId,
                userId:        $command->userId,
                platform:      $command->platform,
                deviceName:    $command->deviceName,
                status:        'pending_verification',
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.registered',
                actorType:     $command->actorType,
                resourceType:  'identity.device',
                correlationId: $command->correlationId,
                outcome:       'success',
                actorId:       $command->userId,
                resourceId:    $device->id,
                afterState:    ['platform' => $command->platform, 'status' => 'pending_verification'],
                ipAddress:     $command->ipHash,
            ));

            $result = [
                'device_id'   => $device->id,
                'status'      => 'pending_verification',
                'challenge_id' => null,
            ];
        });

        // (5) Initiate OTP challenge for the newly registered device
        if ($result !== null) {
            $user = $this->users->findById($command->userId);
            if ($user !== null) {
                try {
                    $challengeId = $this->deviceService->initiateVerification(
                        userId:            $command->userId,
                        deviceId:          $result['device_id'],
                        mobileIdentifier:  $user->phone,
                        correlationId:     $command->correlationId,
                    );
                    $result['challenge_id'] = $challengeId;
                } catch (\Throwable) {
                    // OTP delivery failure is non-fatal: device is registered, client can retry
                }
            }
        }

        // (5b) Dispatch suspicious detection job after transaction (non-blocking)
        if ($result !== null) {
            SuspiciousDeviceDetectionJob::dispatch(
                $result['device_id'],
                $command->userId,
                $command->fingerprintHash,
                false,
            );
        }

        // (6) Store idempotency response
        $this->idempotency->recordResponse($lock, 201, $result ?? []);

        // (7) Increment metric
        $this->metrics->incrementDeviceRegistered($command->platform);

        return $result ?? [];
    }
}
