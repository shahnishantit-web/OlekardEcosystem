<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T035: US7 — Admin refund list item.
 *
 * reserved_since = earliest of submitted_at, pending_at, unknown_at to show
 * how long funds have been locked.
 */
final class AdminRefundListResource extends JsonResource
{
    /** @var PaymentRefundModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $reservedSince = $this->earliestReservationTimestamp();

        return [
            'id'                   => $this->resource->id,
            'payment_intent_id'    => $this->resource->payment_intent_id,
            'payment_attempt_id'   => $this->resource->payment_attempt_id,
            'amount_minor'         => $this->resource->amount_minor,
            'status'               => $this->resource->status->value,
            'provider_code'        => $this->resource->provider_code ?? null,
            'reserved_since'       => $reservedSince?->toIso8601String(),
            'requested_at'         => $this->resource->requested_at?->toIso8601String(),
            'refund_accounting_status' => $this->resource->refund_accounting_status,
        ];
    }

    private function earliestReservationTimestamp(): ?\DateTimeInterface
    {
        $timestamps = array_filter([
            $this->resource->submitted_at,
            $this->resource->pending_at,
            $this->resource->unknown_at,
        ]);

        if (empty($timestamps)) {
            return null;
        }

        return min($timestamps);
    }
}
