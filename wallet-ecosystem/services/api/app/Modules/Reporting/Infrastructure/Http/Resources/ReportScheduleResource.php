<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ReportScheduleModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportScheduleResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ReportScheduleModel $schedule */
        $schedule = $this->resource;

        return [
            'id'                          => $schedule->id,
            'name'                        => $schedule->name,
            'report_type'                 => $schedule->report_type,
            'filters'                     => $schedule->filters,
            'cadence'                     => $schedule->cadence,
            'cadence_time'                => $schedule->cadence_time,
            'cadence_day_of_week'         => $schedule->cadence_day_of_week,
            'cadence_day_of_month'        => $schedule->cadence_day_of_month,
            'status'                      => $schedule->status,
            'recipients'                  => $schedule->recipients,
            'email_notification_enabled'  => $schedule->email_notification_enabled,
            'next_run_at'                 => $schedule->next_run_at?->toIso8601String(),
            'last_run_at'                 => $schedule->last_run_at?->toIso8601String(),
            'created_at'                  => $schedule->created_at?->toIso8601String(),
        ];
    }
}
