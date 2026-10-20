<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\UserContactInfo;
use App\Modules\Profile\Infrastructure\Listeners\CreateProfileOnUserRegisteredListener;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

// ---------------------------------------------------------------------------
// T164 — E2E Scenario 1: Registration → Profile View
// ---------------------------------------------------------------------------

describe('E2E Scenario 1: Registration → Profile View (T164)', function (): void {

    it('consuming Identity.User.Registered creates profile; GET returns active profile; profile-created audit present', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        // E01 contract mock: listener fetches contact info from identity service
        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('getUserContactInfo')
            ->with($userId)
            ->once()
            ->andReturn(new UserContactInfo(
                userId:       $userId,
                email:        'e2e-registered@example.com',
                mobileNumber: '+26771234567',
                actorType:    'CUSTOMER',
            ));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        // Simulate E01 dispatching Identity.User.Registered to the E02 listener
        app(CreateProfileOnUserRegisteredListener::class)->handle(
            'Identity.User.Registered',
            [
                'user_id'        => $userId,
                'correlation_id' => $correlationId,
                'actor_type'     => 'CUSTOMER',
            ],
        );

        // Cross-module boundary 1: E02 profile record created with contact info from E01
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile)->not->toBeNull('Profile must be created by the listener');
        expect($profile->account_status)->toBe('active');
        expect($profile->email)->toBe('e2e-registered@example.com');
        expect($profile->mobile_number)->toBe('+26771234567');
        // Personal fields remain null until the user populates them
        expect($profile->first_name)->toBeNull();
        expect($profile->last_name)->toBeNull();
        expect($profile->date_of_birth)->toBeNull();

        // Cross-module boundary 2: GET /v1/users/profile returns the created profile
        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);
        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.user_id'))->toBe($userId);
        expect($response->json('data.account_status'))->toBe('active');
        expect($response->json('data.first_name'))->toBeNull();
        expect($response->json('data'))->not->toHaveKey('profile_photo_key');

        // Cross-module boundary 3: profile-created audit record written by SYSTEM actor
        $audit = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.profile-created')
            ->where('resource_id', $userId)
            ->first();
        expect($audit)->not->toBeNull('profile.user.profile-created audit must be recorded');
        expect($audit->actor_type)->toBe('SYSTEM');
        expect($audit->outcome)->toBe('success');
        expect($audit->correlation_id)->toBe($correlationId);

        // Profile creation event in outbox
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('aggregate_id', $userId)
            ->whereIn('event_name', ['Profile.User.ProfileCreated', 'Profile.Account.Created'])
            ->first();
        expect($event)->not->toBeNull('A profile-creation event must be published to the outbox');
    });

    it('duplicate Identity.User.Registered is idempotent — no second profile row created', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        // getUserContactInfo called only once — listener exits early on the duplicate
        $mockIdentity = Mockery::mock(IdentityModuleContract::class);
        $mockIdentity->shouldReceive('getUserContactInfo')
            ->with($userId)
            ->once()
            ->andReturn(new UserContactInfo(
                userId:       $userId,
                email:        'dup@example.com',
                mobileNumber: null,
                actorType:    'CUSTOMER',
            ));
        app()->instance(IdentityModuleContract::class, $mockIdentity);

        $payload  = ['user_id' => $userId, 'correlation_id' => $correlationId, 'actor_type' => 'CUSTOMER'];
        $listener = app(CreateProfileOnUserRegisteredListener::class);

        $listener->handle('Identity.User.Registered', $payload);
        $listener->handle('Identity.User.Registered', $payload); // duplicate — must be no-op

        $count = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->count();
        expect($count)->toBe(1, 'Duplicate event must not create a second profile row');
    });
});

// ---------------------------------------------------------------------------
// T165 — E2E Scenario 2: Personal Info Update
// ---------------------------------------------------------------------------

describe('E2E Scenario 2: Personal Info Update (T165)', function (): void {

    it('PUT /v1/users/profile/personal → re-fetch → fields reflected; audit + event in outbox', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $idempKey      = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // Step 1: Update personal info
        $updateResponse = $this->putJson('/v1/users/profile/personal', [
            'first_name'         => 'E2EFirst',
            'last_name'          => 'E2ELast',
            'preferred_language' => 'en',
            'address'            => [
                'street'  => '1 E2E Street',
                'city'    => 'Gaborone',
                'country' => 'BW',
            ],
        ], [
            'X-Test-User-Id'   => $userId,
            'Idempotency-Key'  => $idempKey,
            'X-Correlation-ID' => $correlationId,
        ]);

        $updateResponse->assertStatus(200);
        expect($updateResponse->json('success'))->toBeTrue();
        expect($updateResponse->json('data.first_name'))->toBe('E2EFirst');
        expect($updateResponse->json('data'))->not->toHaveKey('profile_photo_key');

        // Step 2: Re-fetch via GET to verify persistence
        $fetchResponse = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);
        $fetchResponse->assertStatus(200);
        expect($fetchResponse->json('data.first_name'))->toBe('E2EFirst');
        expect($fetchResponse->json('data.last_name'))->toBe('E2ELast');
        expect($fetchResponse->json('data.preferred_language'))->toBe('en');
        expect($fetchResponse->json('data.address.city'))->toBe('Gaborone');
        expect($fetchResponse->json('data.address.country'))->toBe('BW');

        // Step 3: Profile.User.PersonalInfoUpdated published to outbox with correct correlation
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.PersonalInfoUpdated')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.User.PersonalInfoUpdated must be published to outbox');
        expect($event->correlation_id)->toBe($correlationId);

        // Step 4: profile.user.personal-info-updated audit record present
        $audit = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'profile.user.personal-info-updated')
            ->where('resource_id', $userId)
            ->first();
        expect($audit)->not->toBeNull('profile.user.personal-info-updated audit must be recorded');
        expect($audit->outcome)->toBe('success');
        expect($audit->correlation_id)->toBe($correlationId);
    });
});
