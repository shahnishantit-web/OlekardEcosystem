<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T035: US7 — Admin refund detail view.
 *
 * Includes all provider, ledger, and reconciliation references
 * that are hidden from the consumer-facing API.
 */
final class AdminRefundDetailResource extends JsonResource
{
    /** @var PaymentRefundModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                       => $this->resource->id,
            'payment_intent_id'        => $this->resource->payment_intent_id,
            'payment_attempt_id'       => $this->resource->payment_attempt_id,
            'amount_minor'             => $this->resource->amount_minor,
            'status'                   => $this->resource->status->value,
            'provider_code'            => $this->resource->provider_code ?? null,

            // Approval / rejection
            'approval_required'        => $this->resource->approval_required,
            'requested_by'             => $this->resource->requested_by,
            'approved_by_actor_id'     => $this->resource->approved_by_actor_id,
            'approved_at'              => $this->resource->approved_at?->toIso8601String(),
            'rejected_by_actor_id'     => $this->resource->rejected_by_actor_id,
            'rejected_at'              => $this->resource->rejected_at?->toIso8601String(),
            'rejection_reason'         => $this->resource->rejection_reason,

            // Provider / bank lifecycle
            'provider_submitted_ref'   => $this->resource->provider_submitted_ref,
            'submitted_at'             => $this->resource->submitted_at?->toIso8601String(),
            'pending_at'               => $this->resource->pending_at?->toIso8601String(),
            'unknown_at'               => $this->resource->unknown_at?->toIso8601String(),
            'query_attempts'           => $this->resource->query_attempts,

            // Accounting
            'refund_accounting_status'    => $this->resource->refund_accounting_status,
            'refund_ledger_journal_id'    => $this->resource->refund_ledger_journal_id,

            'reason'                   => $this->resource->reason,
            'requested_at'             => $this->resource->requested_at?->toIso8601String(),
        ];
    }
}
