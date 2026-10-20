<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Domain\Exceptions\IntegrationNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SetDefaultIntegrationController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $id): IntegrationResource
    {
        $actorUserId   = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        try {
            $integration = $this->service->setDefault($id, $actorUserId, $correlationId);
        } catch (IntegrationNotFoundException) {
            throw new NotFoundHttpException('Integration not found.');
        }

        return new IntegrationResource($integration);
    }
}
