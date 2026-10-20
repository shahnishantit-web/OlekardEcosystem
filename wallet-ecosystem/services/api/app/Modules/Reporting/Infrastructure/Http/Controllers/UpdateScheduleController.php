<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Requests\UpdateReportScheduleRequest;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportScheduleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class UpdateScheduleController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(string $id, UpdateReportScheduleRequest $request): JsonResponse
    {
        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();
        $schedule      = $this->service->update($id, $userId, $request->validated(), $correlationId);

        return (new ReportScheduleResource($schedule))->response();
    }
}
