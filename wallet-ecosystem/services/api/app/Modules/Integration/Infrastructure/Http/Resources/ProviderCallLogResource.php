<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Resources;

use App\Modules\Integration\Infrastructure\Models\ProviderCallLogModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProviderCallLogResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ProviderCallLogModel $log */
        $log = $this->resource;

        return [
            'id'                 => $log->id,
            'integration_id'     => $log->integration_id,
            'correlation_id'     => $log->correlation_id,
            'provider_reference' => $log->provider_reference,
            'request_summary'    => $log->request_summary,
            'response_code'      => $log->response_code,
            'outcome'            => $log->outcome->value,
            'retry_eligible'     => $log->retry_eligible,
            'elapsed_ms'         => $log->elapsed_ms,
            'created_at'         => $log->created_at?->toIso8601String(),
        ];
    }
}
