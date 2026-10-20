<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Contracts;

interface ProfileModuleContract
{
    /**
     * Get the current account status for a user.
     * Returns null if no profile record exists.
     */
    public function getAccountStatus(string $userId): ?string;

    /**
     * Check whether a user's profile is complete enough for downstream flows (e.g., KYC).
     * Returns true if the profile has at least first_name, last_name, and date_of_birth.
     */
    public function isProfileComplete(string $userId): bool;
}
