<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentModel */
final class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'application_id' => $this->application_id,
            'user_id'        => $this->user_id,
            'agent_type'     => $this->agent_type->value,
            'status'         => $this->status->value,
            'approved_at'    => $this->approved_at?->toIso8601String(),
            'suspended_at'   => $this->suspended_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
