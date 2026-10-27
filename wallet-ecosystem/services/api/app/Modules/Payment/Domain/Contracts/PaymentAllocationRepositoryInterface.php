<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentAllocationModel;
use Illuminate\Support\Collection;

interface PaymentAllocationRepositoryInterface
{
    /** Create a new allocation record. */
    public function create(array $data): PaymentAllocationModel;

    /** Find an allocation by its idempotency key; returns null if not found. */
    public function findByIdempotencyKey(string $key): ?PaymentAllocationModel;

    /** @return Collection<int, PaymentAllocationModel> */
    public function findByIntentId(string $intentId): Collection;
}
