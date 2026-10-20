<?php

declare(strict_types=1);

use App\Modules\Profile\Domain\Services\ProfileModuleService;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T179 — ProfileModuleService unit tests
// ---------------------------------------------------------------------------

describe('ProfileModuleService::getAccountStatus() (T179)', function (): void {

    it('returns the account_status string when a profile exists', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) ['account_status' => 'suspended']);

        $service = new ProfileModuleService($repo);

        expect($service->getAccountStatus($userId))->toBe('suspended');
    });

    it('returns null when no profile record exists for the given userId', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn(null);

        $service = new ProfileModuleService($repo);

        expect($service->getAccountStatus($userId))->toBeNull();
    });

    it('returns active status when profile is active', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) ['account_status' => 'active']);

        $service = new ProfileModuleService($repo);

        expect($service->getAccountStatus($userId))->toBe('active');
    });

});

describe('ProfileModuleService::isProfileComplete() (T179)', function (): void {

    it('returns true when first_name, last_name, and date_of_birth are all non-null', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) [
                'first_name'    => 'Alice',
                'last_name'     => 'Mokobi',
                'date_of_birth' => '1990-06-15',
            ]);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeTrue();
    });

    it('returns false when first_name is null', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) [
                'first_name'    => null,
                'last_name'     => 'Mokobi',
                'date_of_birth' => '1990-06-15',
            ]);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeFalse();
    });

    it('returns false when last_name is null', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) [
                'first_name'    => 'Alice',
                'last_name'     => null,
                'date_of_birth' => '1990-06-15',
            ]);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeFalse();
    });

    it('returns false when date_of_birth is null', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) [
                'first_name'    => 'Alice',
                'last_name'     => 'Mokobi',
                'date_of_birth' => null,
            ]);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeFalse();
    });

    it('returns false when no profile record exists', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn(null);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeFalse();
    });

    it('returns false when all three fields are null (empty profile created by listener)', function (): void {
        $userId = (string) Uuid::uuid7();

        $repo = Mockery::mock(EloquentUserProfileRepository::class);
        $repo->shouldReceive('findByUserId')
            ->once()
            ->with($userId)
            ->andReturn((object) [
                'first_name'    => null,
                'last_name'     => null,
                'date_of_birth' => null,
            ]);

        $service = new ProfileModuleService($repo);

        expect($service->isProfileComplete($userId))->toBeFalse();
    });

});
