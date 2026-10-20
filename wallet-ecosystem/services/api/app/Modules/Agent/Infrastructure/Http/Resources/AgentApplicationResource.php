<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use App\Modules\Agent\Infrastructure\Models\AgentApplicationModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AgentApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AgentApplicationModel $this */
        return [
            'id'               => $this->id,
            'agent_type'       => $this->agent_type->value,
            'status'           => $this->status->value,
            'kyc_result'       => $this->kyc_result,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),
            'approved_at'      => $this->approved_at?->toIso8601String(),
            'correlation_id'   => $this->correlation_id,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
