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

describe('Email change security (T067)', function (): void {

    it('second initiation with different idempotency key returns 409 CONTACT_CHANGE_IN_PROGRESS', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        // First initiation succeeds
        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $first = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'first@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );
        $first->assertStatus(200);

        // Second initiation (different idempotency key, same user) — pending already exists
        $second = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'second@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $second->assertStatus(409);
        expect($second->json('error.code'))->toBe('CONTACT_CHANGE_IN_PROGRESS');

        // Only one pending challenge was created
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->pending_email)->toBe('first@example.com', 'pending_email must be from first request only');
        expect($profile->pending_email_challenge_id)->toBe($challengeId);
    });

    it('email uniqueness check discloses nothing — response identical whether new email is in use or not', function (): void {
        $userId       = (string) Uuid::uuid7();
        $challengeId1 = (string) Uuid::uuid7();
        $challengeId2 = (string) Uuid::uuid7();

        // Two separate users — first user already has the target email
        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        $existingUserId = (string) Uuid::uuid7();
        $repo->create(['user_id' => $existingUserId, 'account_status' => 'active']);
        $repo->update($existingUserId, ['email' => 'taken@example.com']);

        // Single mock covering both requests — ContactChangeService is a singleton so its
        // OtpServiceContract dependency is injected once and shared across both HTTP calls.
        $fakeChallengeTaken     = new OtpChallenge();
        $fakeChallengeTaken->id = $challengeId1;
        $fakeChallengeNew       = new OtpChallenge();
        $fakeChallengeNew->id   = $challengeId2;

        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallengeTaken);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallengeNew);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $responseTaken = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'taken@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Clear pending for the second attempt
        $repo->update($userId, ['pending_email' => null, 'pending_email_challenge_id' => null]);

        $responseNew = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'available@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Both return 200 — no uniqueness disclosure
        $responseTaken->assertStatus(200);
        $responseNew->assertStatus(200);

        // Both return same structure
        expect($responseTaken->json('success'))->toBeTrue();
        expect($responseNew->json('success'))->toBeTrue();
        expect($responseTaken->json('error'))->toBeNull();
        expect($responseNew->json('error'))->toBeNull();
    });

    // T151 — Concurrency simulation: 5 sequential POST with distinct keys while one is in-progress
    it('5 sequential POST email/change with pending challenge → first succeeds; remaining 4 return 409 CONTACT_CHANGE_IN_PROGRESS', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active', 'email' => 'original@example.com']);

        $challengeId = (string) Uuid::uuid7();
        $fakeChallenge     = new OtpChallenge();
        $fakeChallenge->id = $challengeId;

        // OTP service only generates one challenge; subsequent requests are blocked before calling generate()
        $mockOtp = Mockery::mock(OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->once()->andReturn($fakeChallenge);
        app()->instance(OtpServiceContract::class, $mockOtp);

        $responses = [];

        // First request — should succeed and put email change in progress
        $responses[] = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'new1@example.com'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        // Requests 2–5 — should all hit 409 because a challenge is now pending
        for ($i = 2; $i <= 5; $i++) {
            $responses[] = $this->postJson(
                '/v1/users/profile/email/change',
                ['new_email' => "new{$i}@example.com"],
                ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
            );
        }

        // First response: 200 with challenge_id
        $responses[0]->assertStatus(200);
        expect($responses[0]->json('success'))->toBeTrue();
        expect($responses[0]->json('data.challenge_id'))->toBe($challengeId);

        // Responses 2–5: 409 CONTACT_CHANGE_IN_PROGRESS
        for ($i = 1; $i <= 4; $i++) {
            $responses[$i]->assertStatus(409);
            expect($responses[$i]->json('error.code'))->toBe('CONTACT_CHANGE_IN_PROGRESS');
        }

        // Only one pending_email stored — last write did not overwrite
        $fresh = $repo->findByUserId($userId);
        expect($fresh->pending_email)->toBe('new1@example.com', 'Only the first pending email must be stored');
        expect($fresh->pending_email_challenge_id)->toBe($challengeId);
    });

    it('returns 400 INVALID_EMAIL_FORMAT for malformed email', function (): void {
        $userId = (string) Uuid::uuid7();
        $repo   = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            '/v1/users/profile/email/change',
            ['new_email' => 'not-an-email'],
            ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_EMAIL_FORMAT');
    });
});
