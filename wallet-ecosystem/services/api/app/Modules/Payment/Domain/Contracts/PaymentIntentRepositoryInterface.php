<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;

interface PaymentIntentRepositoryInterface
{
    public function save(PaymentIntentModel $model): void;

    public function findById(string $id, bool $lock = false): ?PaymentIntentModel;

    public function findByIdempotencyKey(string $key, string $payerActorId): ?PaymentIntentModel;

    /** @return PaymentIntentModel[] */
    public function findActiveForPurpose(string $purposeType, string $purposeReference): array;

    /** @return PaymentIntentModel[] */
    public function findExpiring(\DateTimeImmutable $before): array;
}
