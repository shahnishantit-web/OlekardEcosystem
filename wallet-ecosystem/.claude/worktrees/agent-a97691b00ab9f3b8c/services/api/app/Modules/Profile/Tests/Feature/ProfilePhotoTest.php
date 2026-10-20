<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Contracts\PhotoStorageResult;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Http\UploadedFile;
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

describe('POST /v1/users/profile/photo (T053)', function (): void {

    it('accepts a valid JPEG upload and returns a signed URL', function (): void {
        $userId  = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Config with permissive limits so any valid file passes
        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(['image/jpeg', 'image/png']);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(5 * 1024 * 1024); // 5 MB
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.signed_url_ttl_seconds')->andReturn(3600);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // Fake storage contract that records store/delete calls
        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')
            ->once()
            ->andReturn(new PhotoStorageResult(
                storageKey: "profile-photos/{$userId}/photo_test.jpg",
                sizeBytes:  1024,
                mimeType:   'image/jpeg',
                widthPx:    200,
                heightPx:   200,
            ));
        $fakeStorage->shouldReceive('generateSignedUrl')
            ->once()
            ->andReturn('https://example.test/signed/photo_test.jpg?sig=abc');
        $fakeStorage->shouldNotReceive('delete'); // no previous photo
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.profile_photo_url'))->toBe('https://example.test/signed/photo_test.jpg?sig=abc');
        expect($response->json('data'))->not->toHaveKey('profile_photo_key');
    });

    it('returned response never contains profile_photo_key', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.signed_url_ttl_seconds')->andReturn(3600);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')->andReturn(new PhotoStorageResult(
            storageKey: "profile-photos/{$userId}/photo_key.jpg",
            sizeBytes: 512, mimeType: 'image/jpeg', widthPx: 100, heightPx: 100,
        ));
        $fakeStorage->shouldReceive('generateSignedUrl')->andReturn('https://cdn.test/signed/url');
        $fakeStorage->shouldReceive('delete')->andReturnNull();
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        $file     = UploadedFile::fake()->image('photo.jpg');
        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(200);
        expect($response->getContent())->not->toContain('profile_photo_key');
    });

    it('deletes old photo from storage after successful upload', function (): void {
        $userId      = (string) Uuid::uuid7();
        $oldPhotoKey = "profile-photos/{$userId}/old_photo.jpg";

        // Seed profile with an existing photo key
        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->update(['profile_photo_key' => $oldPhotoKey]);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.signed_url_ttl_seconds')->andReturn(3600);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        $newKey      = "profile-photos/{$userId}/new_photo.jpg";
        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')->andReturn(new PhotoStorageResult(
            storageKey: $newKey, sizeBytes: 512, mimeType: 'image/jpeg', widthPx: 100, heightPx: 100,
        ));
        $fakeStorage->shouldReceive('generateSignedUrl')->andReturn('https://cdn.test/new_signed_url');
        // Old key MUST be deleted exactly once
        $fakeStorage->shouldReceive('delete')->with($oldPhotoKey)->once();
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', UploadedFile::fake()->image('new.jpg'));

        $response->assertStatus(200);
    });

    it('emits Profile.User.PhotoUpdated to outbox after successful upload', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')->andReturn(new PhotoStorageResult(
            storageKey: "profile-photos/{$userId}/x.jpg", sizeBytes: 128, mimeType: 'image/jpeg', widthPx: 50, heightPx: 50,
        ));
        $fakeStorage->shouldReceive('generateSignedUrl')->andReturn('https://cdn.test/x');
        $fakeStorage->shouldReceive('delete')->andReturnNull();
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        $this->postJson('/v1/users/profile/photo', [], [
            'X-Test-User-Id'   => $userId,
            'X-Correlation-ID' => $correlationId,
        ])->withFile('photo', UploadedFile::fake()->image('x.jpg'));

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.PhotoUpdated')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.PhotoUpdated must be written to outbox');
        expect($event->correlation_id)->toBe($correlationId);
    });

    it('returns 401 when no token is provided', function (): void {
        $response = $this->postJson('/v1/users/profile/photo');
        $response->assertStatus(401);
    });

    it('returns 400 when no photo file is provided', function (): void {
        $userId   = (string) Uuid::uuid7();
        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId]);
        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });
});
