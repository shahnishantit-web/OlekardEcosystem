<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExceptionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'item_id'         => $this->item_id,
            'batch_id'        => $this->batch_id,
            'exception_type'  => $this->exception_type instanceof \BackedEnum ? $this->exception_type->value : $this->exception_type,
            'status'          => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'opened_by'       => $this->opened_by,
            'assigned_to'     => $this->assigned_to,
            'resolved_by'     => $this->resolved_by,
            'resolution'      => $this->resolution,
            'resolution_type' => $this->resolution_type,
            'opened_at'       => $this->opened_at?->toIso8601String(),
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'item'            => $this->whenLoaded('item', fn () => new ItemSummaryResource($this->item)),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
