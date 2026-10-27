<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T030: US6 — Consumer-facing refund item.
 *
 * status_label maps internal states to consumer-friendly strings.
 * funds_returned is authoritative only for confirmed/succeeded states.
 */
final class CustomerRefundListResource extends JsonResource
{
    /** @var PaymentRefundModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $status = $this->resource->status;

        return [
            'id'           => $this->resource->id,
            'amount_minor' => $this->resource->amount_minor,
            'currency_code' => $this->resource->currency_code ?? null,
            'status'       => $status->value,
            'status_label' => $this->consumerStatusLabel($status),
            'funds_returned' => $status->fundsReturned(),
            'requested_at' => $this->resource->requested_at?->toIso8601String(),
        ];
    }

    private function consumerStatusLabel(PaymentRefundStatus $status): string
    {
        return match ($status) {
            PaymentRefundStatus::Confirmed,
            PaymentRefundStatus::Succeeded  => 'Funds returned',
            PaymentRefundStatus::Pending    => 'Being processed',
            PaymentRefundStatus::Unknown    => 'Under review',
            PaymentRefundStatus::DefinitiveFailure,
            PaymentRefundStatus::Failed     => 'Refund declined',
            PaymentRefundStatus::Cancelled,
            PaymentRefundStatus::Rejected   => 'Refund cancelled',
            default                         => 'Processing',
        };
    }
}
