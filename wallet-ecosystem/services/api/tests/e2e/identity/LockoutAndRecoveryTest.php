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
 * Scenario 2 (quickstart.md Scenario 3): Fail PIN N times → account locked → audit event →
 * PIN reset via OTP → unlocked → successful PIN login.
 */
final class LockoutAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_2_pin_lockout_and_otp_recovery(): void
    {
        $mobile = '+254' . mt_rand(700000000, 799999999);
        $userId = $this->seedActiveUserWithPin($mobile, '111111');

        // Exhaust PIN attempts until locked
        $maxAttempts = (int) config('identity.pin.max_attempts', 5);
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson('/v1/auth/login', [
                'mobile_number' => $mobile,
                'pin'           => '999999',
            ]);
        }

        // Account should now be locked
        $lockedResponse = $this->postJson('/v1/auth/login', [
            'mobile_number' => $mobile,
            'pin'           => '111111',
        ]);
        $lockedResponse->assertStatus(423);

        // Audit event: Identity.User.AccountLocked must be in outbox
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Identity.User.AccountLocked')
            ->where('payload', 'like', "%{$userId}%")
            ->first();
        $this->assertNotNull($event, 'AccountLocked audit event must be emitted');

        // Initiate PIN reset
        $initReset = $this->postJson('/v1/auth/pin/reset/initiate', ['mobile_number' => $mobile]);
        $initReset->assertOk();

        // Fetch OTP from DB (Docker integration mode)
        $otp = DB::connection('identity')
            ->table('identity.one_time_passwords')
            ->where('user_id', $userId)
            ->where('purpose', 'pin_reset')
            ->orderByDesc('created_at')
            ->value('code');
        $this->assertNotEmpty($otp);

        // Complete reset with new PIN
        $completeReset = $this->postJson('/v1/auth/pin/reset/complete', [
            'mobile_number' => $mobile,
            'otp'           => $otp,
            'new_pin'       => '654321',
        ]);
        $completeReset->assertOk();

        // Login succeeds with new PIN
        $loginNew = $this->postJson('/v1/auth/login', [
            'mobile_number' => $mobile,
            'pin'           => '654321',
        ]);
        $loginNew->assertOk();
        $this->assertNotEmpty($loginNew->json('data.access_token'));
    }

    private function seedActiveUserWithPin(string $mobile, string $pin): string
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'mobile_number' => $mobile,
            'email'         => "e2e-lockout-{$userId}@test.local",
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        // PIN is stored hashed — use bcrypt to match the login check
        DB::connection('identity')->table('identity.user_pins')->insert([
            'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'    => $userId,
            'pin_hash'   => bcrypt($pin),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $userId;
    }
}
