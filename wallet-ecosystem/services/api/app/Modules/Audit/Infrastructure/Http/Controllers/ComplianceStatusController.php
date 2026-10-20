<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Audit\Infrastructure\Http\Resources\ComplianceStatusResource;
use Illuminate\Routing\Controller;

final class ComplianceStatusController extends Controller
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function __invoke(string $userId): ComplianceStatusResource
    {

        return new ComplianceStatusResource($this->service->getStatus($userId));
    }
}
