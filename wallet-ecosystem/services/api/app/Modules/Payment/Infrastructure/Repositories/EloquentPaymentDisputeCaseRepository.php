<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentPaymentDisputeCaseRepository implements PaymentDisputeCaseRepositoryInterface
{
    public function findById(string $id): ?PaymentDisputeCaseModel
    {
        /** @var PaymentDisputeCaseModel|null */
        return PaymentDisputeCaseModel::find($id);
    }

    public function findByProviderRef(string $providerCode, string $providerDisputeReference): ?PaymentDisputeCaseModel
    {
        /** @var PaymentDisputeCaseModel|null */
        return PaymentDisputeCaseModel::query()
            ->where('provider_code', $providerCode)
            ->where('provider_dispute_reference', $providerDisputeReference)
            ->first();
    }

    public function listFiltered(array $filters): LengthAwarePaginator
    {
        $query = PaymentDisputeCaseModel::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['case_type'])) {
            $query->where('case_type', $filters['case_type']);
        }

        if (isset($filters['requires_finance_review'])) {
            $query->where('requires_finance_review', (bool) $filters['requires_finance_review']);
        }

        if (isset($filters['deadline_before'])) {
            $query->where('response_deadline', '<=', $filters['deadline_before']);
        }

        if (isset($filters['payment_intent_id'])) {
            $query->whereHas('attempt', function ($q) use ($filters): void {
                $q->where('payment_intent_id', $filters['payment_intent_id']);
            });
        }

        if (isset($filters['payment_attempt_id'])) {
            $query->where('payment_attempt_id', $filters['payment_attempt_id']);
        }

        // Default ordering: response_deadline ASC (NULL last), then created_at DESC
        $query->orderByRaw('response_deadline ASC NULLS LAST')->orderByDesc('created_at');

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        $page    = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function save(PaymentDisputeCaseModel $model): void
    {
        $model->save();
    }
}
