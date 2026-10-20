<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Infrastructure\Http\Requests\CreateServiceRequest;
use App\Modules\Service\Infrastructure\Http\Requests\UpdateServiceRequest;
use App\Modules\Service\Infrastructure\Http\Resources\ServiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ServiceCatalogController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->catalog->list(
            filters: $request->only(['status', 'category']),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return ServiceResource::collection($paginator);
    }

    public function store(CreateServiceRequest $request): JsonResponse
    {
        $service = $this->catalog->create(
            data:          $request->validated(),
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return (new ServiceResource($service))->response()->setStatusCode(201);
    }

    public function show(string $id): ServiceResource
    {
        return new ServiceResource($this->catalog->findById($id));
    }

    public function update(UpdateServiceRequest $request, string $id): ServiceResource
    {
        $service = $this->catalog->update(
            id:            $id,
            data:          $request->validated(),
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return new ServiceResource($service);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $this->catalog->deactivate(
            id:            $id,
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return response()->json(['message' => 'Service deactivated.']);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $this->catalog->reactivate(
            id:            $id,
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return response()->json(['message' => 'Service reactivated.']);
    }
}
