<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evidence = null;
        if ($this->relationLoaded('evidence')) {
            $evidence = DisputeEvidenceResource::collection($this->evidence)->resolve();
        }

        return [
            'id'                          => $this->id,
            'dispute_number'              => $this->dispute_number,
            'ticket_id'                   => $this->ticket_id,
            'transaction_id'              => $this->transaction_id,
            'reporter_id'                 => $this->reporter_id,
            'status'                      => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'reason_code'                 => $this->reason_code instanceof \BackedEnum ? $this->reason_code->value : $this->reason_code,
            'reason_description'          => $this->reason_description,
            'resolution_note'             => $this->resolution_note,
            'rejection_reason'            => $this->rejection_reason,
            'escalated'                   => $this->escalated,
            'escalated_at'                => $this->escalated_at?->toIso8601String(),
            'refund_pending_confirmation' => $this->refund_pending_confirmation,
            'refund_initiator_id'         => $this->refund_initiator_id,
            'refunded_amount_minor'       => $this->refunded_amount_minor,
            'refund_currency'             => $this->refund_currency,
            'investigator_id'             => $this->investigator_id,
            'investigation_started_at'    => $this->investigation_started_at?->toIso8601String(),
            'approved_at'                 => $this->approved_at?->toIso8601String(),
            'rejected_at'                 => $this->rejected_at?->toIso8601String(),
            'dispute_refunded_at'         => $this->dispute_refunded_at?->toIso8601String(),
            'evidence'                    => $evidence,
            'created_at'                  => $this->created_at?->toIso8601String(),
            'updated_at'                  => $this->updated_at?->toIso8601String(),
        ];
    }
}
