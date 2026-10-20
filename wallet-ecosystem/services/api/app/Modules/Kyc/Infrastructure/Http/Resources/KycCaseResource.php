<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Http\Resources;

use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class KycCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KycCaseModel $this */
        return [
            'case_id'          => $this->id,
            'status'           => $this->status->value,
            'attempt_number'   => $this->attempt_number,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            'verified_at'      => $this->verified_at?->toIso8601String(),
            'expires_at'       => $this->expires_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'info_request_note' => $this->info_request_note,
            'created_at'       => $this->created_at?->toIso8601String(),
            // PII fields (personal_info, documents) are NOT included here — only on explicit show endpoints
        ];
    }
}
