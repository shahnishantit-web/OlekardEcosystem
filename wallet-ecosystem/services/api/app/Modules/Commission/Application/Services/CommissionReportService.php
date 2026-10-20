<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CommissionReportService
{
    public function listPaid(string $agentId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = CommissionModel::where('agent_id', $agentId)
            ->where('status', CommissionStatus::Paid->value);

        if (isset($filters['from'])) {
            $query->where('updated_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('updated_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('updated_at')->paginate(perPage: $perPage, page: $page);
    }

    public function listPending(string $agentId, int $page, int $perPage): array
    {
        $pendingStatuses = [CommissionStatus::Pending->value, CommissionStatus::Approved->value];

        $paginator = CommissionModel::where('agent_id', $agentId)
            ->whereIn('status', $pendingStatuses)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        $pendingTotal = CommissionModel::where('agent_id', $agentId)
            ->whereIn('status', $pendingStatuses)
            ->sum('amount');

        $currencyCode = CommissionModel::where('agent_id', $agentId)->value('currency_code') ?? 'BWP';

        return [
            'data'          => $paginator,
            'pending_total' => (int) $pendingTotal,
            'currency_code' => $currencyCode,
        ];
    }

    public function report(array $filters, int $page, int $perPage): array
    {
        $query = CommissionModel::query();

        if (isset($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $data = (clone $query)->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        $totalsByStatus = CommissionModel::when(isset($filters['agent_id']), fn ($q) => $q->where('agent_id', $filters['agent_id']))
            ->when(isset($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->selectRaw('status, SUM(amount) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $totalsByType = CommissionModel::when(isset($filters['agent_id']), fn ($q) => $q->where('agent_id', $filters['agent_id']))
            ->when(isset($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->selectRaw('transaction_type, SUM(amount) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type')
            ->all();

        return [
            'data'             => $data,
            'totals_by_status' => $totalsByStatus,
            'totals_by_type'   => $totalsByType,
        ];
    }
}
