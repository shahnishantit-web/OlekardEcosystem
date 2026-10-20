<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Domain\Exceptions\ApiClientNotFoundException;
use App\Modules\Integration\Infrastructure\Http\Resources\ApiClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RotateApiClientSecretController
{
    public function __construct(
        private readonly ApiClientApplicationService $service,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $actorUserId = (string) $request->attributes->get('identity.user_id');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?: Str::uuid());
        $reason = $request->input('reason');

        try {
            $result = $this->service->rotateSecret($id, $actorUserId, $correlationId, $reason);
        } catch (ApiClientNotFoundException) {
            throw new NotFoundHttpException('API client not found.');
        }

        return (new ApiClientResource($result['client'], true))
            ->additional(['raw_secret' => $result['raw_secret']])
            ->response()
            ->setStatusCode(200)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
