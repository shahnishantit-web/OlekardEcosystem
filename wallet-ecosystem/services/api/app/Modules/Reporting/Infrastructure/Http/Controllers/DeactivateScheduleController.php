<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportScheduleApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DeactivateScheduleController
{
    public function __construct(
        private readonly ReportScheduleApplicationService $service,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();
        $this->service->deactivate($id, $userId, $correlationId);

        return response()->json(null, 204);
    }
}
