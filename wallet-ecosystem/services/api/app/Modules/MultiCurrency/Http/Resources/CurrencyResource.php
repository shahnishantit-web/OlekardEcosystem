<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Resources;

use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CurrencyModel
 */
final class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'           => $this->code,
            'name'           => $this->name,
            'symbol'         => $this->symbol,
            'decimal_places' => $this->decimal_places,
            'is_active'      => $this->is_active,
        ];
    }
}
