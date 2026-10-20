<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\DeploymentRollbackModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeploymentRollbackModel */
final class DeploymentRollbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'from_version'    => $this->from_version,
            'to_version'      => $this->to_version,
            'status'          => $this->status->value,
            'initiated_by'    => $this->initiated_by,
            'reason'          => $this->reason,
            'error_message'   => $this->error_message,
            'completed_at'    => $this->completed_at?->toAtomString(),
            'created_at'      => $this->created_at?->toAtomString(),
        ];
    }
}
