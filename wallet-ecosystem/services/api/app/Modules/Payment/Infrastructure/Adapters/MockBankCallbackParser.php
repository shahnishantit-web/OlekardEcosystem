<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Ramsey\Uuid\Uuid;

/**
 * T109: Mock bank callback parser for development and testing ONLY.
 * Permanently production-blocked; delegates signature verification to BankCallbackVerifier.
 *
 * Expected payload shape:
 *   { "transaction_ref": "<attempt_id>", "status": "success|failed|pending",
 *     "timestamp": "1234567890", "correlation_id": "<uuid>",
 *     "amount": 10000, "currency": "BWP" }
 */
final class MockBankCallbackParser implements ProviderWebhookContract
{
    public function __construct(
        private readonly BankCallbackVerifier $verifier,
    ) {
        $env = app()->environment();
        if ($env === 'production' || $env === 'staging') {
            throw new ProductionBlockedException('mock_bank_callback_parser');
        }
    }

    /**
     * @param  array<string,string> $headers
     * @throws WebhookSignatureException  On invalid or absent HMAC-SHA256 signature.
     * @throws ProviderException          On structurally invalid payload.
     */
    public function parse(
        string $rawPayload,
        string $signature,
        array  $headers,
    ): NormalizedWebhookEvent {
        // Step 1: verify HMAC-SHA256 before any parsing
        $isValid = $this->verifier->verify(
            rawPayload:   $rawPayload,
            signature:    $signature,
            algorithm:    'sha256',
            secretRef:    'payment.mock_bank.callback_secret',
        );

        if (! $isValid) {
            throw new WebhookSignatureException('Mock bank callback HMAC-SHA256 verification failed.');
        }

        // Step 2: parse payload
        $data = json_decode($rawPayload, true);
        if (! is_array($data)) {
            throw new ProviderException('Mock bank callback payload is not valid JSON.');
        }

        $transactionRef = isset($data['transaction_ref']) && is_string($data['transaction_ref'])
            ? $data['transaction_ref']
            : null;

        $rawStatus = isset($data['status']) && is_string($data['status'])
            ? $data['status']
            : 'unknown';

        $timestamp = isset($data['timestamp']) && (is_string($data['timestamp']) || is_int($data['timestamp']))
            ? (string) $data['timestamp']
            : (string) time();

        $correlationId = isset($data['correlation_id']) && is_string($data['correlation_id'])
            ? $data['correlation_id']
            : (string) Uuid::uuid7();

        // Step 3: map provider status
        $providerStatus = match ($rawStatus) {
            'success' => ProviderStatus::SUCCESS,
            'failed'  => ProviderStatus::FAILURE,
            default   => ProviderStatus::UNKNOWN,
        };

        $normalizedEventType = match ($rawStatus) {
            'success' => 'payment.succeeded',
            'failed'  => 'payment.failed',
            default   => 'payment.unknown',
        };

        // idempotency_key = transaction_ref + ':' + timestamp (stable across retries)
        $idempotencyKey = ($transactionRef ?? 'unknown') . ':' . $timestamp;

        if ($transactionRef !== null) {
            return NormalizedWebhookEvent::resolved(
                idempotencyKey:      $idempotencyKey,
                correlationId:       $correlationId,
                platformEntityId:    $transactionRef,
                platformEntityType:  'payment_attempt',
                providerKey:         'mock_bank',
                providerReference:   $transactionRef,
                normalizedEventType: $normalizedEventType,
                status:              $providerStatus,
                receivedAt:          new \DateTimeImmutable(),
                rawPayload:          $rawPayload,
            );
        }

        return NormalizedWebhookEvent::unresolved(
            idempotencyKey:      $idempotencyKey,
            correlationId:       $correlationId,
            providerKey:         'mock_bank',
            providerReference:   null,
            normalizedEventType: $normalizedEventType,
            status:              $providerStatus,
            receivedAt:          new \DateTimeImmutable(),
            rawPayload:          $rawPayload,
            platformEntityType:  'payment_attempt',
        );
    }
}
