<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Commands\DetectDeviceChangeCommand;
use App\Modules\Identity\Application\Handlers\DetectDeviceChangeHandler;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Services\DeviceService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * T156 — FR-E03-046: fingerprint update after OTP re-verification.
 *
 * After successful OTP re-verification the stored device_fingerprint MUST be
 * replaced with the newly verified hash so subsequent authentications do not
 * re-trigger change detection.  If OTP verification fails the hash MUST remain
 * unchanged and the device MUST stay in pending_verification.
 */

$capturedOtp = null;

beforeEach(function () use (&$capturedOtp): void {
    Queue::fake();

    $capturedOtp = null;

    $this->app->bind(OtpDeliveryContract::class, function () use (&$capturedOtp) {
        return new class ($capturedOtp) implements OtpDeliveryContract {
            public function __construct(private mixed &$capturedOtp) {}

            public function deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult
            {
                $this->capturedOtp = $otp;
                return new OtpDeliveryResult(true, 'stub-' . uniqid(), null);
            }
        };
    });

    Redis::connection('identity')->flushdb();

    $this->userId        = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->deviceId      = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->originalHash  = hash('sha256', 'original-fingerprint');
    $this->newHash       = hash('sha256', 'updated-fingerprint');

    \DB::connection('identity')->table('identity.users')->insert([
        'id'            => $this->userId,
        'email'         => 'fp-update@test.local',
        'mobile_number' => '+26775600001',
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $this->deviceId,
        'user_id'            => $this->userId,
        'device_name'        => 'My Phone',
        'platform'           => 'android',
        'device_fingerprint' => $this->originalHash,
        'status'             => 'trusted',
        'trusted_at'         => now(),
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
});

describe('FR-E03-046 — fingerprint updated after successful re-verification', function () use (&$capturedOtp): void {

    it('stores pending fingerprint in Redis and updates device after OTP success', function () use (&$capturedOtp): void {
        $deviceService = app(DeviceService::class);
        $devices       = app(DeviceRepositoryInterface::class);

        // Demote device to pending_verification (simulates DetectDeviceChangeHandler)
        $handler = app(DetectDeviceChangeHandler::class);
        $handler->handle(new DetectDeviceChangeCommand(
            deviceId:                $this->deviceId,
            userId:                  $this->userId,
            submittedFingerprintHash: $this->newHash,
            actorType:               'CUSTOMER',
            correlationId:           (string) \Ramsey\Uuid\Uuid::uuid7(),
            ipHash:                  str_repeat('a', 64),
        ));

        $device = $devices->findById($this->deviceId);
        expect($device->status)->toBe('pending_verification');
        expect($device->device_fingerprint)->toBe($this->originalHash);

        // Initiate verification with pending fingerprint
        $challengeId = $deviceService->initiateVerification(
            userId:                $this->userId,
            deviceId:              $this->deviceId,
            mobileIdentifier:      '+26775600001',
            correlationId:         (string) \Ramsey\Uuid\Uuid::uuid7(),
            pendingFingerprintHash: $this->newHash,
        );

        expect($capturedOtp)->not->toBeNull();

        // Verify OTP — triggers fingerprint update
        $deviceService->verifyDeviceByOtp(
            challengeId:   $challengeId,
            otpInput:      $capturedOtp,
            correlationId: (string) \Ramsey\Uuid\Uuid::uuid7(),
            ipHash:        str_repeat('b', 64),
        );

        $updated = $devices->findById($this->deviceId);
        expect($updated->status)->toBe('trusted');
        expect($updated->device_fingerprint)->toBe($this->newHash);
    });

    it('does not update fingerprint when OTP fails — device stays in pending_verification', function () use (&$capturedOtp): void {
        $deviceService = app(DeviceService::class);
        $devices       = app(DeviceRepositoryInterface::class);

        // Demote device
        $devices->updateStatus($this->deviceId, 'pending_verification');

        // Initiate verification with pending fingerprint
        $deviceService->initiateVerification(
            userId:                $this->userId,
            deviceId:              $this->deviceId,
            mobileIdentifier:      '+26775600001',
            correlationId:         (string) \Ramsey\Uuid\Uuid::uuid7(),
            pendingFingerprintHash: $this->newHash,
        );

        // Submit wrong OTP (invalid challenge triggers exception)
        $threw = false;
        try {
            $deviceService->verifyDeviceByOtp(
                challengeId:   (string) \Ramsey\Uuid\Uuid::uuid7(),
                otpInput:      '000000',
                correlationId: (string) \Ramsey\Uuid\Uuid::uuid7(),
                ipHash:        str_repeat('c', 64),
            );
        } catch (\Throwable) {
            $threw = true;
        }
        expect($threw)->toBeTrue();

        // Fingerprint and status must be unchanged
        $device = $devices->findById($this->deviceId);
        expect($device->status)->toBe('pending_verification');
        expect($device->device_fingerprint)->toBe($this->originalHash);
    });

    it('subsequent login with new fingerprint succeeds after re-verification (no re-detection)', function () use (&$capturedOtp): void {
        $deviceService = app(DeviceService::class);

        // Initiate verification — pending fingerprint is the new hash
        $challengeId = $deviceService->initiateVerification(
            userId:                $this->userId,
            deviceId:              $this->deviceId,
            mobileIdentifier:      '+26775600001',
            correlationId:         (string) \Ramsey\Uuid\Uuid::uuid7(),
            pendingFingerprintHash: $this->newHash,
        );

        $deviceService->verifyDeviceByOtp(
            challengeId:   $challengeId,
            otpInput:      $capturedOtp,
            correlationId: (string) \Ramsey\Uuid\Uuid::uuid7(),
            ipHash:        str_repeat('d', 64),
        );

        // Resolve device again with the new fingerprint — must be found as trusted (no new OTP challenge)
        $resolved = $deviceService->resolveDevice(
            userId:               $this->userId,
            rawClientFingerprint: 'updated-fingerprint',
            deviceName:           'My Phone',
            platform:             'android',
            correlationId:        (string) \Ramsey\Uuid\Uuid::uuid7(),
            maskedIdentifier:     '+2677***0001',
        );

        expect($resolved->id)->toBe($this->deviceId);
        expect($resolved->status)->toBe('trusted');
    });

});
