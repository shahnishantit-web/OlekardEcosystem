<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use App\Modules\Integration\Domain\Exceptions\IntegrationNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Requests\UpdateIntegrationStatusRequest;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateIntegrationStatusController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(UpdateIntegrationStatusRequest $request, string $id): IntegrationResource
    {
        $actorUserId   = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        try {
            $integration = $this->service->updateStatus(
                $id,
                IntegrationStatus::from($request->validated()['status']),
                $actorUserId,
                $correlationId,
            );
        } catch (IntegrationNotFoundException) {
            throw new NotFoundHttpException('Integration not found.');
        }

        return new IntegrationResource($integration);
    }
}
