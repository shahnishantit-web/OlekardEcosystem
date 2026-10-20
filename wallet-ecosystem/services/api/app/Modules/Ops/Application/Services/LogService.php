<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use Carbon\Carbon;
use Illuminate\Pagination\CursorPaginator;

final class LogService
{
    public function __construct(
        private readonly LogRepositoryInterface $repository,
    ) {}

    /** @return CursorPaginator<mixed> */
    public function search(
        ?string $correlationId,
        ?LogSeverity $severity,
        ?string $serviceName,
        ?Carbon $from,
        ?Carbon $to,
        int $perPage = 50,
        ?string $cursor = null,
    ): CursorPaginator {
        return $this->repository->search($correlationId, $severity, $serviceName, $from, $to, $perPage, $cursor);
    }
}
