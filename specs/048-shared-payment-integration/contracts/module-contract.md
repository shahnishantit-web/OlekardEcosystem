# Module Contract: PaymentModuleContract (E48)

**File**: `app/Modules/Payment/Domain/Contracts/PaymentModuleContract.php`

This is the ONLY interface through which horizontal business modules interact with the Payment module. No module outside `App\Modules\Payment\` may depend on Payment domain internals.

---

## PHP Interface

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PaymentIntentRequest;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;

interface PaymentModuleContract
{
    /**
     * Create a payment intent for a horizontal module.
     *
     * The amount is resolved server-side via BusinessPurposeResolverInterface.
     * The mobile-supplied amount in the request is ignored for financial purposes.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException
     * @throws \App\Modules\Payment\Domain\Exceptions\DuplicateActiveIntentException
     * @return array{
     *   payment_intent_id: string,
     *   payment_intent_reference: string,
     *   status: string,
     *   amount_minor: int,
     *   currency_code: string,
     *   expires_at: \DateTimeImmutable,
     *   correlation_id: string,
     * }
     */
    public function createPaymentIntent(PaymentIntentRequest $request): array;

    /**
     * Discover available payment options for a given payment context.
     *
     * Options are filtered server-side by organisation, module, currency, amount, country,
     * and provider status. The mobile client MUST use this list — it MUST NOT hard-code providers.
     *
     * @return PaymentProviderOption[]
     */
    public function getAvailableOptions(OptionsQuery $query): array;

    /**
     * Get authoritative payment status for a payment intent.
     *
     * Called after redirect return. The redirect URL is NEVER treated as proof of payment.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException
     * @throws \App\Modules\Payment\Domain\Exceptions\AccessDeniedException
     * @return array{
     *   payment_intent_id: string,
     *   reference: string,
     *   status: string,
     *   amount_minor: int,
     *   currency_code: string,
     *   succeeded_at: ?\DateTimeImmutable,
     *   active_attempt: ?array,
     * }
     */
    public function getPaymentStatus(string $paymentIntentId, string $actorId): array;
}
```

---

## BusinessPurposeResolverInterface

Each horizontal module MUST implement this interface and register it with the `purpose_type` it owns. The Payment module calls it to resolve the authoritative payable obligation.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PayableObligation;

interface BusinessPurposeResolverInterface
{
    /**
     * The purpose_type string this resolver handles (e.g., 'funeral_contribution').
     */
    public function supportedPurposeType(): string;

    /**
     * Resolve the payable obligation for a purpose reference.
     *
     * The Payment module calls this to get the authoritative amount, currency,
     * organisation, and payer. The mobile-supplied amount is NEVER used.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException
     *         When the purpose does not exist, is already paid, or is ineligible.
     */
    public function resolve(
        string $purposeReference,
        string $actorId,
        string $correlationId,
    ): PayableObligation;
}
```

---

## PaymentAdapterInterface

Implemented by each payment adapter (WalletPaymentAdapter, MockBankPaymentAdapter, future real bank adapters).

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;

interface PaymentAdapterInterface extends ProviderAdapterInterface
{
    /**
     * Initiate a payment for a specific attempt.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\ProductionBlockedException
     * @throws \App\Modules\Payment\Domain\Exceptions\InsufficientFundsException
     * @throws \App\Modules\Payment\Domain\Exceptions\StepUpRequiredException
     * @throws \App\Modules\Platform\Domain\Exceptions\ProviderException
     */
    public function initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult;

    /**
     * Query the current payment status from the provider.
     * For synchronous providers (e.g., wallet), this reads from DB.
     * For async providers (e.g., bank), this calls the provider API.
     */
    public function queryPaymentStatus(string $providerReferenceId): PaymentStatusResult;

    /**
     * Initiate a refund for a succeeded payment.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException
     * @throws \App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException
     */
    public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult;

    /**
     * Cancel a pending or awaiting-action payment attempt.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException
     */
    public function cancelPayment(string $providerReferenceId): PaymentCancelResult;

    /**
     * Return the capabilities this adapter supports.
     * Results are static — must not query provider at runtime.
     */
    public function getCapabilities(): ProviderCapabilitySet;

    /** Returns the configuration key: e.g., 'olekard_wallet', 'mock_bank' */
    public function getProviderIdentifier(): string;
}
```

---

## Integration Events (consumed by horizontal modules)

Business modules subscribe to these events via their event listeners. They MUST NOT couple to Payment module internals.

### `payment.confirmed`

```php
// Published by: OutboxEventPublisher after PaymentAttempt transitions to succeeded
// Payload shape:
[
    'event'                  => 'payment.confirmed',
    'payment_intent_id'      => string,   // UUID v7
    'payment_attempt_id'     => string,   // UUID v7
    'purpose_type'           => string,
    'purpose_reference'      => string,   // UUID v7
    'organisation_id'        => string,   // UUID v7
    'payer_actor_id'         => string,   // UUID v7
    'amount_minor'           => int,
    'currency_code'          => string,
    'provider_code'          => string,
    'fee_minor'              => int,
    'correlation_id'         => string,
    'occurred_at'            => string,   // ISO 8601 UTC
]
```

### `payment.failed`

```php
[
    'event'               => 'payment.failed',
    'payment_intent_id'   => string,
    'purpose_type'        => string,
    'purpose_reference'   => string,
    'organisation_id'     => string,
    'payer_actor_id'      => string,
    'failure_reason'      => string,   // normalised code
    'correlation_id'      => string,
    'occurred_at'         => string,
]
```

### `payment.reversed`

```php
[
    'event'               => 'payment.reversed',
    'payment_intent_id'   => string,
    'payment_attempt_id'  => string,
    'purpose_type'        => string,
    'purpose_reference'   => string,
    'organisation_id'     => string,
    'amount_minor'        => int,
    'currency_code'       => string,
    'correlation_id'      => string,
    'occurred_at'         => string,
]
```

### `refund.confirmed`

```php
[
    'event'                     => 'refund.confirmed',
    'payment_intent_id'         => string,
    'payment_refund_id'         => string,
    'purpose_type'              => string,
    'purpose_reference'         => string,
    'organisation_id'           => string,
    'refund_amount_minor'       => int,
    'currency_code'             => string,
    'is_full_refund'            => bool,
    'remaining_refundable_minor' => int,
    'correlation_id'            => string,
    'occurred_at'               => string,
]
```
