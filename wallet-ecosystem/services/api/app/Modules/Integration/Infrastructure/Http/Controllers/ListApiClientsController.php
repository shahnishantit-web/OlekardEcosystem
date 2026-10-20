<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Infrastructure\Http\Resources\ApiClientResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListApiClientsController
{
    public function __construct(
        private readonly ApiClientApplicationService $service,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', '20'), 100);
        $paginator = $this->service->list($perPage);

        return ApiClientResource::collection($paginator);
    }
}
