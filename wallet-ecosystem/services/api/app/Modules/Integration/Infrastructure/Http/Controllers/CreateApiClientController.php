<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\DTOs\CreateApiClientData;
use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Infrastructure\Http\Requests\CreateApiClientRequest;
use App\Modules\Integration\Infrastructure\Http\Resources\ApiClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class CreateApiClientController
{
    public function __construct(
        private readonly ApiClientApplicationService $service,
    ) {}

    public function __invoke(CreateApiClientRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actorUserId = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        $result = $this->service->create(
            new CreateApiClientData(
                name: $validated['name'],
                permissions: $validated['permissions'] ?? [],
                rateLimitPerMinute: (int) ($validated['rate_limit_per_minute'] ?? 60),
            ),
            $actorUserId,
            $correlationId,
        );

        return (new ApiClientResource($result['client'], true))
            ->additional(['raw_secret' => $result['raw_secret']])
            ->response()
            ->setStatusCode(201)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
