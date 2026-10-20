<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\AlertStatus;
use App\Modules\Ops\Infrastructure\Models\AlertModel;
use Carbon\Carbon;
use Illuminate\Pagination\CursorPaginator;

interface AlertRepositoryInterface
{
    public function create(string $alertRuleId, string $conditionSummary): AlertModel;

    public function resolve(string $id, Carbon $resolvedAt): AlertModel;

    public function findFiringForRule(string $alertRuleId): ?AlertModel;

    public function findFiringByGroupingKey(string $groupingKey): ?AlertModel;

    /** @return CursorPaginator<AlertModel> */
    public function paginate(?AlertStatus $status, ?string $severity, ?Carbon $from, int $perPage, ?string $cursor): CursorPaginator;
}
