<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class ProviderRegistryService
{
    public function __construct(
        private readonly PaymentProviderRepositoryInterface $providerRepository,
        private readonly WalletModuleContract               $walletModule,
    ) {
    }

    /**
     * Discover available payment options for a given context.
     *
     * @return PaymentProviderOption[]
     */
    public function discoverOptions(OptionsQuery $query): array
    {
        $accounts = $this->providerRepository->findActiveForContext($query);

        $options = [];
        foreach ($accounts as $account) {
            $provider = $account->provider;
            if ($provider === null) {
                continue;
            }

            $isWallet          = $provider->adapter_identifier === 'olekard_wallet';
            $walletBalanceMinor = null;
            $hasSufficientBalance = null;

            if ($isWallet) {
                $walletId = $this->walletModule->findPrimaryWalletId($query->actorId);
                if ($walletId !== null) {
                    $walletBalanceMinor   = $this->walletModule->availableBalance($walletId, $query->currencyCode);
                    $hasSufficientBalance = $walletBalanceMinor !== null && $walletBalanceMinor >= $query->amountMinor;
                } else {
                    $hasSufficientBalance = false;
                }
            }

            $options[] = new PaymentProviderOption(
                providerCode:         $provider->code,
                displayName:          $provider->display_name,
                isWallet:             $isWallet,
                isDefault:            $provider->is_default,
                sortOrder:            $provider->sort_order,
                walletBalanceMinor:   $walletBalanceMinor,
                hasSufficientBalance: $hasSufficientBalance,
                checkoutFlow:         $account->supported_payment_methods[0] ?? null,
            );
        }

        return $options;
    }
}
