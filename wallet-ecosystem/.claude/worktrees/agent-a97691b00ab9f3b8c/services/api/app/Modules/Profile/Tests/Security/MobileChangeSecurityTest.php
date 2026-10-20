<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('Mobile change concurrency (T191)', function (): void {

    it('only one pending_mobile_challenge_id is created when 5 requests arrive in sequence; all others return 409 CONTACT_CHANGE_IN_PROGRESS', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['mobile_number' => '+26771000000']);

        // Only the first request will invoke OtpServiceContract::generate(); the remaining 4
        // are rejected by the ContactChangeInProgressException guard (pending_mobile !== null)
        // before OTP generation is reached — matching the email equivalent (T151).
        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        // Five requests with distinct idempotency keys and distinct target numbers simulate
        // concurrent initiations. The ContactChangeService guard serializes them at the
        // application level — the first to commit the pending state wins; the rest see
        // pending_mobile !== null and throw ContactChangeInProgressException → 409.
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson(
                '/v1/users/profile/mobile/change',
                ['new_mobile' => '+26771999' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
            );
        }

        $successCount  = 0;
        $conflictCount = 0;
        foreach ($responses as $response) {
            if ($response->status() === 200) {
                ++$successCount;
                expect($response->json('data.challenge_id'))->not->toBeNull();
            } elseif ($response->status() === 409) {
                ++$conflictCount;
                expect($response->json('error.code'))->toBe('CONTACT_CHANGE_IN_PROGRESS');
            }
        }

        expect($successCount)->toBe(1, 'exactly one initiation must succeed');
        expect($conflictCount)->toBe(4, 'all other 4 requests must return 409 CONTACT_CHANGE_IN_PROGRESS');

        // Exactly one pending challenge must be persisted in the database
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_mobile_challenge_id)->toBe($challengeId, 'only the first challenge_id must be stored');
        expect($profile->pending_mobile)->not->toBeNull('pending_mobile must be set from the winning request');
    });

});
