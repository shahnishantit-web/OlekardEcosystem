<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

interface KycModuleContract
{
    /**
     * Returns the KYC tier for the given user: 'basic' | 'standard' | 'enhanced'.
     * Returns null when the user has no completed KYC case.
     */
    public function getKycTierForUser(string $userId): ?string;
}
