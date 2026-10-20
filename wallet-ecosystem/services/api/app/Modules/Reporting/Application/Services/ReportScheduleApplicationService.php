<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Domain\Enums\ReportType;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use App\Modules\Reporting\Infrastructure\Models\ReportScheduleModel;
use App\Modules\Reporting\Infrastructure\Models\ScheduleRunModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportScheduleApplicationService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {}

    public function create(string $userId, array $data, string $correlationId): ReportScheduleModel
    {
        $this->validateScheduleData($data);

        $schedule = ReportScheduleModel::create([
            'owner_user_id'               => $userId,
            'name'                        => $data['name'],
            'report_type'                 => $data['report_type'],
            'filters'                     => $data['filters'] ?? [],
            'cadence'                     => $data['cadence'],
            'cadence_time'                => $data['cadence_time'],
            'cadence_day_of_week'         => $data['cadence_day_of_week'] ?? null,
            'cadence_day_of_month'        => $data['cadence_day_of_month'] ?? null,
            'status'                      => 'active',
            'recipients'                  => $data['recipients'] ?? [],
            'email_notification_enabled'  => $data['email_notification_enabled'] ?? false,
            'next_run_at'                 => null,
        ]);

        $schedule->next_run_at = $schedule->computeNextRunAt(CarbonImmutable::now());
        $schedule->save();

        $this->audit->record(new AuditEventData(
            action:        'reporting.schedule.created',
            actorType:     'user',
            resourceType:  'report_schedules',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $schedule->id,
            afterState:    ['report_type' => $schedule->report_type, 'cadence' => $schedule->cadence],
        ));

        return $schedule;
    }

    public function update(string $scheduleId, string $userId, array $data, string $correlationId): ReportScheduleModel
    {
        $schedule = $this->findForOwner($scheduleId, $userId);

        $cadenceFields  = ['cadence', 'cadence_time', 'cadence_day_of_week', 'cadence_day_of_month'];
        $cadenceChanged = count(array_intersect($cadenceFields, array_keys($data))) > 0;

        $schedule->fill(array_filter($data, fn ($k) => in_array($k, [
            'name', 'filters', 'cadence', 'cadence_time', 'cadence_day_of_week',
            'cadence_day_of_month', 'recipients', 'email_notification_enabled',
        ], true), ARRAY_FILTER_USE_KEY));

        if ($cadenceChanged) {
            $schedule->next_run_at = $schedule->computeNextRunAt(CarbonImmutable::now());
        }

        $schedule->save();

        $this->audit->record(new AuditEventData(
            action:        'reporting.schedule.updated',
            actorType:     'user',
            resourceType:  'report_schedules',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $schedule->id,
        ));

        return $schedule;
    }

    public function deactivate(string $scheduleId, string $userId, string $correlationId): void
    {
        $schedule = $this->findForOwner($scheduleId, $userId);
        $schedule->status = 'inactive';
        $schedule->save();

        $this->audit->record(new AuditEventData(
            action:        'reporting.schedule.deactivated',
            actorType:     'user',
            resourceType:  'report_schedules',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $schedule->id,
        ));
    }

    public function getSchedule(string $scheduleId, string $userId): ReportScheduleModel
    {
        return $this->findForOwner($scheduleId, $userId);
    }

    public function listSchedules(string $userId, int $perPage = 20): LengthAwarePaginator
    {
        return ReportScheduleModel::where('owner_user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function listRuns(string $scheduleId, string $userId, int $perPage = 20): LengthAwarePaginator
    {
        $schedule = $this->findForOwner($scheduleId, $userId);

        return ScheduleRunModel::where('schedule_id', $schedule->id)
            ->orderByDesc('triggered_at')
            ->paginate($perPage);
    }

    private function findForOwner(string $scheduleId, string $userId): ReportScheduleModel
    {
        $schedule = ReportScheduleModel::where('id', $scheduleId)
            ->where('owner_user_id', $userId)
            ->first();

        if ($schedule === null) {
            throw new NotFoundHttpException('Schedule not found.');
        }

        return $schedule;
    }

    private function validateScheduleData(array $data): void
    {
        $reportType = $data['report_type'] ?? null;
        if ($reportType !== null) {
            $active = ReportDefinitionModel::active()->where('type', $reportType)->exists();
            if (!$active) {
                throw ValidationException::withMessages(['report_type' => 'Report type is not available.']);
            }
        }
    }
}
