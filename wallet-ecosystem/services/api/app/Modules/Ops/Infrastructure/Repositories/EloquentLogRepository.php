<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use App\Modules\Ops\Infrastructure\Models\LogEntryModel;
use Carbon\Carbon;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;

final class EloquentLogRepository implements LogRepositoryInterface
{
    public function create(string $serviceName, string $correlationId, LogSeverity $severity, string $message, array $context): LogEntryModel
    {
        return LogEntryModel::create([
            'id'             => Str::orderedUuid()->toString(),
            'service_name'   => $serviceName,
            'correlation_id' => $correlationId,
            'severity'       => $severity->value,
            'message'        => $message,
            'context'        => $context,
            'created_at'     => Carbon::now(),
        ]);
    }

    public function search(
        ?string $correlationId,
        ?LogSeverity $severity,
        ?string $serviceName,
        ?Carbon $from,
        ?Carbon $to,
        int $perPage,
        ?string $cursor
    ): CursorPaginator {
        $query = LogEntryModel::query()->orderByDesc('created_at');

        if ($correlationId !== null) {
            $query->where('correlation_id', $correlationId);
        }
        if ($severity !== null) {
            $query->where('severity', $severity->value);
        }
        if ($serviceName !== null) {
            $query->where('service_name', $serviceName);
        }
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }
}
