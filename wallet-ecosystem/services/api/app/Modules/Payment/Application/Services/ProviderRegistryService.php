<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentInstrumentType;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use App\Modules\Payment\Infrastructure\Models\ConsumerPaymentOptionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class ProviderRegistryService
{
    public function __construct(
        private readonly PaymentProviderRepositoryInterface $providerRepository,
        private readonly WalletModuleContract               $walletModule,
        private readonly EligibilityPipeline                $eligibilityPipeline,
    ) {
    }

    /**
     * Discover available payment options for a given context.
     *
     * The DB query pre-filters by platform restrictions (is_enabled/is_suspended/is_active).
     * The eligibility pipeline then evaluates each returned account through all 8 stages.
     * Only accounts passing all stages are included in the returned options.
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
            if ($provider->isMock() && ! app()->environment('local', 'development', 'testing')) {
                continue;
            }

            $eligibility = $this->eligibilityPipeline->evaluate($account, $query);
            if (! $eligibility->isEligible) {
                continue;
            }

            $isWallet             = $provider->adapter_identifier === 'olekard_wallet';
            $walletBalanceMinor   = null;
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

            $eligibleInstruments = array_values(array_filter(
                array_map(
                    static fn (string $v) => PaymentInstrumentType::tryFrom($v)?->value,
                    $account->supported_payment_methods ?? [],
                ),
                static fn (?string $v) => $v !== null,
            ));

            // T072: Load ConsumerPaymentOption display metadata for this account
            $consumerOption = ConsumerPaymentOptionModel::query()
                ->where('account_id', $account->id)
                ->where('is_active', true)
                ->orderBy('display_order')
                ->first();

            $options[] = new PaymentProviderOption(
                providerCode:         $provider->code,
                displayName:          $provider->display_name,
                isWallet:             $isWallet,
                isDefault:            $provider->is_default,
                sortOrder:            $provider->sort_order,
                walletBalanceMinor:   $walletBalanceMinor,
                hasSufficientBalance: $hasSufficientBalance,
                checkoutFlow:         $account->supported_payment_methods[0] ?? null,
                displayDescription:   $account->display_description,
                displayIconKey:       $account->display_icon_key,
                displayBadge:         $provider->isMock() ? 'Development mock — test only' : $account->display_badge,
                eligibleInstrumentTypes: $eligibleInstruments,
                capabilityFlags:      $account->capability_flags ?? [],
                displayLabel:         $consumerOption?->display_label ?? $account->display_label,
                paymentMethod:        $consumerOption?->payment_method,
                displayOrder:         $consumerOption?->display_order ?? 0,
                restrictions:         $consumerOption?->country_restrictions ?? [],
                supportedNextAction:  $consumerOption !== null ? 'redirect' : null,
            );
        }

        return $options;
    }
}
