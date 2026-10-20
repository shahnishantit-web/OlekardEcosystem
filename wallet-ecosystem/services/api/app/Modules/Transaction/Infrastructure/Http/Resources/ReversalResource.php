<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Resources;

use App\Modules\Transaction\Infrastructure\Models\ReversalModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReversalModel */
final class ReversalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'transaction_id'          => $this->transaction_id,
            'status'                  => $this->status->value,
            'requested_by'            => $this->requested_by,
            'requested_at'            => $this->requested_at->toIso8601String(),
            'approved_by'             => $this->approved_by,
            'approved_at'             => $this->approved_at?->toIso8601String(),
            'rejected_by'             => $this->rejected_by,
            'rejected_reason'         => $this->rejected_reason,
            'executed_at'             => $this->executed_at?->toIso8601String(),
            'compensating_journal_id' => $this->compensating_journal_id,
            'created_at'              => $this->created_at->toIso8601String(),
            'updated_at'              => $this->updated_at->toIso8601String(),
        ];
    }
}
