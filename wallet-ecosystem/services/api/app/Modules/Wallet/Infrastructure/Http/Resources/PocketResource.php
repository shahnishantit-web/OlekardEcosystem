<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Resources;

use App\Modules\Wallet\Infrastructure\Models\CurrencyModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PocketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $precision = CurrencyModel::on('wallet')
            ->find($this->currency_code)?->precision ?? null;

        return [
            'id'            => $this->id,
            'currency_code' => $this->currency_code,
            'status'        => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'precision'     => $precision,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
