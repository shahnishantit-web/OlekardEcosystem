<?php

declare(strict_types=1);

namespace Tests\E2E\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @group docker
 * @group e2e
 *
 * Scenario 4 (quickstart.md Scenario 2): Login from a new device fingerprint triggers
 * DEVICE_VERIFICATION_REQUIRED → OTP device verify → device trusted → subsequent login without challenge.
 */
final class NewDeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_4_new_device_requires_verification_then_trusted(): void
    {
        $mobile     = '+254' . mt_rand(700000000, 799999999);
        $userId     = $this->seedActiveUser($mobile);
        $knownFp    = 'fp-known-' . uniqid();
        $unknownFp  = 'fp-unknown-' . uniqid();

        // Seed a known device
        DB::connection('identity')->table('identity.devices')->insert([
            'id'          => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'     => $userId,
            'fingerprint' => $knownFp,
            'status'      => 'trusted',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Login from UNKNOWN device fingerprint → DEVICE_VERIFICATION_REQUIRED
        $loginUnknown = $this->postJson('/v1/auth/login', [
            'mobile_number'      => $mobile,
            'pin'                => '111111',
            'device_fingerprint' => $unknownFp,
        ]);
        $loginUnknown->assertStatus(200);
        $this->assertSame(
            'DEVICE_VERIFICATION_REQUIRED',
            $loginUnknown->json('data.next_step') ?? $loginUnknown->json('error.code')
        );

        // Fetch device-verify OTP from DB
        $otp = DB::connection('identity')
            ->table('identity.one_time_passwords')
            ->where('user_id', $userId)
            ->where('purpose', 'device_verification')
            ->orderByDesc('created_at')
            ->value('code');
        $this->assertNotEmpty($otp);

        // Verify device
        $verifyDevice = $this->postJson('/v1/auth/devices/verify', [
            'user_id'            => $userId,
            'otp'                => $otp,
            'device_fingerprint' => $unknownFp,
        ]);
        $verifyDevice->assertOk();

        // Subsequent login with same device fingerprint should succeed without challenge
        $loginTrusted = $this->postJson('/v1/auth/login', [
            'mobile_number'      => $mobile,
            'pin'                => '111111',
            'device_fingerprint' => $unknownFp,
        ]);
        $loginTrusted->assertOk();
        $this->assertNotEmpty($loginTrusted->json('data.access_token'));
    }

    private function seedActiveUser(string $mobile): string
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'mobile_number' => $mobile,
            'email'         => "e2e-device-{$userId}@test.local",
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::connection('identity')->table('identity.user_pins')->insert([
            'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'    => $userId,
            'pin_hash'   => bcrypt('111111'),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $userId;
    }
}
