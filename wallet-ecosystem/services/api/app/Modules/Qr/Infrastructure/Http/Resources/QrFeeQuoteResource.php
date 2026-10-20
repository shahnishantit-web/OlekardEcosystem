<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Resources;

use App\Modules\Qr\Domain\DTO\QrFeeQuoteResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrFeeQuoteResult
 */
final class QrFeeQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QrFeeQuoteResult $result */
        $result = $this->resource;

        return [
            'amount'   => $result->amount,
            'fee'      => $result->fee,
            'total'    => $result->total,
            'currency' => $result->currency,
        ];
    }
}
