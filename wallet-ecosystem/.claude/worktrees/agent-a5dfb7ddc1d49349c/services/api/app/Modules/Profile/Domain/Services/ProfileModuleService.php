<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Profile\Domain\Contracts\ProfileModuleContract;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;

final class ProfileModuleService implements ProfileModuleContract
{
    public function __construct(
        private readonly EloquentUserProfileRepository $profileRepository,
    ) {}

    public function getAccountStatus(string $userId): ?string
    {
        $profile = $this->profileRepository->findByUserId($userId);

        return $profile?->account_status;
    }

    public function isProfileComplete(string $userId): bool
    {
        $profile = $this->profileRepository->findByUserId($userId);

        if ($profile === null) {
            return false;
        }

        return $profile->first_name !== null
            && $profile->last_name !== null
            && $profile->date_of_birth !== null;
    }
}
