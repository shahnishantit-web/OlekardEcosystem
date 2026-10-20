<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentModuleContract;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentIntentRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;

final class PaymentApplicationService implements PaymentModuleContract
{
    public function __construct(
        private readonly PaymentOrchestrationService       $orchestration,
        private readonly ProviderRegistryService           $registry,
        private readonly PaymentIntentRepositoryInterface  $intentRepository,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Amount is resolved server-side via BusinessPurposeResolverInterface.
     * The mobile-supplied amount in the request is ignored for financial purposes.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): array
    {
        $result = $this->orchestration->createIntent([
            'purpose_type'      => $request->purposeType,
            'purpose_reference' => $request->purposeReference,
            'organisation_id'   => $request->organisationId,
            'payer_actor_id'    => $request->payerActorId,
            'currency_code'     => $request->currencyCode,
            'idempotency_key'   => $request->idempotencyKey,
            'correlation_id'    => $request->correlationId,
            'metadata'          => $request->metadata,
        ]);

        // Reshape orchestration result into the contract's expected shape.
        // expires_at from orchestration is an ISO-8601 string; contract expects DateTimeImmutable.
        $expiresAt = $result['expires_at'] !== ''
            ? (new \DateTimeImmutable($result['expires_at']))
            : new \DateTimeImmutable('+30 minutes');

        return [
            'payment_intent_id'        => $result['id'],
            'payment_intent_reference' => $result['id'],
            'status'                   => $result['status'],
            'amount_minor'             => $result['amount_minor'],
            'currency_code'            => $result['currency_code'],
            'expires_at'               => $expiresAt,
            'correlation_id'           => $request->correlationId,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Options are filtered server-side. Mobile client MUST NOT hard-code providers.
     *
     * @return PaymentProviderOption[]
     */
    public function getAvailableOptions(OptionsQuery $query): array
    {
        return $this->registry->discoverOptions($query);
    }

    /**
     * {@inheritDoc}
     *
     * Validates actor ownership before exposing intent data.
     * Redirect URL arrival is NEVER proof of payment — always call this method.
     */
    public function getPaymentStatus(string $paymentIntentId, string $actorId): array
    {
        $intent = $this->intentRepository->findById($paymentIntentId);

        if ($intent === null) {
            throw new PaymentIntentNotFoundException($paymentIntentId);
        }

        // Server-side ownership check — never leak another actor's payment intent.
        if ($intent->payer_actor_id !== $actorId) {
            throw new PaymentIntentNotFoundException($paymentIntentId);
        }

        $activeAttempt      = $this->attemptRepository->findActiveForIntent($paymentIntentId);
        $activeAttemptShape = null;

        if ($activeAttempt !== null) {
            $attemptStatus = $activeAttempt->status instanceof PaymentAttemptStatus
                ? $activeAttempt->status->value
                : (string) $activeAttempt->status;

            $activeAttemptShape = [
                'attempt_id'       => $activeAttempt->id,
                'provider_code'    => $activeAttempt->provider_code,
                'status'           => $attemptStatus,
                'next_action_type' => $activeAttempt->next_action_type,
                'next_action_data' => $activeAttempt->next_action_data,
                'fee_minor'        => $activeAttempt->fee_minor,
            ];
        }

        $intentStatus = $intent->status->value;

        return [
            'payment_intent_id' => $intent->id,
            'reference'         => $intent->reference ?? $intent->id,
            'status'            => $intentStatus,
            'amount_minor'      => $intent->amount_minor,
            'currency_code'     => $intent->currency_code,
            'succeeded_at'      => $intent->succeeded_at,
            'active_attempt'    => $activeAttemptShape,
        ];
    }
}
