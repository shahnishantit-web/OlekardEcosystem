<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Resources;

use App\Modules\Wallet\Infrastructure\Models\WalletLimitModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WalletLimitModel $model */
        $model = $this->resource;

        return [
            'id'            => $model->id,
            'wallet_id'     => $model->wallet_id,
            'currency_code' => $model->currency_code,
            'limit_type'    => $model->limit_type->value,
            'limit_value'   => $model->limit_value,
            'is_tbd'        => $model->is_tbd,
            'created_at'    => $model->created_at?->toIso8601String(),
            'updated_at'    => $model->updated_at?->toIso8601String(),
        ];
    }
}
