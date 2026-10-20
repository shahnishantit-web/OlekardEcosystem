<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Resources;

use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FxRateModel
 */
final class FxRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->getAttribute('source') ?? 'manual',
            'provider' => $this->getAttribute('provider'),
            'observed_at' => $this->getAttribute('observed_at')?->toIso8601String(),
            'last_updated' => ($this->getAttribute('observed_at') ?? $this->effective_from)?->toIso8601String(),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'from_currency_code' => $this->from_currency_code,
            'to_currency_code' => $this->to_currency_code,
            'rate' => $this->rate,
            'spread' => $this->spread,
            'status' => $this->status instanceof FxRateStatus
                ? $this->status->value
                : $this->status,
            'change_request_id' => $this->change_request_id,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
