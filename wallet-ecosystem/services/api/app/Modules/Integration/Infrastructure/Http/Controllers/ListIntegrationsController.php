<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListIntegrationsController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'provider_type' => $request->query('provider_type'),
            'status'        => $request->query('status'),
        ]);

        $integrations = $this->service->list($filters);

        return IntegrationResource::collection($integrations);
    }
}
