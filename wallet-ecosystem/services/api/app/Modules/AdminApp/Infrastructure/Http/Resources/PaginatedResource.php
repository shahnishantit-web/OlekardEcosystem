<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

final class PaginatedResource extends ResourceCollection
{
    private string $resourceClass;

    public function __construct(\Illuminate\Pagination\LengthAwarePaginator $resource, string $resourceClass)
    {
        parent::__construct($resource);
        $this->resourceClass = $resourceClass;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            // @phpstan-ignore-next-line
            'data' => $this->collection->map(
                fn ($item) => (new $this->resourceClass($item))->toArray($request),
            ),
            'meta' => [
                'total'        => $this->resource->total(),
                'per_page'     => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'last_page'    => $this->resource->lastPage(),
            ],
        ];
    }
}
