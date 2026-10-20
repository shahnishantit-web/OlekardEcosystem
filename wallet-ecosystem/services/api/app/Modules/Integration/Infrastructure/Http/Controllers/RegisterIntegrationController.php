<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\DTOs\CreateIntegrationData;
use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Infrastructure\Http\Requests\RegisterIntegrationRequest;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class RegisterIntegrationController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(RegisterIntegrationRequest $request): JsonResponse
    {
        $validated     = $request->validated();
        $actorUserId   = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        $integration = $this->service->register(
            new CreateIntegrationData(
                providerType:         IntegrationProviderType::from($validated['provider_type']),
                providerSlug:         $validated['provider_slug'],
                displayName:          $validated['display_name'],
                credentialsReference: $validated['credentials_reference'],
                isDefault:            (bool) ($validated['is_default'] ?? false),
                endpointOverride:     $validated['endpoint_override'] ?? null,
                configuration:        $validated['configuration'] ?? null,
            ),
            $actorUserId,
            $correlationId,
        );

        return (new IntegrationResource($integration))
            ->response()
            ->setStatusCode(201);
    }
}
