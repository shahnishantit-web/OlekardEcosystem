<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T033: Admin dispute case list item — minimal summary without event history.
 */
final class DisputeCaseListResource extends JsonResource
{
    /** @var PaymentDisputeCaseModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                       => $this->resource->id,
            'payment_attempt_id'       => $this->resource->payment_attempt_id,
            'case_type'                => $this->resource->case_type->value,
            'status'                   => $this->resource->status->value,
            'provider_code'            => $this->resource->provider_code,
            'provider_dispute_reference' => $this->resource->provider_dispute_reference,
            'disputed_amount_minor'    => $this->resource->disputed_amount_minor,
            'currency_code'            => $this->resource->currency_code,
            'reason_code'              => $this->resource->reason_code,
            'response_deadline'        => $this->resource->response_deadline?->toIso8601String(),
            'requires_finance_review'  => $this->resource->requires_finance_review,
            'entitlement_action'       => $this->resource->entitlement_action?->value,
            'accounting_status'        => $this->resource->accounting_status?->value,
            'opened_at'                => $this->resource->opened_at?->toIso8601String(),
            'closed_at'                => $this->resource->closed_at?->toIso8601String(),
        ];
    }
}
