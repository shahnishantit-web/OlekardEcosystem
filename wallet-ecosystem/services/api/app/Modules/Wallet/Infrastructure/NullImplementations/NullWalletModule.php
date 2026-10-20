<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Platform\Domain\Contracts\CurrencyContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class NullWalletModule implements WalletModuleContract
{
    public function isActive(string $walletId): bool
    {
        throw new ModuleNotImplementedException('Wallet', 'isActive');
    }

    public function ownerActorId(string $walletId): ?string
    {
        throw new ModuleNotImplementedException('Wallet', 'ownerActorId');
    }

    public function currency(string $walletId): ?CurrencyContract
    {
        throw new ModuleNotImplementedException('Wallet', 'currency');
    }

    public function pocketExists(string $walletId, string $currencyCode): bool
    {
        throw new ModuleNotImplementedException('Wallet', 'pocketExists');
    }

    public function availableBalance(string $walletId, string $currencyCode): ?int
    {
        throw new ModuleNotImplementedException('Wallet', 'availableBalance');
    }
}
