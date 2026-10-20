<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Exceptions\ProviderException;

/**
 * T100: Verifies HMAC signatures on bank provider callbacks.
 * Secret is resolved via ConfigurationService (reference → actual value).
 * Constant-time comparison via hash_equals to prevent timing attacks.
 * Secret value is NEVER stored or logged.
 */
final class BankCallbackVerifier
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {
    }

    /**
     * @throws ProviderException if signature is invalid or algorithm unsupported
     */
    public function verify(
        string $rawPayload,
        string $signature,
        string $algorithm,
        string $secretRef,
    ): bool {
        $supportedAlgorithms = ['sha256', 'sha512', 'sha384'];

        if (! in_array($algorithm, $supportedAlgorithms, strict: true)) {
            throw new ProviderException(
                "Unsupported HMAC algorithm '{$algorithm}'. Supported: ".implode(', ', $supportedAlgorithms)
            );
        }

        // Resolve actual secret from ConfigurationService — never cache or log the value
        $secretValue = $this->config->get($secretRef);

        if (empty($secretValue) || ! is_string($secretValue)) {
            throw new ProviderException(
                "Callback secret reference '{$secretRef}' is not configured or is empty."
            );
        }

        $expected = hash_hmac($algorithm, $rawPayload, $secretValue);

        // Constant-time comparison to prevent timing attacks
        return hash_equals($expected, $signature);
    }
}
