<?php

declare(strict_types=1);

namespace Tests\E2E\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group docker
 * @group e2e
 *
 * Scenario 1 (quickstart.md): Full registration → OTP verify → login with OTP → PIN set → login with PIN.
 * Requires live Docker stack with running E01 + DB + Redis.
 */
final class RegistrationToFirstLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_1_registration_otp_verify_pin_login(): void
    {
        $mobile = '+254' . mt_rand(700000000, 799999999);
        $email  = 'e2e-' . uniqid() . '@test.local';

        // Step 1: Register
        $register = $this->postJson('/v1/auth/register', [
            'mobile_number' => $mobile,
            'email'         => $email,
            'actor_type'    => 'CUSTOMER',
        ]);
        $register->assertCreated();
        $userId = $register->json('data.user_id');
        $this->assertNotEmpty($userId);

        // Step 2: Verify OTP (audit trail: Identity.User.OtpVerified emitted)
        $otp = $this->fetchOtpForUser($userId);
        $verifyOtp = $this->postJson('/v1/auth/otp/verify', [
            'user_id' => $userId,
            'otp'     => $otp,
        ]);
        $verifyOtp->assertOk();
        $setupToken = $verifyOtp->json('data.setup_token');
        $this->assertNotEmpty($setupToken);

        // Step 3: Set PIN using setup token
        $setPin = $this->postJson('/v1/auth/pin/set', [
            'setup_token' => $setupToken,
            'pin'         => '123456',
        ]);
        $setPin->assertOk();

        // Step 4: Login with PIN
        $loginPin = $this->postJson('/v1/auth/login', [
            'mobile_number' => $mobile,
            'pin'           => '123456',
        ]);
        $loginPin->assertOk();
        $accessToken = $loginPin->json('data.access_token');
        $this->assertNotEmpty($accessToken);

        // Step 5: Assert active session exists
        $sessions = $this->withToken($accessToken)->getJson('/v1/auth/sessions');
        $sessions->assertOk();
        $this->assertGreaterThanOrEqual(1, count($sessions->json('data')));
    }

    private function fetchOtpForUser(string $userId): string
    {
        // In Docker test mode, OTP is returned in the response or via a test API endpoint.
        // Retrieve from DB for integration tests.
        $row = \Illuminate\Support\Facades\DB::connection('identity')
            ->table('identity.one_time_passwords')
            ->where('user_id', $userId)
            ->where('purpose', 'registration')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row, 'No OTP found for user in DB');

        return $row->code;
    }
}
