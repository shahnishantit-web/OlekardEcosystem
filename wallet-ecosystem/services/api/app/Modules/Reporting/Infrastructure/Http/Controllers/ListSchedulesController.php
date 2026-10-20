<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportScheduleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListSchedulesController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $userId    = (string) $request->attributes->get('identity.user_id', '');
        $perPage   = (int) $request->query('per_page', 20);
        $schedules = $this->service->listSchedules($userId, $perPage);

        return ReportScheduleResource::collection($schedules);
    }
}
