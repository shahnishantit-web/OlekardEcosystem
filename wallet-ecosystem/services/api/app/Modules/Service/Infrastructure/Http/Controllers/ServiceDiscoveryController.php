<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServiceDiscoveryService;
use App\Modules\Service\Infrastructure\Http\Resources\ServiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ServiceDiscoveryController extends Controller
{
    public function __construct(private readonly ServiceDiscoveryService $discovery) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->discovery->list(
            filters: $request->only(['category', 'search']),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return ServiceResource::collection($paginator);
    }

    public function show(string $id): ServiceResource
    {
        return new ServiceResource($this->discovery->show($id));
    }
}
