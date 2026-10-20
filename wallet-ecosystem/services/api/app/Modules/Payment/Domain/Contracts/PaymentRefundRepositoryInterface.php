<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;

interface PaymentRefundRepositoryInterface
{
    public function save(PaymentRefundModel $model): void;

    public function findById(string $id): ?PaymentRefundModel;

    /** @return PaymentRefundModel[] */
    public function findByIntentId(string $paymentIntentId): array;

    /**
     * Sum confirmed refund amounts for an attempt.
     * Used to enforce the cumulative refund limit.
     */
    public function sumConfirmedRefundsForAttempt(string $attemptId): int;
}
