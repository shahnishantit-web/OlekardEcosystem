<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Domain\Exceptions\ApiClientNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Resources\ApiClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetApiClientController
{
    public function __construct(
        private readonly ApiClientApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $id): ApiClientResource
    {
        try {
            $client = $this->service->get($id);
        } catch (ApiClientNotFoundException) {
            throw new NotFoundHttpException('API client not found.');
        }

        return new ApiClientResource($client);
    }
}
