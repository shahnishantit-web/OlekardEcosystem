<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;
use Ramsey\Uuid\Uuid;

/** Explicitly TEST ONLY protocol. Not a bank contract or certification evidence. */
final class AccountMockWebhookParser implements ProviderWebhookContract
{
    public function __construct(
        private readonly PaymentProviderAccountModel $account,
        private readonly string $providerCode,
        private readonly string $environment,
        private readonly SecretProviderInterface $secrets,
    ) {
        DevelopmentPaymentGuard::check();
    }

    public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent
    {
        DevelopmentPaymentGuard::check();
        $reference = $this->account->callback_secret_reference;
        if (! is_string($reference) || $reference === '') {
            throw new ProviderException('Payment callback secret reference is missing.');
        }
        // EnvSecretProvider is the installed foundation resolver, not configuration values.
        $secret = $this->secrets->get($reference);
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $rawPayload, $secret), $signature)) {
            throw new WebhookSignatureException('Invalid payment callback signature.');
        }
        try {
            $data = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('Invalid development callback JSON.');
        }
        if (! is_array($data) || ! is_string($data['event_id'] ?? null)
            || $data['event_id'] === '' || strlen($data['event_id']) > 200
            || ! is_string($data['transaction_ref'] ?? null) || ! Uuid::isValid($data['transaction_ref'])
            || ! is_string($data['merchant_account'] ?? null)
            || ! is_int($data['amount'] ?? null) || $data['amount'] < 0
            || ! is_string($data['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $data['currency'])
            || ! in_array($data['status'] ?? null, ['success', 'failed', 'pending', 'unknown'], true)) {
            throw new ProviderException('Incomplete development callback identity or money.');
        }
        $status = match ($data['status']) {
            'success' => ProviderStatus::SUCCESS,
            'failed' => ProviderStatus::FAILURE,
            default => ProviderStatus::UNKNOWN,
        };
        return NormalizedWebhookEvent::resolved(
            idempotencyKey: hash('sha256', $this->account->id.'|'.$this->environment.'|'.$data['event_id']),
            correlationId: (string) Uuid::uuid7(),
            platformEntityId: $data['transaction_ref'],
            platformEntityType: 'payment_attempt',
            providerKey: $this->providerCode,
            providerReference: $data['transaction_ref'],
            normalizedEventType: 'payment.'.$status->value,
            status: $status,
            receivedAt: new \DateTimeImmutable(),
            rawPayload: $rawPayload,
            metadata: [
                'payment_account_id' => $this->account->id,
                'payment_environment' => $this->environment,
                'payment_provider_code' => $this->providerCode,
                'account_config_version' => $this->account->config_version,
                'verification_method' => 'raw_body_hmac_sha256_development_only',
                'verification_key_reference' => $reference,
                'merchant_account' => $data['merchant_account'],
                'amount_minor' => $data['amount'],
                'currency' => $data['currency'],
                'test_only' => true,
            ],
        );
    }
}
