<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Services;

use App\Modules\Profile\Infrastructure\Models\UserProfileModel;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class WalletOwnerMerchantReadService implements MerchantReadServiceInterface
{
    public function __construct(private readonly WalletModuleContract $walletModule) {}

    public function isActive(string $merchantWalletId): bool
    {
        return $this->walletModule->isActive($merchantWalletId);
    }

    public function getDisplayName(string $merchantWalletId): string
    {
        $userId = $this->walletModule->ownerActorId($merchantWalletId);

        if ($userId === null) {
            return 'Unknown Merchant';
        }

        $profile = UserProfileModel::where('user_id', $userId)->first();

        if ($profile === null) {
            return 'Unknown Merchant';
        }

        return trim("{$profile->first_name} {$profile->last_name}");
    }
}
