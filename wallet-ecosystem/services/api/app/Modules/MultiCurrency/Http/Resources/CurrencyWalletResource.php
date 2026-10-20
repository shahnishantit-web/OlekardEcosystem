<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Resources;

use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyModel;
use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyWalletModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CurrencyWalletModel
 */
final class CurrencyWalletResource extends JsonResource
{
    public function __construct(
        CurrencyWalletModel $resource,
        private readonly int $balanceMinor = 0,
        private readonly ?CurrencyModel $currency = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $decimalPlaces = $this->currency?->decimal_places ?? 2;
        $balance       = number_format($this->balanceMinor / (10 ** $decimalPlaces), $decimalPlaces, '.', '');

        return [
            'id'            => $this->id,
            'currency_code' => $this->currency_code,
            'currency_name' => $this->currency?->name ?? $this->currency_code,
            'currency_symbol' => $this->currency?->symbol ?? '',
            'balance'       => $balance,
            'is_active'     => $this->is_active,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
