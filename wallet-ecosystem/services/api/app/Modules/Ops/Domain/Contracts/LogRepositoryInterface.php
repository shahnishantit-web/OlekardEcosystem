<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Domain\Enums\LogSeverity;
use App\Modules\Ops\Infrastructure\Models\LogEntryModel;
use Carbon\Carbon;
use Illuminate\Pagination\CursorPaginator;

interface LogRepositoryInterface
{
    public function create(string $serviceName, string $correlationId, LogSeverity $severity, string $message, array $context): LogEntryModel;

    /** @return CursorPaginator<LogEntryModel> */
    public function search(
        ?string $correlationId,
        ?LogSeverity $severity,
        ?string $serviceName,
        ?Carbon $from,
        ?Carbon $to,
        int $perPage,
        ?string $cursor
    ): CursorPaginator;
}
