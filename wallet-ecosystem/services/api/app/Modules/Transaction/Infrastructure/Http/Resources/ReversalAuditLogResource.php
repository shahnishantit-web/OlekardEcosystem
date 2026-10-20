<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Resources;

use App\Modules\Transaction\Infrastructure\Models\ReversalAuditLogModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReversalAuditLogModel */
final class ReversalAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reversal_id'    => $this->reversal_id,
            'transaction_id' => $this->transaction_id,
            'action'         => $this->action,
            'actor_type'     => $this->actor_type,
            'metadata'       => $this->metadata,
            'occurred_at'    => $this->occurred_at->toIso8601String(),
        ];
    }
}
