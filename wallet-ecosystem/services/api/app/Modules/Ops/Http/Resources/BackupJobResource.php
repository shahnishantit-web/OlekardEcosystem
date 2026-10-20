<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\BackupJobModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BackupJobModel */
final class BackupJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'idempotency_key'  => $this->idempotency_key,
            'type'             => $this->type->value,
            'status'           => $this->status->value,
            'storage_path'     => $this->storage_path,
            'size_bytes'       => $this->size_bytes,
            'error_message'    => $this->error_message,
            'started_at'       => $this->started_at?->toAtomString(),
            'completed_at'     => $this->completed_at?->toAtomString(),
            'created_at'       => $this->created_at?->toAtomString(),
        ];
    }
}
