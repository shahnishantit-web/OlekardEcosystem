<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Resources;

use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ApiClientResource extends JsonResource
{
    private bool $showSecret;

    public function __construct(mixed $resource, bool $showSecret = false)
    {
        parent::__construct($resource);
        $this->showSecret = $showSecret;
    }

    public function toArray($request): array
    {
        /** @var ApiClientModel $client */
        $client = $this->resource;

        $data = [
            'id'                    => $client->id,
            'name'                  => $client->name,
            'api_key'               => $client->api_key,
            'status'                => $client->status->value,
            'permissions'           => $client->permissions,
            'rate_limit_per_minute' => $client->rate_limit_per_minute,
            'last_used_at'          => isset($client->last_used_at)
                ? \Carbon\Carbon::parse($client->last_used_at)->toIso8601String()
                : null,
            'created_at'            => $client->created_at?->toIso8601String(),
            'updated_at'            => $client->updated_at?->toIso8601String(),
        ];

        if ($this->showSecret) {
            $data['raw_secret'] = $this->additional['raw_secret'] ?? null;
        }

        return $data;
    }
}
