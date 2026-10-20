<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\CurrencyContract;

interface WalletModuleContract
{
    /** Returns true if the wallet exists and is in an active, operable state. */
    public function isActive(string $walletId): bool;

    /** Returns the UUID of the actor who owns the wallet, or null if the wallet does not exist. */
    public function ownerActorId(string $walletId): ?string;

    /** Returns the currency of the wallet. */
    public function currency(string $walletId): CurrencyContract;
}
