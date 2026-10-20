<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\DTOs\UpdateIntegrationData;
use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Domain\Exceptions\IntegrationNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Requests\UpdateIntegrationRequest;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateIntegrationController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(UpdateIntegrationRequest $request, string $id): IntegrationResource
    {
        $validated     = $request->validated();
        $actorUserId   = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        try {
            $integration = $this->service->update($id, new UpdateIntegrationData(
                displayName:          $validated['display_name'] ?? null,
                credentialsReference: $validated['credentials_reference'] ?? null,
                endpointOverride:     $validated['endpoint_override'] ?? null,
                configuration:        $validated['configuration'] ?? null,
            ), $actorUserId, $correlationId);
        } catch (IntegrationNotFoundException) {
            throw new NotFoundHttpException('Integration not found.');
        }

        return new IntegrationResource($integration);
    }
}
