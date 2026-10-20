<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ScheduleRunResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListScheduleRunsController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(string $id, Request $request): AnonymousResourceCollection
    {
        $userId  = (string) $request->attributes->get('identity.user_id', '');
        $perPage = (int) $request->query('per_page', 20);
        $runs    = $this->service->listRuns($id, $userId, $perPage);

        return ScheduleRunResource::collection($runs);
    }
}
