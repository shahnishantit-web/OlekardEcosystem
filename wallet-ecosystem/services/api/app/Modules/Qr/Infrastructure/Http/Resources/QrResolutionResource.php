<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Resources;

use App\Modules\Qr\Domain\DTO\QrResolutionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrResolutionResult
 */
final class QrResolutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QrResolutionResult $result */
        $result = $this->resource;

        return [
            'qr_code_id'           => $result->qrCodeId,
            'type'                 => $result->type->value,
            'merchant_id'          => $result->merchantId,
            'merchant_display_name' => $result->merchantDisplayName,
            'currency'             => $result->currency,
            'amount'               => $result->amount,
            'amount_required'      => $result->amountRequired,
            'expires_at'           => $result->expiresAt?->toIso8601String(),
        ];
    }
}
