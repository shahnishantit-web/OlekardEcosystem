<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * T035: Consumer-facing dispute summary — no provider references, no hold amounts,
 * no internal accounting status. Consumer label used instead of raw status.
 */
final class CustomerDisputeSummaryResource extends JsonResource
{
    /** @var PaymentDisputeCaseModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                       => $this->resource->id,
            'case_type'                => $this->resource->case_type->value,
            'status_label'             => $this->resource->status->consumerLabel(),
            'disputed_amount_minor'    => $this->resource->disputed_amount_minor,
            'currency_code'            => $this->resource->currency_code,
            'response_deadline'        => $this->resource->response_deadline?->toIso8601String(),
            'opened_at'                => $this->resource->opened_at?->toIso8601String(),
            'closed_at'                => $this->resource->closed_at?->toIso8601String(),
        ];
    }
}
