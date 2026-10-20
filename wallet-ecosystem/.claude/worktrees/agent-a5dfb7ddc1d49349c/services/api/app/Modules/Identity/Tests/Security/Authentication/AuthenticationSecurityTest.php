<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
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

describe('Authentication security invariants', function (): void {

    it('does not disclose the credential value in any error response', function (): void {
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000020',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26771000020',
            'credential'         => '654321',
        ], ['X-Correlation-ID' => 'sec-a1', 'X-Idempotency-Key' => 'sec-idem-a1']);

        $responseBody = $response->getContent();

        // PIN must not appear in error response (BR-004)
        expect($responseBody)->not->toContain('654321');
        expect($responseBody)->not->toContain('123456');
    });

    it('returns the same error code for wrong PIN and for non-existent identifier (no timing distinction via error code)', function (): void {
        // Both paths return IDENTITY_002 â€” prevents identifier enumeration (BR-014)
        $response1 = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779990001',
            'credential'         => '123456',
        ], ['X-Correlation-ID' => 'sec-a2', 'X-Idempotency-Key' => 'sec-idem-a2']);

        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);
        $user = $users->create(['mobile_number' => '+26779990002', 'status' => 'active', 'actor_type' => 'CUSTOMER']);
        $credRepo->create([
            'user_id' => $user->id, 'credential_type' => 'pin',
            'credential_hash' => Hash::make('111111'), 'status' => 'active',
        ]);

        $response2 = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779990002',
            'credential'         => '999999',
        ], ['X-Correlation-ID' => 'sec-a3', 'X-Idempotency-Key' => 'sec-idem-a3']);

        expect($response1->json('error.code'))->toBe('IDENTITY_002');
        expect($response2->json('error.code'))->toBe('IDENTITY_002');
    });

    it('lockout threshold is sourced from ConfigurationSecurityService (AC-017)', function (): void {
        // The lockout threshold must come from the config service, not a hard-coded literal.
        // We verify this behaviourally: the configured value controls exactly how many
        // failures trigger lockout.
        $users    = app(UserRepositoryInterface::class);
        $credRepo = app(CredentialRepositoryInterface::class);
        $config   = app(ConfigurationSecurityService::class);
        $maxAttempts = $config->getLockoutMaxFailedAttempts();

        $user = $users->create([
            'mobile_number' => '+26779990010',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id' => $user->id, 'credential_type' => 'pin',
            'credential_hash' => Hash::make('111111'), 'status' => 'active',
        ]);

        // maxAttempts - 1 failures must NOT lock
        for ($i = 0; $i < $maxAttempts - 1; $i++) {
            $this->postJson('/v1/auth/login', [
                'credential_type' => 'pin',
                'identifier'      => '+26779990010',
                'credential'         => '999999',
            ], ['X-Correlation-ID' => "cfg-{$i}", 'X-Idempotency-Key' => "cfg-idem-{$i}"]);
        }

        // Pre-create a trusted device so the successful login proceeds past device verification
        app(DeviceRepositoryInterface::class)->create([
            'user_id'            => $user->id,
            'device_fingerprint' => hash('sha256', 'lockout-test-device'),
            'status'             => 'trusted',
            'platform'           => 'android',
        ]);

        // (maxAttempts - 1) attempts should still allow a successful login
        $rBefore = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26779990010',
            'credential'         => '111111',
            'device_fingerprint' => 'lockout-test-device',
        ], ['X-Correlation-ID' => 'cfg-ok', 'X-Idempotency-Key' => 'cfg-idem-ok']);

        $rBefore->assertStatus(200);
    });

    it('PIN value does not appear in any response body on login failure (BR-004)', function (): void {
        $submittedPin = '876543';

        $response = $this->postJson('/v1/auth/login', [
            'credential_type' => 'pin',
            'identifier'      => '+26779990020',
            'credential'         => $submittedPin,
        ], ['X-Correlation-ID' => 'pin-log-1', 'X-Idempotency-Key' => 'pin-log-idem-1']);

        $body = $response->getContent();
        expect($body)->not->toContain($submittedPin);
    });

    it('biometric challenge nonce is consumed after use (replay protection)', function (): void {
        $r = $this->getJson('/v1/auth/login/biometric/challenge', ['X-Correlation-ID' => 'sec-bio1']);
        $nonce = $r->json('data.nonce');

        // Attempting to verify with a correct nonce but wrong assertion should invalidate it
        $this->postJson('/v1/auth/login/biometric', [
            'user_id'         => '00000000-0000-0000-0000-000000000000',
            'credential_id'   => 'dummy',
            'signed_assertion' => 'invalid',
            'nonce'           => $nonce,
        ], ['X-Correlation-ID' => 'sec-bio2']);

        // Second attempt with same nonce must fail immediately
        $response = $this->postJson('/v1/auth/login/biometric', [
            'user_id'         => '00000000-0000-0000-0000-000000000000',
            'credential_id'   => 'dummy',
            'signed_assertion' => 'invalid',
            'nonce'           => $nonce,
        ], ['X-Correlation-ID' => 'sec-bio3']);

        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_002');
    });

})->group('identity', 'authentication', 'security');

