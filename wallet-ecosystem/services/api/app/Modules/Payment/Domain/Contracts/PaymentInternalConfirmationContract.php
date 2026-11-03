<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Payment\Domain\ValueObjects\PaymentConfirmationEvidence;

/** Internal service-only evidence query; never exposed by the payer status API. */
interface PaymentInternalConfirmationContract
{
    public function confirmationEvidence(
        ServiceActor $service,
        string $paymentIntentId,
        string $organisationId,
        string $purposeType,
        string $purposeReference,
    ): ?PaymentConfirmationEvidence;

    public function acknowledgeFulfilment(
        ServiceActor $service,
        string $paymentIntentId,
        string $organisationId,
        string $purposeType,
        string $purposeReference,
        string $fulfilmentReference,
        string $idempotencyKey,
    ): void;
}
