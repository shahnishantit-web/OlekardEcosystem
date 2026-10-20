<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DisputeEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'dispute_id'    => $this->dispute_id,
            'uploaded_by_id' => $this->uploaded_by,
            'file_type'     => $this->file_type instanceof \BackedEnum ? $this->file_type->value : $this->file_type,
            'file_key'      => $this->file_key,
            'original_name' => $this->original_name,
            'label'         => $this->label,
            'size_bytes'    => $this->size_bytes,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
