<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Domain\Exceptions\ApiClientNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Requests\UpdateApiClientStatusRequest;
use App\Modules\Integration\Infrastructure\Http\Resources\ApiClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateApiClientStatusController
{
    public function __construct(
        private readonly ApiClientApplicationService $service,
    ) {}

    public function __invoke(UpdateApiClientStatusRequest $request, string $id): ApiClientResource
    {
        $actorUserId   = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());

        try {
            $client = $this->service->updateStatus(
                $id,
                ApiClientStatus::from($request->validated()['status']),
                $actorUserId,
                $correlationId,
            );
        } catch (ApiClientNotFoundException) {
            throw new NotFoundHttpException('API client not found.');
        }

        return new ApiClientResource($client);
    }
}
