<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Requests\CreateReportScheduleRequest;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportScheduleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class CreateScheduleController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(CreateReportScheduleRequest $request): JsonResponse
    {
        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();

        $schedule = $this->service->create($userId, $request->validated(), $correlationId);

        return (new ReportScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }
}
