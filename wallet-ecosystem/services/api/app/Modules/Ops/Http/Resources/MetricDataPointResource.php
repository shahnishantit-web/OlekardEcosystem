<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\ServiceMetricModel;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceMetricModel */
final class MetricDataPointResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'service_name' => $this->service_name,
            'metric_name'  => $this->metric_name,
            'value'        => $this->value,
            'unit'         => $this->unit,
            'granularity'  => $this->granularity,
            'recorded_at'  => $this->recorded_at?->toIso8601String(),
        ];
    }
}
