<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\DisputeCaseEventModel;
use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T034: Admin dispute case detail — includes event timeline and hold info.
 */
final class DisputeCaseDetailResource extends JsonResource
{
    /** @var PaymentDisputeCaseModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $hold = $this->resource->hold;

        return [
            'id'                          => $this->resource->id,
            'payment_attempt_id'          => $this->resource->payment_attempt_id,
            'payment_intent_id'           => $this->resource->payment_intent_id,
            'case_type'                   => $this->resource->case_type->value,
            'status'                      => $this->resource->status->value,

            'provider_code'               => $this->resource->provider_code,
            'provider_dispute_reference'  => $this->resource->provider_dispute_reference,
            'provider_decision_reference' => $this->resource->provider_decision_reference,

            'disputed_amount_minor'       => $this->resource->disputed_amount_minor,
            'currency_code'               => $this->resource->currency_code,
            'reason_code'                 => $this->resource->reason_code,
            'net_chargeback_minor'        => $this->resource->net_chargeback_minor,
            'chargeback_fee_minor'        => $this->resource->chargeback_fee_minor,

            'response_deadline'           => $this->resource->response_deadline?->toIso8601String(),
            'requires_finance_review'     => $this->resource->requires_finance_review,
            'finance_review_acknowledged_at' => $this->resource->finance_review_acknowledged_at?->toIso8601String(),
            'finance_review_acknowledged_by' => $this->resource->finance_review_acknowledged_by,

            'entitlement_action'          => $this->resource->entitlement_action?->value,
            'entitlement_evidence_ref'    => $this->resource->entitlement_evidence_ref,
            'accounting_status'           => $this->resource->accounting_status?->value,
            'chargeback_journal_id'       => $this->resource->chargeback_journal_id,
            'fee_journal_id'              => $this->resource->fee_journal_id,

            'hold' => $hold ? [
                'id'                      => $hold->id,
                'hold_amount_minor'       => $hold->hold_amount_minor,
                'settled_funds_receivable' => $hold->settled_funds_receivable,
                'authorised_at'           => $hold->authorised_at?->toIso8601String(),
                'released_at'             => $hold->released_at?->toIso8601String(),
                'authorised_by'           => $hold->authorised_by,
            ] : null,

            'events' => $this->resource->events->map(fn(DisputeCaseEventModel $ev) => [
                'event_type'  => $ev->event_type,
                'actor_id'    => $ev->actor_id,
                'occurred_at' => $ev->occurred_at?->toIso8601String(),
                'payload'     => $ev->payload,
            ])->values()->all(),

            'opened_at'  => $this->resource->opened_at?->toIso8601String(),
            'closed_at'  => $this->resource->closed_at?->toIso8601String(),
        ];
    }
}
