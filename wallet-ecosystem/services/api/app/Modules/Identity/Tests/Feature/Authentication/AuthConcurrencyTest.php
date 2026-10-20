<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;


beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $c, string $cor): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });

    Redis::connection('identity')->flushdb();
});

describe('Login concurrency (spec Section 24.3)', function (): void {

    it('two sequential login requests from trusted devices both return tokens', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);
        $devRepo  = app(DeviceRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000030',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        // Pre-trust both devices so login proceeds without device verification
        $devRepo->create(['user_id' => $user->id, 'device_fingerprint' => hash('sha256', 'device-A'), 'status' => 'trusted', 'platform' => 'android']);
        $devRepo->create(['user_id' => $user->id, 'device_fingerprint' => hash('sha256', 'device-B'), 'status' => 'trusted', 'platform' => 'ios']);

        // Two separate login requests (different idempotency keys = different operations)
        $r1 = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771000030',
            'credential'         => '123456',
            'device_fingerprint' => 'device-A',
            'device_name'        => 'Phone A',
            'platform'           => 'android',
        ], ['X-Correlation-ID' => 'conc1', 'X-Idempotency-Key' => 'conc-idem1']);

        $r2 = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26771000030',
            'credential'         => '123456',
            'device_fingerprint' => 'device-B',
            'device_name'        => 'Phone B',
            'platform'           => 'ios',
        ], ['X-Correlation-ID' => 'conc2', 'X-Idempotency-Key' => 'conc-idem2']);

        // Both should succeed; each creates a separate session
        $r1->assertStatus(200);
        $r2->assertStatus(200);

        // Session IDs must differ (separate sessions)
        expect($r1->json('data.session_id'))->not->toBe($r2->json('data.session_id'));
    });

    it('lockout counter is not corrupted by rapid successive failed attempts', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000031',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('111111'),
            'status'          => 'active',
        ]);

        // Three wrong attempts
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/v1/auth/login', [
                'credential_type' => 'pin',
                'identifier'      => '+26771000031',
                'credential'         => '999999',
            ], ['X-Correlation-ID' => "conc-f{$i}", 'X-Idempotency-Key' => "conc-fidem{$i}"]);
        }

        $updatedUser = $users->findByMobile('+26771000031');
        // failed_attempt_count must be at least 3
        expect($updatedUser->failed_attempt_count)->toBeGreaterThanOrEqual(3);
    });

})->group('identity', 'authentication', 'concurrency');

