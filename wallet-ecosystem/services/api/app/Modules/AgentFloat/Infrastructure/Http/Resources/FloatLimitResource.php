<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Resources;

use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FloatLimitModel
 */
final class FloatLimitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        /** @var FloatLimitModel $limits */
        $limits = $this->resource;

        return [
            'agent_id'              => $limits->agent_id,
            'currency_code'         => $limits->currency_code,
            'max_balance'           => $limits->max_balance_is_tbd ? null : $limits->max_balance,
            'per_transaction_limit' => $limits->per_tx_is_tbd ? null : $limits->per_transaction_limit,
            'daily_volume_limit'    => $limits->daily_vol_is_tbd ? null : $limits->daily_volume_limit,
            'low_float_threshold'   => $limits->low_threshold_is_tbd ? null : $limits->low_float_threshold,
            'effective_from'        => $limits->effective_from?->toISOString(),
            'updated_by'            => $limits->updated_by,
            'updated_at'            => $limits->updated_at?->toISOString(),
        ];
    }
}
