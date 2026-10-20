<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Resources;

use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrCodeModel
 */
final class QrCodeResource extends JsonResource
{
    private bool $includePayload;

    public function __construct(mixed $resource, bool $includePayload = false)
    {
        parent::__construct($resource);
        $this->includePayload = $includePayload;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id'           => $this->id,
            'merchant_id'  => $this->merchant_id,
            'type'         => $this->type->value,
            'label'        => $this->label,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'merchant_ref' => $this->merchant_ref,
            'status'       => $this->status->value,
            'expires_at'   => $this->expires_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];

        if ($this->includePayload) {
            $data['payload'] = $this->payload;
        }

        return $data;
    }
}
