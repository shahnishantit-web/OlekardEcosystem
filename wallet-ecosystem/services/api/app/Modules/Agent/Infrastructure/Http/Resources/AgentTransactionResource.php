<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AgentTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->resource->id,
            'type'          => $this->resource->type,
            'status'        => $this->resource->status,
            'amount'        => $this->resource->amount,
            'currency_code' => $this->resource->currency_code,
            'direction'     => $this->resource->direction,
            'reference'     => $this->resource->reference,
            'created_at'    => $this->resource->created_at,
        ];
    }
}
