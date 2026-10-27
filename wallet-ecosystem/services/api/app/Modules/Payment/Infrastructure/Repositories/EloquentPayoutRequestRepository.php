<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentPayoutRequestRepository implements PayoutRequestRepositoryInterface
{
    public function findById(string $id): ?PayoutRequestModel
    {
        /** @var PayoutRequestModel|null */
        return PayoutRequestModel::query()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?PayoutRequestModel
    {
        /** @var PayoutRequestModel|null */
        return PayoutRequestModel::query()->where('idempotency_key', $key)->first();
    }

    public function sumActiveReservationsForFund(string $fundReferenceId, string $currencyCode): int
    {
        $result = DB::connection('payment')
            ->table('payout_reservations')
            ->join('payout_requests', 'payout_reservations.payout_request_id', '=', 'payout_requests.id')
            ->where('payout_requests.fund_reference_id', $fundReferenceId)
            ->where('payout_requests.currency_code', $currencyCode)
            ->whereIn('payout_reservations.status', ['awaiting_approval', 'approved'])
            ->sum('payout_reservations.reserved_amount_minor');

        return (int) $result;
    }

    public function save(PayoutRequestModel $model): void
    {
        $model->save();
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PayoutRequestModel::query();

        if (isset($filters['fund_reference_id'])) {
            $query->where('fund_reference_id', $filters['fund_reference_id']);
        }

        if (isset($filters['organisation_id'])) {
            $query->where('organisation_id', $filters['organisation_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
