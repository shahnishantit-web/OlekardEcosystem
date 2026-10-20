<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Resources;

use App\Modules\Wallet\Infrastructure\Models\WalletStatusHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WalletStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WalletStatusHistoryModel $model */
        $model = $this->resource;

        return [
            'id'          => $model->id,
            'wallet_id'   => $model->wallet_id,
            'from_status' => $model->from_status,
            'to_status'   => $model->to_status->value,
            'reason'      => $model->reason,
            'actor_id'    => $model->actor_id,
            'created_at'  => $model->created_at?->toIso8601String(),
        ];
    }
}
