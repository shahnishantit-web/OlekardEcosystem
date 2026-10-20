<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use App\Modules\Agent\Infrastructure\Models\AgentDocumentModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentDocumentModel */
final class AgentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'application_id'   => $this->application_id,
            'document_type'    => $this->document_type->value,
            'status'           => $this->status->value,
            'mime_type'        => $this->mime_type,
            'size_bytes'       => $this->size_bytes,
            'rejection_reason' => $this->rejection_reason,
            'uploaded_at'      => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
