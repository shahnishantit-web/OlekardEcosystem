<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;

final class PaymentWebhookFactory
{
    public function __construct(private readonly SecretProviderInterface $secrets) {}

    public function forIntegration(IntegrationModel $integration): ProviderWebhookContract
    {
        $config = $integration->configuration ?? [];
        if (! \Ramsey\Uuid\Uuid::isValid($config['payment_provider_account_id'] ?? '')) {
            throw new ProviderException('Payment integration account mapping is not configured.');
        }
        $account = PaymentProviderAccountModel::with('provider')->find($config['payment_provider_account_id'] ?? '');
        if ($account === null || $account->provider === null
            || ($config['environment'] ?? null) !== $account->provider->environment
            || ($config['payment_provider_code'] ?? null) !== $account->provider->code) {
            throw new ProviderException('Payment integration account mapping is not configured.');
        }
        // Payment provider/account enablement governs NEW attempts, not resolution.
        // No live bank parser is registered without reviewed provider contracts.
        if ($account->provider->adapter_identifier !== 'mock_bank') {
            throw new ProviderException('Reviewed provider callback contract is not installed.');
        }
        return new AccountMockWebhookParser($account, $account->provider->code, $account->provider->environment, $this->secrets);
    }
}
