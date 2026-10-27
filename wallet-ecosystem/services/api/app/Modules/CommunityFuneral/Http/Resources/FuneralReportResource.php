<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FuneralReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;

        return [
            'collection_id' => $data['collection_id'] ?? null,
            'confirmed_gross_minor' => (int) ($data['confirmed_gross_minor'] ?? 0),
            'fee_total_minor' => (int) ($data['fee_total_minor'] ?? 0),
            'confirmed_refunds_minor' => (int) ($data['confirmed_refunds_minor'] ?? 0),
            'net_support_minor' => (int) ($data['net_support_minor'] ?? 0),
            'late_review_gross_minor' => (int) ($data['late_review_gross_minor'] ?? 0),
            'unresolved_liability_count' => (int) ($data['unresolved_liability_count'] ?? 0),
            'pending_payment_count' => (int) ($data['pending_payment_count'] ?? 0),
            'pending_refund_count' => (int) ($data['pending_refund_count'] ?? 0),
            'late_review_count' => (int) ($data['late_review_count'] ?? 0),
            'payout_liability_open' => (int) ($data['payout_liability_open'] ?? 0),
            'settlement_status' => $data['settlement_status'] ?? 'unresolved',
            'payout_status' => $data['payout_status'] ?? 'blocked',
        ];
    }
}
