<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportScheduleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GetScheduleController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $userId   = (string) $request->attributes->get('identity.user_id', '');
        $schedule = $this->service->getSchedule($id, $userId);

        return (new ReportScheduleResource($schedule))->response();
    }
}
