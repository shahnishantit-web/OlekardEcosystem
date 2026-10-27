<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\ConfigurationVersionModel;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * T131: Resolves the ProviderWebhookContract implementation for a given provider_code.
 * Parsers are registered by provider code under 'payment.callback_parser.{providerCode}'.
 */
final class PaymentCallbackService
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Resolve the parser for the given provider code.
     *
     * @throws ProviderException if no parser is registered for the provider.
     */
    public function resolveParser(string $providerCode): ProviderWebhookContract
    {
        $key = 'payment.callback_parser.'.$providerCode;

        if (! $this->container->bound($key)) {
            throw new ProviderException("No webhook parser registered for provider: {$providerCode}");
        }

        $parser = $this->container->make($key);

        if (! $parser instanceof ProviderWebhookContract) {
            throw new ProviderException("Parser for provider '{$providerCode}' does not implement ProviderWebhookContract.");
        }

        return $parser;
    }

    /**
     * Register a parser for a provider code. Called from service providers.
     */
    public function registerParser(string $providerCode, string $parserClass): void
    {
        $this->container->bind(
            'payment.callback_parser.'.$providerCode,
            $parserClass,
        );
    }

    /**
     * T080: Resolve the ConfigurationVersion snapshot for a payment attempt's callback.
     *
     * Callbacks MUST use the config version recorded at attempt initiation (FR-023),
     * not the current account state. This ensures callbacks continue processing even
     * when the account is suspended or retired.
     *
     * Returns null if the attempt has no configuration_version_id (pre-P12 attempt).
     */
    public function resolveConfigVersionForAttempt(string $attemptId): ?ConfigurationVersionModel
    {
        $attempt = PaymentAttemptModel::query()->find($attemptId);

        if ($attempt === null || empty($attempt->configuration_version_id)) {
            return null;
        }

        return ConfigurationVersionModel::query()->find($attempt->configuration_version_id);
    }
}
