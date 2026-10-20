<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use Illuminate\Support\Facades\Redis;


beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub-'.uniqid(), null);
        }
    });

    Redis::connection('identity')->flushdb();
});

describe('OTP concurrency invariants (spec Section 24.3)', function (): void {

    it('allows only one active OTP challenge per identifier+type at a time', function (): void {
        // First registration — creates challenge A
        $r1 = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26770100001',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'con1', 'X-Idempotency-Key' => 'con-idem1']);
        $r1->assertStatus(202);
        $challengeId1 = $r1->json('data.challenge_id');

        // Second registration for the same mobile — previous challenge must be invalidated
        // Note: second registration for existing mobile returns 409 per spec.
        // Concurrency here refers to two OTP sends for the same identifier within the same session.
        // The OtpService.invalidateExisting() ensures only one pending challenge exists.
        $challenges = app(OtpChallengeRepositoryInterface::class);
        $activeChallenge = $challenges->findById($challengeId1);

        expect($activeChallenge)->not->toBeNull();
        expect($activeChallenge->status)->toBe('pending');

        // Simulate a second OTP generate call for the same identifier
        // (would happen if the user resends or if two requests race)
        $otpService = app(\App\Modules\Identity\Domain\Services\OtpService::class);
        $challengeTwo = $otpService->generate(
            identifier:    '+26770100001',
            challengeType: \App\Modules\Identity\Domain\ValueObjects\OtpChallengeType::REGISTRATION,
            userId:        $activeChallenge->user_id,
            correlationId: 'con2',
        );

        // First challenge must now be invalidated
        $activeChallenge->refresh();
        expect($activeChallenge->status)->toBe('invalidated');

        // Only the new challenge is pending
        expect($challengeTwo->status)->toBe('pending');
        expect($challengeTwo->id)->not->toBe($challengeId1);
    });

    it('OTP verification on a consumed/invalidated challenge returns 401', function (): void {
        $r1 = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26770100002',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => 'con3', 'X-Idempotency-Key' => 'con-idem3']);

        $challengeId = $r1->json('data.challenge_id');

        // Invalidate the challenge by generating a new one for same identifier
        $challenges = app(OtpChallengeRepositoryInterface::class);
        $c          = $challenges->findById($challengeId);
        app(\App\Modules\Identity\Domain\Services\OtpService::class)->generate(
            identifier:    '+26770100002',
            challengeType: \App\Modules\Identity\Domain\ValueObjects\OtpChallengeType::REGISTRATION,
            userId:        $c->user_id,
            correlationId: 'con4',
        );

        // Attempt to verify the old (now invalidated) challenge
        $response = $this->postJson('/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'otp'          => '123456',
        ], ['X-Correlation-ID' => 'con5', 'X-Idempotency-Key' => 'con-idem5']);

        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('IDENTITY_003');
    });

})->group('identity', 'registration', 'concurrency');
