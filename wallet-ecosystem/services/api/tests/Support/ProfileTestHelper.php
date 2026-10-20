<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;

/**
 * Helpers for Profile module feature and security tests.
 * Uses withoutMiddleware('identity.auth') + manual request attribute injection
 * to keep Profile tests isolated from E01 JWT infrastructure.
 */
trait ProfileTestHelper
{
    /**
     * Seed a profile record and return the user_id.
     */
    protected function seedProfile(array $overrides = []): string
    {
        $userId = $overrides['user_id'] ?? (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(array_merge([
            'user_id'        => $userId,
            'email'          => null,
            'mobile_number'  => null,
            'account_status' => 'active',
        ], $overrides));
        return $userId;
    }

    /**
     * Make an authenticated GET request as the given user_id.
     * Bypasses identity.auth middleware; sets identity.user_id as a request attribute.
     */
    protected function getProfileAs(string $userId, string $path = '/v1/users/profile'): TestResponse
    {
        return $this->withoutMiddleware(['identity.auth'])
            ->withHeaders(['X-Correlation-ID' => (string) Uuid::uuid7()])
            ->call('GET', $path, [], [], [], ['HTTP_IDENTITY_USER_ID' => $userId])
            ->tap(fn () => request()->attributes->set('identity.user_id', $userId));
    }

    /**
     * Make an authenticated request with manually set request attributes.
     */
    protected function actingAsUser(string $userId, string $actorType = 'CUSTOMER'): static
    {
        $this->withoutMiddleware(['identity.auth']);
        app()->bind(\Illuminate\Http\Request::class, function () use ($userId, $actorType) {
            $req = request();
            $req->attributes->set('identity.user_id', $userId);
            $req->attributes->set('identity.actor_type', $actorType);
            return $req;
        });
        return $this;
    }
}
