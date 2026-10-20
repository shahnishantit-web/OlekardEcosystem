<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Resources;

use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeadLetterItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel $item */
        $item = $this->resource;

        $data = [
            'id'             => $item->id,
            'operation_type' => $item->operation_type,
            'source_id'      => $item->source_id,
            'payload'        => PiiMasker::maskFields($item->payload ?? []),
            'failure_reason' => $item->failure_reason,
            'retry_count'    => $item->retry_count,
            'status'         => $item->status,
            'replayed_at'    => $item->replayed_at?->toIso8601String(),
            'replayed_by'    => $item->replayed_by,
            'dismissed_at'   => $item->dismissed_at?->toIso8601String(),
            'dismissed_by'   => $item->dismissed_by,
            'dismissal_reason' => $item->dismissal_reason,
            'correlation_id' => $item->correlation_id,
            'created_at'     => $item->created_at?->toIso8601String(),
            'expires_at'     => $item->expires_at?->toIso8601String(),
        ];

        // Include retry_history only on single-item show (not list responses)
        if ($request->route('id') !== null) {
            $data['retry_history'] = $item->retry_history ?? [];
        }

        return $data;
    }
}
