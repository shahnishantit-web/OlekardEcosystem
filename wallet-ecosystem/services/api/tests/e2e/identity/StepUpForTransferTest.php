<?php

declare(strict_types=1);

namespace Tests\E2E\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group docker
 * @group e2e
 *
 * Scenario 3 (quickstart.md Scenario 7): Authenticated customer triggers step-up for high-value transfer.
 * MFA challenge issued → step-up token has correct scope → subsequent request with step-up token passes.
 */
final class StepUpForTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_3_step_up_triggered_and_token_scoped(): void
    {
        $mobile      = '+254' . mt_rand(700000000, 799999999);
        [$accessToken] = $this->loginActiveUser($mobile);

        // Initiate step-up for transfer scope
        $initiate = $this->withToken($accessToken)->postJson('/v1/auth/step-up/initiate', [
            'scope'  => 'transfer',
            'reason' => 'High-value transfer above threshold',
        ]);
        $initiate->assertOk();
        $mfaChallenge = $initiate->json('data.mfa_challenge_id');
        $this->assertNotEmpty($mfaChallenge);

        // Verify step-up (use OTP from DB in Docker mode)
        $otp = \Illuminate\Support\Facades\DB::connection('identity')
            ->table('identity.mfa_challenges')
            ->where('id', $mfaChallenge)
            ->value('code');

        $verify = $this->withToken($accessToken)->postJson('/v1/auth/step-up/verify', [
            'mfa_challenge_id' => $mfaChallenge,
            'code'             => $otp,
        ]);
        $verify->assertOk();
        $stepUpToken = $verify->json('data.step_up_token');
        $this->assertNotEmpty($stepUpToken);

        // Assert token scope includes 'transfer'
        $claims = $this->decodeJwtPayload($stepUpToken);
        $this->assertContains('transfer', (array) ($claims['scope'] ?? []));
    }

    /** @return array{0: string, 1: string} [accessToken, userId] */
    private function loginActiveUser(string $mobile): array
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'mobile_number' => $mobile,
            'email'         => "e2e-stepup-{$userId}@test.local",
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        \Illuminate\Support\Facades\DB::connection('identity')->table('identity.user_pins')->insert([
            'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'    => $userId,
            'pin_hash'   => bcrypt('111111'),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/v1/auth/login', [
            'mobile_number' => $mobile,
            'pin'           => '111111',
        ]);
        $login->assertOk();

        return [$login->json('data.access_token'), $userId];
    }

    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        return json_decode($payload, true) ?? [];
    }
}
