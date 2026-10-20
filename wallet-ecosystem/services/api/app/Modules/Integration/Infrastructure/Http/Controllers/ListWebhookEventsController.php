<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Infrastructure\Http\Resources\WebhookEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListWebhookEventsController
{
    public function __construct(
        private readonly WebhookEventRepositoryInterface $repository,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', '20'), 100);

        $filters = array_filter([
            'status'        => $request->query('status'),
            'provider_slug' => $request->query('provider_slug'),
            'provider_type' => $request->query('provider_type'),
        ]);

        return WebhookEventResource::collection($this->repository->paginate($filters, $perPage));
    }
}
