<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ScheduleRunModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ScheduleRunResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ScheduleRunModel $run */
        $run = $this->resource;

        return [
            'id'             => $run->id,
            'schedule_id'    => $run->schedule_id,
            'job_id'         => $run->job_id,
            'outcome'        => $run->outcome,
            'failure_reason' => $run->failure_reason,
            'triggered_at'   => $run->triggered_at?->toIso8601String(),
        ];
    }
}
