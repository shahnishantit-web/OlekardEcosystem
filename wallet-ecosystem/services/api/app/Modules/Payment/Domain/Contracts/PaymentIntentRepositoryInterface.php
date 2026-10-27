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

    /**
     * P08 US2: Find intents in pending_ledger state for accounting repair.
     * Returns only pending_ledger intents (NOT ledger_failed — those are terminal).
     *
     * @return \Illuminate\Support\Collection<int, PaymentIntentModel>
     */
    public function findPendingAccounting(int $limit = 500): \Illuminate\Support\Collection;

    /**
     * P08 US4: Find intents with unresolved accounting for admin visibility.
     * Returns pending_ledger AND ledger_failed by default.
     * Pass $statusFilter to restrict to a single status value.
     *
     * @return \Illuminate\Support\Collection<int, PaymentIntentModel>
     */
    public function findAllUnresolvedAccounting(?string $statusFilter = null, int $limit = 100): \Illuminate\Support\Collection;
}
