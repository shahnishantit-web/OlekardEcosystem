<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\IntegrationApplicationService;
use App\Modules\Integration\Domain\Exceptions\IntegrationNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Resources\IntegrationResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetIntegrationController
{
    public function __construct(
        private readonly IntegrationApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $id): IntegrationResource
    {
        try {
            $integration = $this->service->get($id);
        } catch (IntegrationNotFoundException) {
            throw new NotFoundHttpException('Integration not found.');
        }

        return new IntegrationResource($integration);
    }
}
