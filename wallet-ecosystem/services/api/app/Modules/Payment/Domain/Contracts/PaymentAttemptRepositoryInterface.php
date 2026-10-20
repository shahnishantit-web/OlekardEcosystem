<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;

interface PaymentAttemptRepositoryInterface
{
    public function save(PaymentAttemptModel $model): void;

    public function findById(string $id): ?PaymentAttemptModel;

    public function findActiveForIntent(string $paymentIntentId): ?PaymentAttemptModel;

    public function findByIdempotencyKey(string $key): ?PaymentAttemptModel;

    /** @return PaymentAttemptModel[] */
    public function findTimedOut(\DateTimeImmutable $before): array;
}
