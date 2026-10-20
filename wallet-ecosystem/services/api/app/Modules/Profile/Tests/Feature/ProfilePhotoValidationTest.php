<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Contracts\PhotoStorageResult;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('Photo upload validation (T054)', function (): void {

    it('returns 413 FILE_TOO_LARGE when file exceeds configured max_size_bytes', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(['image/jpeg', 'image/png']);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(100); // 100 bytes — tiny
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // File fake creates a real temp file; getSize() > 100 bytes for any image
        $file = UploadedFile::fake()->image('large.jpg', 100, 100);

        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(413);
        expect($response->json('error.code'))->toBe('FILE_TOO_LARGE');
    });

    it('returns 415 UNSUPPORTED_MEDIA_TYPE for a disallowed MIME type', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(['image/jpeg']);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(null);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // PDF file — not an accepted image type
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(415);
        expect($response->json('error.code'))->toBe('UNSUPPORTED_MEDIA_TYPE');
    });

    it('returns 422 IMAGE_DIMENSIONS_TOO_SMALL when image is below minimum dimensions', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(['image/jpeg', 'image/png']);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturn(null);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturn(500);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturn(500);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // 10×10 image — well below 500×500 minimum
        $file = UploadedFile::fake()->image('tiny.jpg', 10, 10);

        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('IMAGE_DIMENSIONS_TOO_SMALL');
    });

    it('skips MIME validation when accepted_mime_types config is null (TBD)', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.signed_url_ttl_seconds')->andReturn(3600);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')->andReturn(new PhotoStorageResult(
            storageKey: "profile-photos/{$userId}/x.jpg", sizeBytes: 256, mimeType: 'image/jpeg', widthPx: 50, heightPx: 50,
        ));
        $fakeStorage->shouldReceive('generateSignedUrl')->andReturn('https://cdn.test/x');
        $fakeStorage->shouldReceive('delete')->andReturnNull();
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        // Any file type should pass when config is null
        $file     = UploadedFile::fake()->create('file.bmp', 5, 'image/bmp');
        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(200);
    });

    it('skips size validation when max_size_bytes config is null (TBD)', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturn(['image/jpeg']);
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.signed_url_ttl_seconds')->andReturn(3600);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        $fakeStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $fakeStorage->shouldReceive('store')->andReturn(new PhotoStorageResult(
            storageKey: "profile-photos/{$userId}/big.jpg", sizeBytes: 999999999, mimeType: 'image/jpeg', widthPx: 50, heightPx: 50,
        ));
        $fakeStorage->shouldReceive('generateSignedUrl')->andReturn('https://cdn.test/big');
        $fakeStorage->shouldReceive('delete')->andReturnNull();
        app()->instance(ProfilePhotoStorageContract::class, $fakeStorage);

        $file     = UploadedFile::fake()->image('big.jpg', 100, 100);
        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(200);
    });

    it('validates width only when min_width_px is set and min_height_px is null', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')->with('profile.photo.accepted_mime_types')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.max_size_bytes')->andReturnNull();
        $mockConfig->shouldReceive('get')->with('profile.photo.min_width_px')->andReturn(800);
        $mockConfig->shouldReceive('get')->with('profile.photo.min_height_px')->andReturnNull();
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // 50×600 — width below threshold, height irrelevant
        $file     = UploadedFile::fake()->image('narrow.jpg', 50, 600);
        $response = $this->postJson('/v1/users/profile/photo', [], ['X-Test-User-Id' => $userId])
            ->withFile('photo', $file);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('IMAGE_DIMENSIONS_TOO_SMALL');
    });
});
