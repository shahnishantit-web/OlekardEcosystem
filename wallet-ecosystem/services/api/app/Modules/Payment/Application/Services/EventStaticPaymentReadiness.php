<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\EventStaticReadinessQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use DateTimeImmutable;

/** Actor-free, uncached configured-method check. This is not a provider-health or certification check. */
final class EventStaticPaymentReadiness
{
    public function __construct(
        private readonly PaymentProviderRepositoryInterface $providers,
        private readonly EligibilityPipeline $eligibility,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(EventStaticReadinessQuery $query): array
    {
        $accounts = $this->providers->findAllWithProviders();
        $categories = [];
        $observedVersions = [];
        $allOnlyWallet = true;
        foreach ($query->categoryContexts as $categoryId => $context) {
            $methods = [];
            $reasonCodes = [];
            foreach ($accounts as $account) {
                if (! $this->includeEnvironment($account)) {
                    continue;
                }
                $eligibility = $this->eligibility->evaluate($account, $context);
                if (! $eligibility->isEligible) {
                    if ($eligibility->reason !== null) {
                        $reasonCodes[] = $eligibility->reason->value;
                    }

                    continue;
                }
                $configured = $account->supported_payment_methods ?? [];
                if ($configured === []) {
                    $reasonCodes[] = 'NO_CONFIGURED_METHOD';

                    continue;
                }
                foreach ($configured as $method) {
                    $methods[] = [
                        'method' => (string) $method,
                        'wallet_only' => $account->provider?->adapter_identifier === 'olekard_wallet',
                        'test_only' => $account->provider?->isMock() ?? false,
                    ];
                }
                $provider = $account->provider;
                if ($provider !== null) {
                    $observedVersions[(string) $provider->id] = [
                        'provider_metadata_version' => (int) $provider->metadata_version,
                        'account_config_version' => (int) $account->config_version,
                        'active_config_version_id' => $account->active_config_version_id,
                    ];
                }
            }
            $walletOnly = $methods !== [] && count(array_filter($methods, static fn (array $method): bool => $method['wallet_only'])) === count($methods);
            if ($methods === [] || ! $walletOnly) {
                $allOnlyWallet = false;
            }
            $categories[] = [
                'category_id' => (string) $categoryId,
                'configured' => $methods !== [],
                'wallet_only' => $walletOnly,
                'development_only' => $methods !== [] && count(array_filter($methods, static fn (array $method): bool => $method['test_only'])) > 0,
                'method_classes' => array_values(array_unique(array_column($methods, 'method'))),
                'reason_codes' => $methods === []
                    ? array_values(array_unique([...$reasonCodes, 'NO_CONFIGURED_METHOD_FOR_CATEGORY']))
                    : [],
            ];
        }

        return [
            'event_id' => $query->eventId,
            'event_version' => $query->eventVersion,
            'eligible' => ! in_array(false, array_column($categories, 'configured'), true),
            'wallet_only' => $allOnlyWallet,
            'categories' => $categories,
            'evaluated_at' => (new DateTimeImmutable)->format(DATE_ATOM),
            'provider_account_versions' => $observedVersions,
            'meaning' => 'Configured static eligibility only; it does not establish customer balance, provider uptime, certification, payment success, or financial closure.',
        ];
    }

    private function includeEnvironment(PaymentProviderAccountModel $account): bool
    {
        if (app()->environment(['local', 'development', 'testing'])) {
            return true;
        }

        return $account->provider !== null && ! $account->provider->isMock();
    }
}
