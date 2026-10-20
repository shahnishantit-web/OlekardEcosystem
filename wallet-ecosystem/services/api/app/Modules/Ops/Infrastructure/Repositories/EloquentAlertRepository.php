<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\AlertRepositoryInterface;
use App\Modules\Ops\Domain\Enums\AlertStatus;
use App\Modules\Ops\Infrastructure\Models\AlertModel;
use Carbon\Carbon;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;

final class EloquentAlertRepository implements AlertRepositoryInterface
{
    public function create(string $alertRuleId, string $conditionSummary): AlertModel
    {
        return AlertModel::create([
            'id'                => Str::orderedUuid()->toString(),
            'alert_rule_id'     => $alertRuleId,
            'status'            => AlertStatus::Firing->value,
            'condition_summary' => $conditionSummary,
            'fired_at'          => Carbon::now(),
        ]);
    }

    public function resolve(string $id, Carbon $resolvedAt): AlertModel
    {
        $alert = AlertModel::findOrFail($id);
        $alert->update(['status' => AlertStatus::Resolved->value, 'resolved_at' => $resolvedAt]);
        return $alert->refresh();
    }

    public function findFiringForRule(string $alertRuleId): ?AlertModel
    {
        return AlertModel::where('alert_rule_id', $alertRuleId)
            ->where('status', AlertStatus::Firing->value)
            ->first();
    }

    public function findFiringByGroupingKey(string $groupingKey): ?AlertModel
    {
        return AlertModel::whereHas('rule', fn ($q) => $q->where('grouping_key', $groupingKey))
            ->where('status', AlertStatus::Firing->value)
            ->first();
    }

    public function paginate(?AlertStatus $status, ?string $severity, ?Carbon $from, int $perPage, ?string $cursor): CursorPaginator
    {
        $query = AlertModel::with('rule')->orderByDesc('fired_at');

        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($severity !== null) {
            $query->whereHas('rule', fn ($q) => $q->where('severity', $severity));
        }
        if ($from !== null) {
            $query->where('fired_at', '>=', $from);
        }

        return $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }
}
