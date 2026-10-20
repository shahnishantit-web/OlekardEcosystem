<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Contracts;

interface MerchantReadServiceInterface
{
    public function isActive(string $merchantWalletId): bool;

    public function getDisplayName(string $merchantWalletId): string;
}
