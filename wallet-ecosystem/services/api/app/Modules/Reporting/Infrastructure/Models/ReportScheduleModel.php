<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $owner_user_id
 * @property string $name
 * @property string $report_type
 * @property array<string, mixed> $filters
 * @property string $cadence
 * @property string $cadence_time
 * @property int|null $cadence_day_of_week
 * @property int|null $cadence_day_of_month
 * @property string $status
 * @property array<string, mixed> $recipients
 * @property bool $email_notification_enabled
 * @property \Carbon\CarbonInterface|\Illuminate\Support\Carbon|null $next_run_at
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ReportScheduleModel extends Model
{
    protected $connection  = 'platform';
    protected $table       = 'reporting.report_schedules';
    protected $keyType     = 'string';
    public    $incrementing = false;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'owner_user_id', 'name', 'report_type', 'filters', 'cadence',
        'cadence_time', 'cadence_day_of_week', 'cadence_day_of_month',
        'status', 'recipients', 'email_notification_enabled',
        'next_run_at', 'last_run_at',
    ];

    protected $casts = [
        'filters'                     => 'array',
        'recipients'                  => 'array',
        'email_notification_enabled'  => 'boolean',
        'next_run_at'                 => 'datetime',
        'last_run_at'                 => 'datetime',
        'created_at'                  => 'datetime',
        'updated_at'                  => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            $model->id ??= (string) Uuid::uuid7();
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRunModel::class, 'schedule_id');
    }

    public function computeNextRunAt(CarbonImmutable $from): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->cadence_time));

        return match ($this->cadence) {
            'daily'   => $this->nextDailyRun($from, $hour, $minute),
            'weekly'  => $this->nextWeeklyRun($from, $hour, $minute),
            'monthly' => $this->nextMonthlyRun($from, $hour, $minute),
            default   => $from->addDay()->setTime($hour, $minute),
        };
    }

    private function nextDailyRun(CarbonImmutable $from, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $from->setTime($hour, $minute);
        return $candidate->greaterThan($from) ? $candidate : $candidate->addDay();
    }

    private function nextWeeklyRun(CarbonImmutable $from, int $hour, int $minute): CarbonImmutable
    {
        $targetDow = (int) ($this->cadence_day_of_week ?? 1);
        $candidate = $from->next($targetDow)->setTime($hour, $minute);
        if ($candidate->dayOfWeek === $from->dayOfWeek && $candidate->greaterThan($from)) {
            return $from->setTime($hour, $minute);
        }
        return $candidate;
    }

    private function nextMonthlyRun(CarbonImmutable $from, int $hour, int $minute): CarbonImmutable
    {
        $dom       = min((int) ($this->cadence_day_of_month ?? 1), 28);
        $candidate = $from->day($dom)->setTime($hour, $minute);
        if ($candidate->greaterThan($from)) {
            return $candidate;
        }
        return $from->addMonth()->day($dom)->setTime($hour, $minute);
    }
}
