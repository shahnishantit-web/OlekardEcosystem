<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Resources;

use App\Modules\Transaction\Infrastructure\Models\TransactionStatusHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionStatusHistoryModel */
final class TransactionStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'transaction_id'  => $this->transaction_id,
            'previous_status' => $this->previous_status,
            'new_status'      => $this->new_status,
            'actor_type'      => $this->actor_type,
            'reason'          => $this->reason,
            'occurred_at'     => $this->occurred_at->toIso8601String(),
        ];
    }
}
