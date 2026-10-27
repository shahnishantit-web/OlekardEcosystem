<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use Illuminate\Support\Collection;

interface PaymentConfigChangeDraftRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): PaymentConfigChangeDraftModel;

    public function findById(string $id): ?PaymentConfigChangeDraftModel;

    public function findByIdempotencyKey(string $key): ?PaymentConfigChangeDraftModel;

    /** @return Collection<int, PaymentConfigChangeDraftModel> */
    public function findPendingByProviderId(string $providerId): Collection;

    public function updateStatus(
        string $id,
        ConfigChangeDraftStatus $status,
        ?string $reviewerActorId,
        ?string $notes,
    ): PaymentConfigChangeDraftModel;
}
