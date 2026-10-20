<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentIntentRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;

interface PaymentModuleContract
{
    /**
     * Create a payment intent for a horizontal module.
     *
     * The amount is resolved server-side via BusinessPurposeResolverInterface.
     * The mobile-supplied amount in the request is ignored for financial purposes.
     *
     * @return array{
     *   payment_intent_id: string,
     *   payment_intent_reference: string,
     *   status: string,
     *   amount_minor: int,
     *   currency_code: string,
     *   expires_at: \DateTimeImmutable,
     *   correlation_id: string,
     * }
     * @throws PurposeNotPayableException
     */
    public function createPaymentIntent(PaymentIntentRequest $request): array;

    /**
     * Discover available payment options for a given payment context.
     *
     * Options are filtered server-side. The mobile client MUST use this list — MUST NOT hard-code providers.
     *
     * @return PaymentProviderOption[]
     */
    public function getAvailableOptions(OptionsQuery $query): array;

    /**
     * Get authoritative payment status. Redirect URL arrival is NEVER treated as proof.
     *
     * @return array{
     *   payment_intent_id: string,
     *   reference: string,
     *   status: string,
     *   amount_minor: int,
     *   currency_code: string,
     *   succeeded_at: ?\DateTimeImmutable,
     *   active_attempt: ?array,
     * }
     * @throws PaymentIntentNotFoundException
     */
    public function getPaymentStatus(string $paymentIntentId, string $actorId): array;
}
