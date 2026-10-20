<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentCancelResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Exceptions\ProviderException;

interface PaymentAdapterInterface extends ProviderAdapterInterface
{
    /**
     * Initiate a payment for a specific attempt.
     *
     * @throws ProductionBlockedException if adapter is mock and APP_ENV=production
     * @throws InsufficientFundsException if wallet balance is insufficient
     * @throws StepUpRequiredException if step-up authentication is required
     * @throws ProviderException on unrecoverable provider error
     */
    public function initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult;

    /**
     * Query the current payment status from the provider.
     * For synchronous providers (wallet), reads from DB. For async (bank), calls provider API.
     */
    public function queryPaymentStatus(string $providerReferenceId): PaymentStatusResult;

    /**
     * Initiate a refund for a succeeded payment.
     *
     * @throws CapabilityUnsupportedException if adapter does not support refunds
     * @throws RefundLimitExceededException if refund exceeds original amount minus prior refunds
     */
    public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult;

    /**
     * Cancel a pending or awaiting-action payment attempt.
     *
     * @throws CapabilityUnsupportedException if adapter does not support cancellation
     */
    public function cancelPayment(string $providerReferenceId): PaymentCancelResult;
}
